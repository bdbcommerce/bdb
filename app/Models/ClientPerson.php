<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A person at a client company: position, department, the part they play in decisions, a status and how to talk
 * to them; optionally linked to their Telegram customer. When someone moves to another company, a new card is made
 * there (previous_id → the old one, which becomes "left").
 */
class ClientPerson extends Model
{
    public const STATUSES = ['active' => 'Active', 'vacation' => 'On leave', 'left' => 'Left the company', 'fired' => 'Dismissed', 'do_not_contact' => 'Do not contact'];

    public const PARTS = ['decides' => 'Decides', 'pays' => 'Pays', 'uses' => 'Uses', 'technical' => 'Technical', 'recommends' => 'Recommends'];

    protected $fillable = ['client_id', 'telegram_user_id', 'previous_id', 'name', 'position', 'department', 'part', 'status', 'style', 'phone', 'notes'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function next(): HasOne
    {
        return $this->hasOne(self::class, 'previous_id');
    }

    /** The current card of a Telegram customer (the newest one that is not "left" / "dismissed"). */
    public static function current(?TelegramUser $user): ?self
    {
        return $user ? static::where('telegram_user_id', $user->id)->whereNotIn('status', ['left', 'fired'])->with('client')->latest('id')->first() : null;
    }

    /** The portrait lines the AI team reads (no phones: they are on the customer card already). */
    public function forPrompt(): array
    {
        $who = array_filter([$this->position, $this->department ? '('.$this->department.')' : null]);
        $lines = ['Client portrait: '.$this->name.($who ? ' — '.implode(' ', $who) : '').($this->part ? '; in decisions: '.mb_strtolower(self::PARTS[$this->part] ?? $this->part) : '')
            .($this->style ? '; how to talk to them: '.$this->style : '').($this->status !== 'active' ? '; status: '.mb_strtolower(self::STATUSES[$this->status] ?? $this->status) : '')
            .($this->notes ? '; notes: '.$this->notes : '')];
        if ($c = $this->client) {
            $lines[] = 'Company: '.$c->name.collect([$c->industry, $c->city, $c->size])->filter()->map(fn ($v) => ', '.$v)->implode('')
                .'; '.mb_strtolower(Client::STATUSES[$c->status] ?? $c->status).($c->since ? ' since '.$c->since : '')
                .($c->notes ? '. Notes: '.mb_strimwidth($c->notes, 0, 600, '…') : '')
                .($c->consumables ? '. Consumables: '.mb_strimwidth($c->consumables, 0, 300, '…') : '')
                .($c->installedItems() ? '. Installed: '.mb_strimwidth(implode('; ', $c->installedItems()), 0, 500, '…') : '');
            $others = $c->people()->where('id', '!=', $this->id)->where('status', 'active')->limit(8)->get()
                ->map(fn (self $p) => $p->name.' ('.collect([$p->position, $p->part ? mb_strtolower(self::PARTS[$p->part] ?? $p->part) : null])->filter()->implode(', ').')');
            if ($others->isNotEmpty()) {
                $lines[] = 'Other people there: '.$others->implode(', ');
            }
        }

        // One line each (a typed line break could look like a new prompt section).
        return array_map(fn ($l) => mb_strimwidth(preg_replace('/\s*\R\s*/u', ' / ', $l), 0, 1500, '…'), $lines);
    }
}
