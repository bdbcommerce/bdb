<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An item from 1C (crm/item), loaded on demand by an admin (Products / Companies → Load items from 1C).
 * company_onec_id = the company the item belongs to in 1C; category_id is 1C's own number.
 *
 * The 1C columns are replaced by every load. The AI columns (AI_FIELDS, sales_note, ai_use, info_*) are ours:
 * staff type them on Conversations → Products or the AI drafts them ("Fill with AI"); a load never touches them.
 */
class OnecItem extends Model
{
    /** What the AI may draft: field => [label, max length]. sales_note is staff only. */
    public const AI_FIELDS = [
        'brand' => ['Brand', 80],
        'model' => ['Model', 120],
        'ai_category' => ['Category', 30],
        'ai_summary' => ['What it is and who needs it', 1000],
        'specs' => ['Specifications — one "name: value" per line', 2000],
        'compatible_with' => ['Works with (devices, consumables)', 1000],
        'keywords' => ['Words customers use (uz / ru / en)', 500],
    ];

    /** ai_category: the catalog categories plus what only the 1C list has. */
    public const EXTRA_CATEGORIES = ['accessory' => 'Accessories', 'spare_part' => 'Spare parts', 'other' => 'Other'];

    /** info_status (null = nothing filled yet) */
    public const STATUSES = ['ai' => 'AI draft — check it', 'checked' => 'Checked'];

    protected $fillable = [
        'onec_id', 'code', 'name', 'full_name', 'short_name', 'mxik', 'barcode', 'unit', 'category_id', 'company_onec_id', 'description',
        'brand', 'model', 'ai_category', 'ai_summary', 'specs', 'compatible_with', 'keywords', 'sales_note', 'ai_use', 'info_status', 'info_by', 'info_at',
    ];

    protected $attributes = ['ai_use' => true];

    protected function casts(): array
    {
        return ['code' => 'integer', 'category_id' => 'integer', 'ai_use' => 'boolean', 'info_at' => 'datetime'];
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** @return array<string, string> key => label */
    public static function categories(): array
    {
        return array_map(fn ($c) => $c[0], CatalogProduct::CATEGORIES) + self::EXTRA_CATEGORIES;
    }

    public function categoryLabel(): ?string
    {
        return $this->ai_category ? (self::categories()[$this->ai_category] ?? $this->ai_category) : null;
    }

    /** @return list<string> the AI fields that are still empty */
    public function emptyAiFields(): array
    {
        return array_values(array_filter(array_keys(self::AI_FIELDS), fn ($f) => blank($this->$f)));
    }

    /**
     * The admin search: a part of the name, barcode, MXIK, brand, model or customer words, or the 1C code.
     * SQLite's LIKE ignores case only for Latin letters, so Cyrillic is tried as typed, lower, UPPER and Capitalised.
     * ESCAPE '!': SQLite has no default escape character, and a backslash one breaks on MySQL.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        if ($search === '') {
            return $query;
        }
        $likes = array_map(fn ($v) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v).'%', array_values(array_unique([
            $search, mb_strtolower($search), mb_strtoupper($search), mb_strtoupper(mb_substr($search, 0, 1)).mb_strtolower(mb_substr($search, 1)),
            mb_convert_case($search, MB_CASE_TITLE),
        ])));

        return $query->where(function ($q) use ($search, $likes) {
            foreach (['name', 'full_name', 'short_name', 'barcode', 'mxik', 'brand', 'model', 'keywords'] as $column) {
                foreach ($likes as $like) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }
            }
            if (ctype_digit($search) && strlen($search) <= 9) {
                $q->orWhere('code', (int) $search);
            }
        });
    }

    /** Products nobody (staff or AI) has described yet. */
    public function scopeWithoutInfo(Builder $query): Builder
    {
        return $query->whereNull('info_status');
    }

    /** What "Fill the next N with AI" takes: nothing filled yet and not hidden from the AI. */
    public function scopeToDescribe(Builder $query): Builder
    {
        return $query->whereNull('info_status')->where('ai_use', true);
    }

    /**
     * Text typed by staff or returned by the AI, made safe to put into a prompt or a list later: brand, model and
     * keywords become one line; the others keep line breaks; control and invisible direction marks go everywhere.
     */
    public static function cleanText(string $field, ?string $value): ?string
    {
        $value = (string) preg_replace('/\p{Cf}+/u', '', (string) $value);
        $value = in_array($field, ['brand', 'model', 'keywords', 'ai_category'], true)
            ? (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $value)
            : (string) preg_replace('/[^\P{Cc}\n]+/u', ' ', str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $value));
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** What 1C says about the item, for the AI draft: only the filled fields. */
    public function onecFacts(): array
    {
        return array_filter([
            'name' => $this->name, 'full_name' => $this->full_name, 'short_name' => $this->short_name,
            'unit' => $this->unit, 'barcode' => $this->barcode, 'description' => $this->description,
        ], fn ($v) => filled($v));
    }
}
