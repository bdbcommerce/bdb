<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A client company: its profile, installed equipment, consumables and the people we talk to there (ClientPerson). */
class Client extends Model
{
    public const STATUSES = ['prospect' => 'Prospect', 'active' => 'Active customer', 'paused' => 'Paused', 'lost' => 'Lost'];

    protected $fillable = ['name', 'inn', 'industry', 'city', 'size', 'status', 'since', 'owner', 'installed', 'consumables', 'notes'];

    public function people(): HasMany
    {
        return $this->hasMany(ClientPerson::class)->orderByRaw("case when status = 'active' then 0 else 1 end")->orderBy('name');
    }

    /** @return list<string> */
    public function installedItems(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->installed))));
    }
}
