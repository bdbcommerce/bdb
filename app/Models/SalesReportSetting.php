<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Weekly sales report settings (singleton). Admin → Telegram → Sales report.
 * The report always goes to the invoice parser's source group (InvoiceParserSetting::source_chat_id) — nowhere else.
 */
class SalesReportSetting extends Model
{
    public const MODEL = 'claude-opus-5-5';

    public const LANGUAGES = ['uz' => 'Oʻzbekcha', 'ru' => 'Русский', 'en' => 'English'];

    /** Channels that can be analysed (subset of ChatConversation::CHANNELS). */
    public const CHANNELS = ['telegram' => 'Telegram bot (customers + staff replies)', 'business' => 'Telegram Business (Premium account chats)', 'group' => 'Telegram groups the bot is in'];

    public const DEFAULT_CHANNELS = ['telegram', 'business'];

    protected $fillable = ['enabled', 'language', 'channels', 'instructions'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'channels' => 'array'];
    }

    public static function current(): self
    {
        try {
            return static::firstOrCreate(['id' => 1])->refresh();
        } catch (Throwable) {
            return new static(['enabled' => false]);
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
}
