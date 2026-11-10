<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A CV sent from the Careers page. The file lives on the private "local" disk (never public)
 * and is downloaded by admins only. Personal data: pruned with its file after 365 days.
 */
class JobApplication extends Model
{
    use Prunable;

    public const STATUSES = ['new' => 'New', 'reviewed' => 'Reviewed', 'interview' => 'Interview', 'hired' => 'Hired', 'rejected' => 'Rejected'];

    public const CV_DIR = 'cvs';

    protected $fillable = [
        'vacancy_id', 'vacancy_title', 'department', 'name', 'phone', 'email', 'message',
        'cv_path', 'cv_name', 'cv_size', 'locale', 'status', 'notes',
    ];

    protected static function booted(): void
    {
        static::deleted(fn (JobApplication $a) => $a->deleteFile());
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
        return (bool) preg_match('#^'.self::CV_DIR.'/[A-Za-z0-9]{40}\.(pdf|docx?|rtf|odt)$#D', (string) $this->cv_path);
    }

    public function deleteFile(): void
    {
        if ($this->hasSafePath()) {
            Storage::disk('local')->delete($this->cv_path);
        }
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
