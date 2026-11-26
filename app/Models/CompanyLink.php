<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram contact (telegram_user_id) or a Telegram group (group_chat_id) works with a company.
 * linked_by ai = the AI found the company from an INN the customer sent; manager = linked or confirmed by a person.
 * Only manager links give the contact the company's price type.
 */
class CompanyLink extends Model
{
    public const BY = ['ai' => 'Found by the AI', 'manager' => 'Linked by a manager'];

    protected $fillable = ['client_id', 'telegram_user_id', 'group_chat_id', 'linked_by', 'linked_by_name'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function byManager(): bool
    {
        return $this->linked_by === 'manager';
    }

    /** The group's title from its latest recorded conversation. */
    public function groupTitle(): ?string
    {
        return $this->group_chat_id ? ChatConversation::where('channel', 'group')->where('external_id', $this->group_chat_id)->latest('id')->value('title') : null;
    }
}
