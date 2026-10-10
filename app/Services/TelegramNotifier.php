<?php

namespace App\Services;

use App\Models\ChatLead;
use App\Services\Telegram\TelegramApi;

/**
 * Posts new chat leads to the sales team's Telegram chat.
 * Does nothing when TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID are not set.
 */
class TelegramNotifier
{
    public function __construct(private TelegramApi $api) {}

    public function enabled(): bool
    {
        return $this->api->configured() && filled(config('services.telegram.chat_id'));
    }

    public function newLead(ChatLead $lead): void
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '<b>New chat lead — '.($lead->channel === 'telegram' ? 'Telegram bot' : 'BDB website').'</b>',
            '',
            '<b>Name:</b> '.$e($lead->name),
            '<b>Phone:</b> '.$e($lead->phone),
            '<b>Language:</b> '.strtoupper($lead->locale),
        ];
        if ($lead->topic) {
            $lines[] = '<b>Topic:</b> '.$e(mb_substr($lead->topic, 0, 500));
        }
        if ($lead->page_url) {
            $lines[] = '<b>'.($lead->channel === 'telegram' ? 'Telegram' : 'Page').':</b> '.$e($lead->page_url);
        }
        $lines[] = '';
        $lines[] = '<a href="'.$e(route('admin.chat-leads.index').'#lead-'.$lead->id).'">Open in admin</a>';

        $this->send(implode("\n", $lines));
    }

    public function send(string $html): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->api->sendMessage(config('services.telegram.chat_id'), $html, [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }
}
