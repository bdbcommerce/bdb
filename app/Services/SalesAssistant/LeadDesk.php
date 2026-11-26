<?php

namespace App\Services\SalesAssistant;

use App\Models\ChatLead;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\Seo\SeoFiles;
use App\Services\Telegram\TelegramApi;
use App\Services\TelegramNotifier;

/**
 * Requests in the sales chat: the "I take it" button (callback lead:{id}:take, only in the sales chat — the first
 * manager owns the lead), and the reminder to the main admin when nobody took one in lead_remind_minutes (working time).
 */
class LeadDesk
{
    public function __construct(private TelegramApi $api, private TelegramNotifier $notifier) {}

    /** The inline keyboard under a new request. */
    public static function keyboard(ChatLead $lead): array
    {
        return ['inline_keyboard' => [[['text' => '🙋 I take it', 'callback_data' => 'lead:'.$lead->id.':take']]]];
    }

    /** @return bool whether the callback was ours */
    public function callback(array $query): bool
    {
        $data = is_string($query['data'] ?? null) ? $query['data'] : '';
        if (! preg_match('/^lead:(\d+):(take|taken)$/', $data, $m)) {
            return false;
        }
        $id = is_scalar($query['id'] ?? null) ? (string) $query['id'] : null;
        $chat = (string) ($query['message']['chat']['id'] ?? '');
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];
        // Only in the sales chat (the members are the managers); a forwarded copy elsewhere does nothing.
        if ($chat === '' || $chat !== (string) config('services.telegram.chat_id') || ! is_scalar($from['id'] ?? null)) {
            $id && $this->api->call('answerCallbackQuery', ['callback_query_id' => $id]);

            return true;
        }
        $lead = ChatLead::find((int) $m[1]);
        if (! $lead) {
            $id && $this->api->call('answerCallbackQuery', ['callback_query_id' => $id, 'text' => 'This request no longer exists.']);

            return true;
        }
        $name = mb_substr(trim(implode(' ', array_filter([$from['first_name'] ?? null, $from['last_name'] ?? null]))) ?: ('@'.($from['username'] ?? $from['id'])), 0, 100);
        // The first tap wins (one UPDATE with the condition, so two managers tapping at once cannot both get it).
        $won = ChatLead::whereKey($lead->id)->whereNull('taken_at')
            ->update(['taken_by' => $name, 'taken_by_telegram_id' => (string) $from['id'], 'taken_at' => now()]) === 1;
        $lead->refresh();
        $id && $this->api->call('answerCallbackQuery', ['callback_query_id' => $id,
            'text' => $won ? 'It is yours.' : 'Already taken by '.$lead->taken_by.'.']);
        if ($won && is_numeric($query['message']['message_id'] ?? null)) {
            $this->api->call('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => (int) $query['message']['message_id'],
                'reply_markup' => ['inline_keyboard' => [[['text' => '✅ Taken by '.$name, 'callback_data' => 'lead:'.$lead->id.':taken']]]]]);
        }

        return true;
    }

    /** Requests nobody took or handled in time: one reminder to the main admin (else to the sales chat). */
    public function tick(): int
    {
        $settings = SalesAssistantSetting::current();
        $minutes = $settings->leadRemindMinutes();
        $admin = TelegramUser::where('is_main_admin', true)->whereNotNull('bot_chat_at')->first();
        $sent = 0;
        // Only the assistant's requests (they carry the "I take it" button), not the bot's "Request a call" leads.
        ChatLead::whereNull('taken_at')->whereNull('reminded_at')->where('is_handled', false)->whereIn('channel', ['telegram', 'business'])
            ->whereNotNull('transcript->conversation_id')
            ->where('created_at', '>=', now()->subDays(3))->orderBy('id')->limit(50)->get()
            ->filter(fn (ChatLead $l) => $settings->workingMinutesBetween($l->created_at, now()) >= $minutes)
            ->each(function (ChatLead $lead) use ($admin, $minutes, &$sent) {
                $lead->update(['reminded_at' => now()]);
                $text = '⏰ <b>Request not taken for '.$minutes.' min</b>'."\n".e(mb_strimwidth($lead->name.' — '.(string) $lead->topic, 0, 300, '…'))
                    ."\n".'<a href="'.e(SeoFiles::publicUrl(route('admin.chat-leads.index'))).'">Requests</a>'
                    .($lead->page_url ? ' · <a href="'.e($lead->page_url).'">Open the chat</a>' : '');
                $ok = $admin ? $this->api->sendMessage($admin->telegram_id, $text, ['parse_mode' => 'HTML', 'disable_web_page_preview' => true]) : false;
                $ok || $this->notifier->send($text."\n<i>Nobody tapped \"I take it\" yet.</i>");
                $sent++;
            });

        return $sent;
    }
}
