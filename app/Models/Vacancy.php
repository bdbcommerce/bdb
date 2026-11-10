<?php

namespace App\Models;

use App\Models\Concerns\FlushesChatKnowledge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** An open position on the public Careers page (Admin → Careers → Vacancies). */
class Vacancy extends Model
{
    use FlushesChatKnowledge, HasTranslations;

    /** Department keys; labels live in lang/{locale}/site.php careers.departments. */
    public const DEPARTMENTS = ['sales', 'projects', 'service', 'software', 'warehouse', 'marketing', 'finance', 'admin'];

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'internship', 'contract'];

    /** schema.org JobPosting employmentType values. */
    public const SCHEMA_TYPES = ['full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'internship' => 'INTERN', 'contract' => 'CONTRACTOR'];

    public array $translatable = ['title', 'location', 'description', 'requirements', 'offer'];

    protected $fillable = [
        'title', 'department', 'employment_type', 'location', 'salary',
        'description', 'requirements', 'offer', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderByDesc('id');
    }

    /** One bullet per non-empty line of a translatable list field. */
    public function lines(string $field, string $locale): array
    {
        $text = (string) $this->getTranslation($field, $locale, false);

        // /u everywhere: without it \R matches byte 0x85 inside Cyrillic "х", and bullets are stripped byte by byte.
        return array_values(array_filter(array_map(
            fn ($l) => trim((string) preg_replace('/^[\s\-•*·—–]+/u', '', $l)),
            preg_split('/\R/u', $text) ?: []
        ), 'strlen'));
    }

    /** Title in the given locale, falling back to any filled translation. */
    public function titleIn(string $locale): string
    {
        return (string) ($this->getTranslation('title', $locale, false)
            ?: collect($this->getTranslations('title'))->filter()->first());
    }
}
