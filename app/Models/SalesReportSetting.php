<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Weekly sales report settings (singleton). Admin → Telegram → Sales report.
 * The weekly report always goes to the invoice parser's source group (InvoiceParserSetting::source_chat_id) — nowhere else;
 * the daily report goes to the invoice parser's JSON recipient (json_chat_id, usually a user ID).
 */
class SalesReportSetting extends Model
{
    public const MODEL = 'claude-opus-5-5';

    public const LANGUAGES = ['uz' => 'Oʻzbekcha', 'ru' => 'Русский', 'en' => 'English'];

    /** Channels that can be analysed (subset of ChatConversation::CHANNELS). */
    public const CHANNELS = ['telegram' => 'Telegram bot chats (customers + staff replies)', 'business' => 'Premium-account chats (Telegram Business)', 'group' => 'Telegram groups the bot is in'];

    public const DEFAULT_CHANNELS = ['telegram', 'business'];

    protected $fillable = ['enabled', 'daily_enabled', 'language', 'channels', 'instructions'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'daily_enabled' => 'boolean', 'channels' => 'array'];
    }

    public static function current(): self
    {
        try {
            return static::firstOrCreate(['id' => 1])->refresh();
        } catch (Throwable) {
            return new static(['enabled' => false, 'daily_enabled' => false]);
        }
    }

    /** @return list<string> */
    public function channelList(): array
    {
        $list = array_values(array_intersect(array_keys(self::CHANNELS), (array) ($this->channels ?? self::DEFAULT_CHANNELS)));

        return $list ?: self::DEFAULT_CHANNELS;
    }

    public function languageCode(): string
    {
        return array_key_exists((string) $this->language, self::LANGUAGES) ? (string) $this->language : 'uz';
    }

    /** The Telegram group the report goes to: the invoice parser's source group (a group ID, starts with -). */
    public static function chatId(): ?string
    {
        $id = trim((string) InvoiceParserSetting::current()->source_chat_id);

        return str_starts_with($id, '-') ? $id : null;
    }

    /** The daily report's recipient: the invoice parser's JSON recipient — never the invoice (accountants') chat or the source group. */
    public static function dailyChatId(): ?string
    {
        $settings = InvoiceParserSetting::current();
        $id = trim((string) $settings->json_chat_id);

        // Not the invoice chat and not the source group (that one gets the weekly report): settings saved before the rules.
        return $id !== '' && ! in_array($id, [trim((string) $settings->target_chat_id), trim((string) $settings->source_chat_id)], true) ? $id : null;
    }

    /** Where a report of this period ('week' | 'day') goes. */
    public static function chatFor(string $period): ?string
    {
        return $period === 'day' ? self::dailyChatId() : self::chatId();
    }
}
