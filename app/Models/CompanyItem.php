<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a company's lists: usual (what they usually order) or plan (what we plan to sell them).
 * kind category = a CatalogProduct::CATEGORIES key, brand = a brand name, sku = a catalog SKU.
 */
class CompanyItem extends Model
{
    public const LISTS = ['usual' => 'Usually orders', 'plan' => 'Sales plan'];

    public const KINDS = ['category' => 'Category', 'brand' => 'Brand', 'sku' => 'Catalog item', 'item1c' => '1C item'];

    protected $fillable = ['client_id', 'list', 'kind', 'value', 'moved_at', 'moved_by'];

    protected function casts(): array
    {
        return ['moved_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** What people and the AI read: "Label printers", "Zebra (brand)", "ZD421T — Zebra ZD421t". */
    public function label(): string
    {
        return match ($this->kind) {
            'category' => CatalogProduct::CATEGORIES[$this->value][0] ?? $this->value,
            'brand' => $this->value.' (brand)',
            'item1c' => ($i = OnecItem::where('code', (int) $this->value)->first()) ? $i->name : '1C item '.$this->value,
            default => $this->value.(($p = CatalogProduct::where('sku', $this->value)->first()) ? ' — '.trim($p->brand.' '.$p->model) : ''),
        };
    }

    /** Does a product (or a free-text item of a request) fall under this entry? */
    public function covers(?CatalogProduct $product, string $text = ''): bool
    {
        $text = mb_strtolower($text);
        $has = fn (?string $s) => filled($s) && str_contains($text, mb_strtolower((string) $s));

        return match ($this->kind) {
            'sku' => ($product && strcasecmp($product->sku, $this->value) === 0) || $has($this->value)
                || (($p = CatalogProduct::where('sku', $this->value)->first()) && $has($p->model)),
            'brand' => ($product && strcasecmp((string) $product->brand, $this->value) === 0) || $has($this->value),
            'category' => $product && $product->category === $this->value,
            'item1c' => $has($this->value) || (($i = OnecItem::where('code', (int) $this->value)->first()) && ($has($i->name) || $has($i->short_name))),
            default => false,
        };
    }
}
