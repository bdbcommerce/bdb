<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLead extends Model
{
    protected $fillable = [
        'channel', 'name', 'phone', 'topic', 'locale', 'page_url', 'transcript', 'is_handled',
    ];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'is_handled' => 'boolean',
        ];
    }
}
