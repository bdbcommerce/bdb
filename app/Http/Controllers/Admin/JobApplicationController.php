<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** CVs sent from the Careers page. */
class JobApplicationController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $vacancyId = $request->query('vacancy');

        $applications = JobApplication::with('vacancy')
            ->when(array_key_exists((string) $status, JobApplication::STATUSES), fn ($q) => $q->where('status', $status))
            ->when($vacancyId === 'none', fn ($q) => $q->whereNull('vacancy_id'))
            ->when(ctype_digit((string) $vacancyId), fn ($q) => $q->where('vacancy_id', (int) $vacancyId))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.job-applications.index', [
            'applications' => $applications,
            'vacancies' => Vacancy::orderBy('sort_order')->get(),
            'counts' => JobApplication::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'status' => $status,
            'vacancyId' => $vacancyId,
        ]);
    }

    public function show(JobApplication $jobApplication)
    {
        // Opening a new application marks it as reviewed.
        if ($jobApplication->status === 'new') {
            $jobApplication->update(['status' => 'reviewed']);
        }

        return view('admin.job-applications.show', ['application' => $jobApplication->load('vacancy')]);
    }

    public function update(Request $request, JobApplication $jobApplication)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(JobApplication::STATUSES))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $jobApplication->update($data);

        return redirect()->route('admin.job-applications.show', $jobApplication)->with('success', 'Application updated.');
    }

    public function destroy(JobApplication $jobApplication)
    {
        $jobApplication->delete(); // the CV file is removed too

        return redirect()->route('admin.job-applications.index')->with('success', 'Application and CV deleted.');
    }

    /** Download the CV (private disk; never served publicly). */
    public function download(JobApplication $jobApplication)
    {
        $disk = Storage::disk('local');
        abort_unless($jobApplication->hasSafePath() && $disk->exists($jobApplication->cv_path), 404);

        return $disk->download($jobApplication->cv_path, $jobApplication->cv_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
