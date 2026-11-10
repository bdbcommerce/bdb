<?php

namespace App\Services;

use App\Models\ChatLead;
use App\Models\JobApplication;
use App\Services\Seo\SeoFiles;
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
            '<b>New chat lead — '.match ($lead->channel) {
                'telegram' => 'Telegram bot', 'mcp' => 'AI agent (MCP)', default => 'BDB website'
            }.'</b>',
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
        $lines[] = '<a href="'.$e(SeoFiles::publicUrl(route('admin.chat-leads.index')).'#lead-'.$lead->id).'">Open in admin</a>';

        $this->send(implode("\n", $lines));
    }

    /** New CV from the Careers page (the file itself stays on the server; staff open it in the admin). */
    public function newApplication(JobApplication $application): void
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '<b>New CV — Careers page</b>',
            '',
            '<b>Position:</b> '.$e($application->vacancy_title ?: 'General application'),
        ];
        if ($application->department) {
            $lines[] = '<b>Department:</b> '.$e(__('site.careers.departments.'.$application->department, [], 'en'));
        }
        $lines[] = '<b>Name:</b> '.$e($application->name);
        $lines[] = '<b>Phone:</b> '.$e($application->phone);
        if ($application->email) {
            $lines[] = '<b>Email:</b> '.$e($application->email);
        }
        $lines[] = '<b>Language:</b> '.strtoupper($application->locale);
        $lines[] = '';
        $lines[] = '<a href="'.$e(SeoFiles::publicUrl(route('admin.job-applications.show', $application))).'">Open in admin</a>';

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
