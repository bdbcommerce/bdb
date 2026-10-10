<?php

namespace App\Models\Concerns;

use App\Services\ChatAssistant;

/**
 * For models whose content feeds the chat assistant: clears its cached
 * site knowledge whenever a record is saved or deleted.
 */
trait FlushesChatKnowledge
{
    protected static function bootFlushesChatKnowledge(): void
    {
        static::saved(fn () => ChatAssistant::forgetKnowledge());
        static::deleted(fn () => ChatAssistant::forgetKnowledge());
    }
}
