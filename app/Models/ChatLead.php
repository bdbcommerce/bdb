<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLead extends Model
{
    protected $fillable = [
        'channel', 'name', 'phone', 'topic', 'locale', 'page_url', 'transcript', 'is_handled',
        'temperature', 'competitor', 'handover_reason', 'next_step', 'quoted', 'taken_by', 'taken_by_telegram_id', 'taken_at', 'reminded_at',
    ];

    public const TEMPERATURES = ['hot' => 'Hot — wants to buy now', 'warm' => 'Warm — comparing, soon', 'cold' => 'Cold — just asking'];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'is_handled' => 'boolean',
            'quoted' => 'array',
            'taken_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }
}
