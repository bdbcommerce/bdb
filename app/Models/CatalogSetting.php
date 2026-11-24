<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Catalog source (singleton, Admin → AI Sales & Support Team → Product catalog):
 * push (1C calls POST /api/catalog with the Bearer token) or pull (we GET the 1C HTTP service hourly, Basic auth).
 */
class CatalogSetting extends Model
{
    public const SOURCES = ['push' => 'Push — 1C sends to us', 'pull' => 'Pull — we load from 1C'];

    protected $fillable = ['source', 'pull_url', 'pull_username', 'pull_password', 'max_age_hours', 'last_success_at', 'last_attempt_at', 'last_error'];

    protected $hidden = ['token_hash', 'pull_password'];

    protected function casts(): array
    {
        return [
            'pull_password' => 'encrypted',
            'max_age_hours' => 'integer',
            'last_success_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        try {
            return static::firstOrCreate(['id' => 1], ['source' => 'push', 'max_age_hours' => 24])->refresh();
        } catch (Throwable) {
            return new static(['source' => 'push', 'max_age_hours' => 24]);
        }
    }

    /** Create a new push token; returns it in plain text (only now — the database keeps a hash). */
    public function regenerateToken(): string
    {
        $token = 'bdb_cat_'.Str::random(40);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'token_hint' => substr($token, -4)])->save();

        return $token;
    }

    public function tokenMatches(?string $token): bool
    {
        return $this->token_hash !== null && is_string($token) && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }

    /** Prices and stock may be quoted only while the last good load is younger than max_age_hours. */
    public function isFresh(): bool
    {
        return $this->last_success_at !== null && $this->last_success_at->gt(now()->subHours(max(1, $this->max_age_hours)));
    }
}
