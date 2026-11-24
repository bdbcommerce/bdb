<?php

namespace App\Services;

use App\Models\ChatLead;
use App\Models\JobApplication;
use App\Models\TelegramUser;
use App\Services\Seo\SeoFiles;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Facades\Storage;

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

    /**
     * A new Telegram contact the AI team may not talk to yet: the sales chat is asked (once per person) to set
     * them as a client, supplier or employee. Until then the AI only greets them once.
     */
    public function assignmentNeeded(TelegramUser $user, string $firstText, bool $premium, bool $greeted = false): bool
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $suggestion = $user->suggestedType();
        $lines = [
            '🆕 <b>New contact — please assign</b> ('.($premium ? 'Premium-account chat' : 'Telegram bot').')',
            '',
            '<b>Name:</b> '.$e($user->displayName() ?? $user->telegram_id),
        ];
        if ($user->username) {
            $lines[] = '<b>Telegram:</b> @'.$e($user->username);
        }
        if ($user->phone) {
            $lines[] = '<b>Phone:</b> '.$e($user->phone);
        }
        if (trim($firstText) !== '') {
            $lines[] = '<b>Wrote:</b> '.$e(mb_substr(trim($firstText), 0, 300));
        }
        if ($suggestion) {
            $lines[] = '<b>Suggested:</b> '.$e(TelegramUser::TYPES[$suggestion[0]].' — '.$suggestion[1]);
        }
        $lines[] = '';
        $lines[] = ($greeted ? 'The AI greeted them once and waits.' : 'The AI has not written to them.').' <a href="'.$e(SeoFiles::publicUrl(route('admin.telegram-users.show', $user)).'#assign').'">Assign in admin</a>';

        return $this->send(implode("\n", $lines));
    }

    /**
     * A customer sent a voice message (or audio file) to the bot: it is forwarded to the sales chat with
     * who sent it, so staff can listen and reply from the admin panel. Returns false when not sent.
     */
    public function voiceMessage(TelegramUser $user, int $messageId, ?int $duration = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $chatId = (string) config('services.telegram.chat_id');
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // forwardMessage keeps the original sender; copyMessage is the fallback (e.g. forwarding restricted).
        $sent = $this->api->call('forwardMessage', ['chat_id' => $chatId, 'from_chat_id' => $user->telegram_id, 'message_id' => $messageId])
            ?? $this->api->call('copyMessage', ['chat_id' => $chatId, 'from_chat_id' => $user->telegram_id, 'message_id' => $messageId]);

        $lines = [
            '🎙 <b>Voice message — Telegram bot</b>'.($duration ? ' ('.$duration.' s)' : ''),
            '',
            '<b>From:</b> '.$e($user->displayName() ?? $user->telegram_id),
            '<b>Phone:</b> '.$e($user->phone ?? 'not registered'),
        ];
        if ($user->username) {
            $lines[] = '<b>Telegram:</b> @'.$e($user->username);
        }
        $lines[] = '<b>Language:</b> '.strtoupper($e($user->locale ?? '—'));
        if (! $sent) {
            $lines[] = '<i>The voice could not be forwarded: listen to it in the admin panel.</i>';
        }
        $lines[] = '';
        $lines[] = 'The bot did not answer it. <a href="'.$e(SeoFiles::publicUrl(route('admin.telegram-users.show', $user))).'">Reply in admin</a>';

        $extra = ['parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if (is_numeric($sent['message_id'] ?? null)) {
            $extra['reply_parameters'] = ['message_id' => (int) $sent['message_id'], 'allow_sending_without_reply' => true];
        }

        return $this->api->sendMessage($chatId, implode("\n", $lines), $extra) || $sent !== null;
    }

    /**
     * New CV from the Careers page: the file is sent to the chat with the details as its caption.
     * Returns where it went, or null when the file could not be sent: then the CV stays on the
     * server and, unless $textFallback is false (retries), the details go out as a text message.
     *
     * @return array{file_id: string, chat_id: string, message_id: int|null}|null
     */
    public function newApplication(JobApplication $application, bool $textFallback = true): ?array
    {
        $caption = $this->applicationText($application);

        if ($this->enabled() && $application->hasSafePath() && Storage::disk('local')->exists($application->cv_path)) {
            $chatId = (string) config('services.telegram.chat_id');
            $message = $this->api->sendDocument(
                $chatId,
                Storage::disk('local')->get($application->cv_path),
                $application->cv_name,
                ['caption' => $caption, 'parse_mode' => 'HTML'],
            );
            if ($fileId = $message['document']['file_id'] ?? null) {
                return ['file_id' => $fileId, 'chat_id' => $chatId, 'message_id' => $message['message_id'] ?? null];
            }
        }

        if ($textFallback) {
            $this->send($caption);
        }

        return null;
    }

    /** Remove a CV message from the chat (best effort: Telegram may refuse, e.g. old messages in some chats). */
    public function deleteMessage(string $chatId, int $messageId): bool
    {
        return $this->api->configured()
            && $this->api->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]) !== null;
    }

    private function applicationText(JobApplication $application): string
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

        return implode("\n", $lines);
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
