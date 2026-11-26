<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact's order from the chat: draft (the AI keeps collecting it until the customer confirms or cancels), confirmed
 * (the customer confirmed; the last OrderBook::KEEP are kept in full, older ones go to OrderMonth), or cancelled.
 * The company details are copied when the order is made (they stay as they were even if the company changes).
 */
class Order extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'];

    protected $fillable = ['telegram_user_id', 'chat_conversation_id', 'chat_lead_id', 'client_id', 'company', 'status', 'items', 'quoted', 'summary', 'confirmed_at', 'cancelled_at', 'nudged_at'];

    protected function casts(): array
    {
        return ['company' => 'array', 'items' => 'array', 'quoted' => 'array', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'nudged_at' => 'datetime'];
    }

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(ChatLead::class, 'chat_lead_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /** @return list<string> "Zebra DS2208: 3 pcs" */
    public function itemLines(): array
    {
        return array_values(array_map(fn ($i) => trim(($i['product'] ?? '').(filled($i['details'] ?? null) ? ': '.$i['details'] : '')), (array) $this->items));
    }
}
