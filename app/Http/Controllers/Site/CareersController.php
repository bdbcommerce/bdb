<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Services\TelegramNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Public "We're hiring" page: open vacancies + a form to send a CV. */
class CareersController extends Controller
{
    /** Allowed CV formats and the max size in KB. */
    public const CV_MIMES = ['pdf', 'doc', 'docx', 'rtf', 'odt'];

    public const CV_MAX_KB = 5120;

    /**
     * The CV's real type from its content (never the client's name or header), or null when not allowed.
     * Some servers detect .docx / .odt as a plain ZIP: those are accepted only when the archive holds
     * a Word document (word/document.xml) or an OpenDocument text (its "mimetype" entry).
     */
    public static function cvExtension(UploadedFile $file): ?string
    {
        // The applicant's own extension must be a CV type too (never .php, .phtml, .html…).
        $client = strtolower($file->getClientOriginalExtension());
        if (! in_array($client, self::CV_MIMES, true)) {
            return null;
        }

        $guess = strtolower((string) $file->guessExtension());
        if (in_array($guess, self::CV_MIMES, true)) {
            return $guess;
        }

        if ($guess === 'zip' && in_array($client, ['docx', 'odt'], true)) {
            $bytes = (string) @file_get_contents($file->getRealPath(), false, null, 0, self::CV_MAX_KB * 1024);
            $marker = $client === 'docx' ? 'word/document.xml' : 'mimetypeapplication/vnd.oasis.opendocument.text';
            if (str_contains($bytes, $marker)) {
                return $client;
            }
        }

        return null;
    }

    public function index(string $locale)
    {
        $vacancies = Vacancy::active()->get()
            ->filter(fn (Vacancy $v) => filled($v->getTranslation('title', $locale, false)))
            ->values();

        return view('site.careers', ['vacancies' => $vacancies]);
    }

    public function apply(Request $request, string $locale, TelegramNotifier $notifier)
    {
        // Honeypot: bots fill the hidden field; pretend success without saving.
        if (filled($request->input('website'))) {
            return redirect()->to(route('site.careers', $locale).'#apply')->with('careers_sent', true);
        }

        $data = $request->validate([
            'vacancy_id' => ['nullable', 'integer', Rule::exists('vacancies', 'id')->where('is_active', true)],
            'department' => ['nullable', Rule::in(Vacancy::DEPARTMENTS)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'email' => ['nullable', 'email:strict', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'cv' => ['required', 'file', 'max:'.self::CV_MAX_KB, function (string $attribute, $file, \Closure $fail) {
                if (! $file instanceof UploadedFile || self::cvExtension($file) === null) {
                    $fail(__('validation.mimes', ['attribute' => __('site.careers.form.cv'), 'values' => implode(', ', self::CV_MIMES)]));
                }
            }],
            'consent' => ['accepted'],
        ], [], [
            'vacancy_id' => __('site.careers.form.position'),
            'name' => __('site.careers.form.name'),
            'phone' => __('site.careers.form.phone'),
            'email' => __('site.careers.form.email'),
            'message' => __('site.careers.form.message'),
            'cv' => __('site.careers.form.cv'),
            'consent' => __('site.careers.form.consent_short'),
        ]);

        $vacancy = filled($data['vacancy_id'] ?? null) ? Vacancy::find($data['vacancy_id']) : null;
        $file = $request->file('cv');
        $extension = self::cvExtension($file);
        // Random name on the private disk; the original name is kept only for the download.
        $path = $file->storeAs(JobApplication::CV_DIR, Str::random(40).'.'.$extension, 'local');

        $application = JobApplication::create([
            'vacancy_id' => $vacancy?->id,
            'vacancy_title' => $vacancy?->titleIn('en') ?: $vacancy?->titleIn($locale),
            'department' => $vacancy?->department ?? ($data['department'] ?? null),
            'name' => strip_tags(trim($data['name'])),
            'phone' => trim($data['phone']),
            'email' => $data['email'] ?? null,
            'message' => isset($data['message']) ? strip_tags(trim($data['message'])) : null,
            'cv_path' => $path,
            'cv_name' => JobApplication::cleanFileName($file->getClientOriginalName(), $extension),
            'cv_size' => (int) $file->getSize(),
            'locale' => $locale,
        ]);

        // After the response: post the CV to the admin Telegram chat, then delete it from the server.
        defer(fn () => rescue(fn () => $application->sendCvToTelegram($notifier), report: true));

        return redirect()->to(route('site.careers', $locale).'#apply')->with('careers_sent', true);
    }
}
