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

    /**
     * The pay in the free-text salary, for schema.org baseSalary. Examples:
     * "6 000 000 UZS", "5–7 млн", "от 5 000 000 до 7 000 000 сум", "до 8 mln", "$500 per week".
     * Returns min/max (one of them null for "up to" / "from"), an ISO currency and the period
     * (MONTH unless the text says hour/day/week/year). Null when it is unclear — e.g. "By agreement",
     * more than two amounts, or a phone number in the text — so nothing wrong reaches search engines.
     *
     * @return array{min: int|null, max: int|null, currency: string, unit: string}|null
     */
    public function salaryRange(): ?array
    {
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $this->salary);
        if ($text === '' || preg_match('/\+\s?\d|\btel\b|тел|call|звон|qoʻngʻiroq/iu', $text)) {
            return null;
        }

        preg_match_all('/(\d{1,3}(?:[ ,.]\d{3})+(?:[.,]\d{1,2}(?!\d))?|\d+(?:[.,]\d+)?)\s*(mln|million|млн|миллион\w*)?/iu', $text, $m, PREG_SET_ORDER);

        $amounts = [];
        foreach ($m as $match) {
            $raw = $match[1];
            $millions = ! empty($match[2]);
            if (! $millions && preg_match('/^(19|20)\d\d$/', $raw)) {
                continue; // a year, not an amount
            }
            // "6,000,000.00" / "6 000 000,00": drop the cents; "4,5" / "4.5" before "mln" is a decimal.
            $digits = preg_replace('/[.,]\d{1,2}$/', '', $raw);
            $value = $millions && preg_match('/^\d+[.,]\d{1,2}$/', $raw)
                ? (float) str_replace(',', '.', $raw)
                : (float) preg_replace('/\D/', '', $digits);
            $amounts[] = ['value' => $millions ? $value * 1_000_000 : $value, 'millions' => $millions];
        }

        // "5–7 млн": the multiplier written once applies to both ends.
        if (count($amounts) === 2 && $amounts[1]['millions'] && ! $amounts[0]['millions'] && $amounts[0]['value'] < 1000) {
            $amounts[0]['value'] *= 1_000_000;
        }

        // Small numbers ("2 shifts") are not amounts; absurd ones are typos.
        $values = array_values(array_filter(
            array_map(fn ($a) => (int) round($a['value']), $amounts),
            fn (int $v) => $v >= 100 && $v < 1_000_000_000_000,
        ));
        if ($values === [] || count($values) > 2) {
            return null;
        }

        $min = min($values);
        $max = max($values);
        if (count($values) === 1) {
            // (?<!\pL)…(?!\pL): word boundaries that also work for Cyrillic (\b does not).
            if (preg_match('/(?<!\pL)(до|up to|max|не более|gacha)(?!\pL)/iu', $text)) {
                $min = null;
            } elseif (preg_match('/(?<!\pL)(от|from|min|dan)(?!\pL)/iu', $text)) {
                $max = null;
            }
        }

        $currency = match (true) {
            (bool) preg_match('/\$|usd|доллар|dollar|у\.\s?е\./iu', $text) => 'USD',
            (bool) preg_match('/€|eur|евро/iu', $text) => 'EUR',
            default => 'UZS',
        };
        $unit = match (true) {
            (bool) preg_match('/hour|час|soat/iu', $text) => 'HOUR',
            (bool) preg_match('/(?<!\pL)day(?!\pL)|день|kuniga|kunlik/iu', $text) => 'DAY',
            (bool) preg_match('/week|недел|hafta/iu', $text) => 'WEEK',
            (bool) preg_match('/year|в год|годов|yillik|yiliga/iu', $text) => 'YEAR',
            default => 'MONTH',
        };

        return ['min' => $min, 'max' => $max, 'currency' => $currency, 'unit' => $unit];
    }

    /** Title in the given locale, falling back to any filled translation. */
    public function titleIn(string $locale): string
    {
        return (string) ($this->getTranslation('title', $locale, false)
            ?: collect($this->getTranslations('title'))->filter()->first());
    }
}
