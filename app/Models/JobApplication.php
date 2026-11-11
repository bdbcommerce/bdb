<?php

namespace App\Models;

use App\Services\TelegramNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A CV sent from the Careers page. The file is posted to the admin Telegram chat and removed
 * from the server; only its Telegram `cv_file_id` is kept and admins download it through the
 * bot. If the upload fails the file stays on the private "local" disk (`cv_path`, never public)
 * until `careers:send-cvs` retries. Personal data: pruned after 365 days; deleting or pruning
 * also deletes the CV message in the Telegram chat (best effort: Telegram may refuse).
 */
class JobApplication extends Model
{
    use Prunable;

    public const STATUSES = ['new' => 'New', 'reviewed' => 'Reviewed', 'interview' => 'Interview', 'hired' => 'Hired', 'rejected' => 'Rejected'];

    public const CV_DIR = 'cvs';

    protected $fillable = [
        'vacancy_id', 'vacancy_title', 'department', 'name', 'phone', 'email', 'message',
        'cv_path', 'cv_file_id', 'cv_chat_id', 'cv_message_id', 'cv_name', 'cv_size', 'locale', 'status', 'notes',
    ];

    /**
     * Send the CV to the admin Telegram chat; on success delete it from the server and keep only the
     * file_id. A lock stops the upload right after the form and the hourly retry sending it twice.
     * Retries pass $textFallback = false so the details are not re-posted every hour.
     */
    public function sendCvToTelegram(TelegramNotifier $notifier, bool $textFallback = true): bool
    {
        return (bool) Cache::lock('job-application-cv:'.$this->id, 120)->get(function () use ($notifier, $textFallback) {
            $this->refresh();
            if ($this->cv_file_id || ! $this->cv_path) {
                return false; // already sent by the other process
            }

            $sent = $notifier->newApplication($this, $textFallback);
            if (! $sent) {
                return false;
            }

            // Record the Telegram copy first; the local file is deleted only once that is saved,
            // so a failed save (e.g. migration not run) never leaves the CV unreachable.
            $localPath = $this->cv_path;
            $this->update([
                'cv_file_id' => $sent['file_id'],
                'cv_chat_id' => $sent['chat_id'],
                'cv_message_id' => $sent['message_id'],
                'cv_path' => null,
            ]);
            if (self::isSafeCvPath($localPath)) {
                Storage::disk('local')->delete($localPath);
            }

            return true;
        });
    }

    protected static function booted(): void
    {
        static::deleted(fn (JobApplication $a) => $a->deleteFile());
    }

    /** Delete the CV wherever it is: the private disk and the Telegram chat message (best effort). */
    public function deleteFile(): void
    {
        if ($this->hasSafePath()) {
            Storage::disk('local')->delete($this->cv_path);
        }

        if ($this->cv_chat_id && $this->cv_message_id) {
            rescue(fn () => app(TelegramNotifier::class)->deleteMessage($this->cv_chat_id, (int) $this->cv_message_id), report: false);
        }
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(365));
    }

    protected function pruning(): void
    {
        $this->deleteFile();
    }

    /** True only for the server-generated name: cvs/<40 random chars>.<allowed extension>. */
    public function hasSafePath(): bool
    {
        return self::isSafeCvPath($this->cv_path);
    }

    private static function isSafeCvPath(?string $path): bool
    {
        return (bool) preg_match('#^'.self::CV_DIR.'/[A-Za-z0-9]{40}\.(pdf|docx?|rtf|odt)$#D', (string) $path);
    }

    /** Safe download name: the applicant's original file name without path or odd characters. */
    public static function cleanFileName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN ._-]+/u', '', $base) ?? '';
        $base = trim(mb_substr($base, 0, 80), ' .-') ?: 'cv';

        return $base.'.'.$extension;
    }
}
