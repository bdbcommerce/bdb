<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A product from the 1C catalog (docs/1c-catalog-http-service.md). Replaced by every full load:
 * products missing from the latest load keep their row but get in_feed = false and are hidden from the AI.
 */
class CatalogProduct extends Model
{
    /** category => [label, params keys from the spec] */
    public const CATEGORIES = [
        'label_printer' => ['Label printers', ['print_method', 'print_width_mm', 'resolution_dpi', 'print_speed_mm_s', 'connectivity', 'class', 'max_media_width_mm', 'media_core_mm', 'max_ribbon_length_m', 'ribbon_core_mm', 'ribbon_ink_side']],
        'receipt_printer' => ['Receipt printers', ['paper_width_mm', 'print_speed_mm_s', 'connectivity', 'auto_cutter']],
        'scanner' => ['Barcode scanners', ['code_types', 'form', 'wireless', 'connectivity', 'reads_screens', 'reads_datamatrix', 'ip_rating']],
        'tsd' => ['Data collection terminals', ['os', 'screen_inch', 'scanner_type', 'ram_gb', 'storage_gb', 'battery_mah', 'ip_rating', 'freezer', 'cellular', 'nfc', 'rfid']],
        'label' => ['Labels', ['material', 'width_mm', 'height_mm', 'labels_per_roll', 'core_mm', 'outer_diameter_mm', 'winding', 'freezer']],
        'ribbon' => ['Ribbons', ['type', 'width_mm', 'length_m', 'core_mm', 'ink_side']],
        'receipt_paper' => ['Receipt paper', ['width_mm', 'length_m', 'core_mm', 'outer_diameter_mm']],
        'scale' => ['Scales', ['max_weight_kg', 'division_g', 'label_printing', 'connectivity']],
        'pos' => ['POS equipment', ['type', 'cpu', 'ram_gb', 'storage_gb', 'screen_inch', 'os']],
        'rfid' => ['RFID', ['kind', 'frequency', 'read_range_m']],
        'software' => ['Software', ['works_with', 'license']],
        'service' => ['Services', ['unit']],
    ];

    public const STOCK = ['in_stock' => 'In stock', 'low' => 'Low', 'to_order' => 'To order', 'out' => 'Out of stock'];

    /** Price groups, lowest first. A group sees its own price and every price below it. */
    public const PRICE_GROUPS = ['end_user' => 'End user', 'dealer' => 'Dealer', 'partner' => 'Partner'];

    public const USE_CASES = ['retail', 'warehouse', 'production', 'pharmacy', 'textile', 'horeca', 'logistics', 'medical'];

    protected $fillable = [
        'sku', 'brand', 'model', 'category', 'name', 'prices', 'stock_status', 'stock_qty', 'lead_time_days',
        'warranty_months', 'params', 'use_cases', 'accessories', 'is_active', 'in_feed',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array', 'prices' => 'array', 'params' => 'array', 'use_cases' => 'array', 'accessories' => 'array',
            'stock_qty' => 'integer', 'lead_time_days' => 'integer', 'warranty_months' => 'integer',
            'is_active' => 'boolean', 'in_feed' => 'boolean',
        ];
    }

    /** What the AI may offer: active in 1C and in the latest load. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('in_feed', true);
    }

    /**
     * The price groups a customer of $group may see: partner → all three, dealer → dealer + end user,
     * anyone else → end user only.
     *
     * @return list<string>
     */
    public static function visibleGroups(?string $group): array
    {
        $groups = array_keys(self::PRICE_GROUPS);
        $level = array_search($group, $groups, true);

        return array_slice($groups, 0, ($level === false ? 0 : $level) + 1);
    }

    /** @return array<string, int> only the prices $group may see (never a higher group's price) */
    public function pricesFor(?string $group): array
    {
        $prices = [];
        foreach (self::visibleGroups($group) as $g) {
            if (is_int($this->prices[$g] ?? null)) {
                $prices[$g] = $this->prices[$g];
            }
        }

        return $prices;
    }

    public function displayName(string $locale = 'ru'): string
    {
        $name = (array) $this->name;

        return (string) ($name[$locale] ?? $name['ru'] ?? $name['uz'] ?? $name['en'] ?? "{$this->brand} {$this->model}");
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category][0] ?? $category;
    }
}
