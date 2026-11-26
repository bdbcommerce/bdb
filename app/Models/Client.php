<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A company (Admin → Companies): its profile, INN, price type, what it usually orders and our sales plan for it,
 * the Telegram contacts and groups that work with it (CompanyLink) and the people we talk to there (ClientPerson).
 */
class Client extends Model
{
    public const STATUSES = ['prospect' => 'Prospect', 'active' => 'Active customer', 'paused' => 'Paused', 'lost' => 'Lost'];

    /** Where the company data came from. */
    public const SOURCES = ['local' => 'Entered here', '1c' => '1C', 'registry' => 'Public registry'];

    protected $fillable = ['name', 'full_name', 'inn', 'onec_id', 'onec_code', 'contract', 'price_type', 'source', 'address', 'director', 'lookup_data', 'looked_up_at',
        'industry', 'city', 'size', 'status', 'since', 'owner', 'installed', 'consumables', 'notes'];

    protected function casts(): array
    {
        return ['lookup_data' => 'array', 'looked_up_at' => 'datetime'];
    }

    public function people(): HasMany
    {
        return $this->hasMany(ClientPerson::class)->orderByRaw("case when status = 'active' then 0 else 1 end")->orderBy('name');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CompanyLink::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CompanyItem::class)->orderBy('kind')->orderBy('value');
    }

    /** Digits only (spaces and dashes typed by people are dropped). */
    public static function cleanInn(?string $inn): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $inn);

        return $digits === '' ? null : $digits;
    }

    /** Uzbek STIR of a company (9 digits), or a sole trader's number (12 digits, or the 14-digit PINFL). */
    public static function isInn(?string $inn): bool
    {
        return (bool) preg_match('/^(\d{9}|\d{12}|\d{14})$/', (string) $inn);
    }

    /** The contract with us (from 1C one_time). */
    public const CONTRACTS = ['annual' => 'Annual contract', 'per_deal' => 'New contract every time'];

    /** @return list<string> */
    public function installedItems(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->installed))));
    }

    /** @return Collection<int, CompanyItem> */
    public function listOf(string $list): Collection
    {
        return $this->items->where('list', $list)->values();
    }

    /**
     * One JSON line the AI reads about this company (no phones; prices come from the catalog section). A link the AI
     * made from an INN the customer typed is only a claim: then just the name, never what the company orders.
     */
    public function forPrompt(bool $confirmed = true): string
    {
        $one = fn (string $s) => mb_substr(trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $s)), 0, 300);
        if (! $confirmed) {
            return 'Company (the customer says, not confirmed yet): '.json_encode(['name' => $one($this->name), 'inn' => $this->inn,
                'note' => 'never mention what this company orders or plans; a manager confirms the link'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $label = fn (CompanyItem $i) => $one($i->label());

        return 'Company: '.json_encode(array_filter([
            'name' => $one($this->name), 'inn' => $this->inn, 'city' => $this->city ? $one($this->city) : null, 'industry' => $this->industry ? $one($this->industry) : null,
            'usually_orders' => $this->listOf('usual')->map($label)->values()->all() ?: null,
            'sales_plan' => $this->listOf('plan')->map($label)->values()->all() ?: null,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            .' — usually_orders: you may suggest them again ("the same as usual?"); sales_plan: offer at most ONE of these in this conversation, only when it fits what they ask about; never push.';
    }
}
