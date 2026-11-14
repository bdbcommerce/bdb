<?php

namespace App\Services\Invoices;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Services\Ai\AiClient;
use App\Services\Telegram\TelegramApi;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Invoice parser: photos posted in the source chat are read by Claude; accepted invoices are
 * posted to the target chat as a summary in Uzbek. Admin → Invoice parser.
 *
 * capture() runs inside the Telegram webhook (already deferred until after the response),
 * process() reads the photo right away; `invoices:process` (every minute) retries what failed.
 */
class InvoiceParser
{
    /** Telegram's limit for one message. */
    private const TELEGRAM_MAX = 4096;

    public function __construct(private TelegramApi $telegram, private ClaudeInvoiceReader $reader) {}

    /** Is this message from the invoice source chat? Then it belongs to the parser (true), whatever it contains. */
    public function handles(array $message): bool
    {
        $chatId = $message['chat']['id'] ?? null;
        if (! is_scalar($chatId)) {
            return false;
        }
        $settings = InvoiceParserSetting::current();

        return $settings->enabled && filled($settings->source_chat_id) && (string) $chatId === trim((string) $settings->source_chat_id);
    }

    /** Store a photo / image file / PDF from the source chat; text and other messages are ignored. */
    public function capture(array $message): ?InvoiceMessage
    {
        [$fileId, $mime] = $this->file($message);
        if (! $fileId || ! is_numeric($message['message_id'] ?? null)) {
            return null;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $str = fn ($v) => is_string($v) ? $v : '';
        $sender = trim($str($from['first_name'] ?? null).' '.$str($from['last_name'] ?? null)) ?: ($str($from['username'] ?? null) ?: null);

        try {
            return InvoiceMessage::create([
                'chat_id' => (string) $message['chat']['id'],
                'message_id' => (int) $message['message_id'],
                'file_id' => $fileId,
                'mime' => $mime,
                'sender' => is_string($sender) ? mb_substr($sender, 0, 255) : null,
                'caption' => is_string($message['caption'] ?? null) ? mb_substr($message['caption'], 0, 1024) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // Telegram re-delivered the same message
        }
    }

    /** Read one stored photo with Claude and post the result. Safe to call twice: a lock and the status guard it. */
    public function process(InvoiceMessage $invoice): void
    {
        $lock = Cache::lock("invoice-message:{$invoice->id}", 300);
        if (! $lock->get()) {
            return;
        }

        try {
            $invoice->refresh();
            if (in_array($invoice->status, ['sent', 'skipped'], true)) {
                return;
            }
            if ($invoice->attempts >= InvoiceMessage::MAX_ATTEMPTS) {
                if ($invoice->status !== 'failed') { // e.g. the last attempt crashed mid-way
                    $invoice->update(['status' => 'failed', 'error' => $invoice->error ?: 'The last attempt did not finish.']);
                }

                return;
            }
            @set_time_limit(300);

            $settings = InvoiceParserSetting::current();
            if (! $settings->apiKey() || blank($settings->target_chat_id)) {
                $invoice->update(['status' => 'failed', 'error' => 'Invoice parser is not set up: Claude API key or destination chat missing.']);

                return;
            }

            // Already read, only posting failed: post again without paying for another read.
            if (($invoice->result['status'] ?? null) === 'accepted') {
                $invoice->update(['status' => 'processing', 'attempts' => $invoice->attempts + 1, 'error' => null]);
                $this->post($invoice, $settings, $invoice->result, []);

                return;
            }

            // Spend cap: anyone who can post in the source chat triggers paid reads.
            $adminRetry = app()->bound('invoice-parser.skip-limit') && app('invoice-parser.skip-limit') === $invoice->id;
            if (! $adminRetry && $this->readsToday() >= $settings->dailyLimit()) {
                $this->fail($invoice, "Daily limit of {$settings->dailyLimit()} reads reached (Settings). Use “Read again” to read it anyway.", false);

                return;
            }

            $invoice->update(['status' => 'processing', 'attempts' => $invoice->attempts + 1, 'error' => null]);

            $file = $this->telegram->downloadFile($invoice->file_id);
            if (! $file) {
                $this->fail($invoice, 'Could not download the photo from Telegram.');

                return;
            }
            $mime = $this->mime($invoice->mime, $file['type'], $file['body']);
            if (! $mime) {
                $invoice->update(['status' => 'skipped', 'result' => ['status' => 'skipped', 'reason' => 'unsupported file type'], 'processed_at' => now()]);

                return;
            }
            $invoice->update(['mime' => $mime]);

            try {
                $reply = $this->reader->read($settings->apiKey(), $settings->modelId(), $settings->systemPrompt(), $file['body'], $mime);
            } catch (InvoiceReadException $e) {
                $this->fail($invoice, $e->getMessage(), $e->retryable);

                return;
            }

            $usage = ['model' => $reply['model'], 'input_tokens' => $reply['input_tokens'], 'output_tokens' => $reply['output_tokens'], 'read_at' => now()];
            $data = AiClient::decodeJson($reply['text']);
            if (! is_array($data) || ! in_array($data['status'] ?? null, ['accepted', 'skipped'], true)) {
                $this->fail($invoice, 'Claude did not return the expected JSON: '.mb_strimwidth($reply['text'], 0, 300, '…'), true, $usage);

                return;
            }
            $data = self::clean($data);

            if ($data['status'] === 'skipped') {
                $invoice->update(['status' => 'skipped', 'result' => $data, 'processed_at' => now()] + $usage);
                if ($settings->notify_skipped) {
                    // <code>: Telegram makes no links or @mentions out of text read from the image.
                    $this->telegram->sendMessage($settings->target_chat_id, '⏭ Hisob-faktura emas: <code>'.e((string) ($data['reason'] ?? '')).'</code>',
                        ['parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]);
                }

                return;
            }

            $this->post($invoice, $settings, $data, $usage);
        } catch (Throwable $e) {
            report($e);
            $this->fail($invoice, 'Unexpected error: '.mb_strimwidth(str_replace((string) InvoiceParserSetting::current()->apiKey(), '***', $e->getMessage()), 0, 300, '…'), true);
        } finally {
            $lock->release();
        }
    }

    /** Post an accepted invoice to the destination chat; on failure the read result is kept, so a retry only posts. */
    private function post(InvoiceMessage $invoice, InvoiceParserSetting $settings, array $data, array $usage): void
    {
        $sent = $this->telegram->call('sendMessage', [
            'chat_id' => $settings->target_chat_id,
            'text' => self::summary($data),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
        if (! $sent) {
            $this->fail($invoice, 'Read OK, but posting to the destination chat failed (is the bot a member there?).', true, $usage + ['result' => $data]);

            return;
        }

        $invoice->update(['status' => 'sent', 'result' => $data, 'error' => null, 'sent_message_id' => $sent['message_id'] ?? null, 'processed_at' => now()] + $usage);
    }

    /** Paid Claude reads made today (for the daily limit). */
    private function readsToday(): int
    {
        return InvoiceMessage::where('read_at', '>=', today())->count();
    }

    /**
     * Claude's JSON in a fixed shape: text fields as short strings, numbers as numbers, at most 500 rows.
     * Anything else (arrays where text belongs, huge strings from text in the image) is dropped or cut,
     * so the admin pages and the Telegram summary can rely on the types.
     */
    public static function clean(array $data): array
    {
        $text = fn ($v, int $max = 200) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $num = fn ($v) => is_numeric($v) ? $v + 0 : null;
        $party = fn ($p, array $keys) => collect($keys)->mapWithKeys(fn ($k) => [$k => $text(is_array($p) ? ($p[$k] ?? null) : null)])->all();

        if (($data['status'] ?? null) !== 'accepted') {
            return ['status' => 'skipped', 'reason' => $text($data['reason'] ?? null, 200)];
        }

        $items = [];
        foreach (array_slice(array_values(array_filter(is_array($data['items'] ?? null) ? $data['items'] : [], 'is_array')), 0, 500) as $item) {
            $items[] = [
                'name' => $text($item['name'] ?? null, 300), 'unit' => $text($item['unit'] ?? null, 30),
                'quantity' => $num($item['quantity'] ?? null), 'price' => $num($item['price'] ?? null), 'amount' => $num($item['amount'] ?? null),
                'vat_rate' => $num($item['vat_rate'] ?? null), 'vat_amount' => $num($item['vat_amount'] ?? null), 'total_with_vat' => $num($item['total_with_vat'] ?? null),
            ];
        }

        return array_filter([
            'status' => 'accepted',
            'invoice_number' => $text($data['invoice_number'] ?? null, 60),
            'invoice_date' => $text($data['invoice_date'] ?? null, 30),
            'supplier' => $party($data['supplier'] ?? null, ['name', 'inn', 'vat_code']),
            'buyer' => $party($data['buyer'] ?? null, ['name', 'inn']),
            'items' => $items,
            'total_amount' => $num($data['total_amount'] ?? null),
            'total_vat' => $num($data['total_vat'] ?? null),
            'total_with_vat' => $num($data['total_with_vat'] ?? null),
            'amount_in_words' => $text($data['amount_in_words'] ?? null, 300),
            'warning' => $text($data['warning'] ?? null, 300),
        ], fn ($v) => $v !== null);
    }

    private function fail(InvoiceMessage $invoice, string $error, bool $retryable = true, array $extra = []): void
    {
        $invoice->update(['status' => 'failed', 'error' => $error] + $extra
            // Not worth retrying (bad request, refusal, wrong key): use up the attempts so the scheduler leaves it.
            + ($retryable ? [] : ['attempts' => InvoiceMessage::MAX_ATTEMPTS]));
    }

    /** [file_id, mime] of the largest photo size, an image document or a PDF; [null, null] otherwise. */
    private function file(array $message): array
    {
        if (is_array($message['photo'] ?? null) && $message['photo']) {
            $sizes = array_filter($message['photo'], fn ($p) => is_array($p) && is_string($p['file_id'] ?? null));
            usort($sizes, fn ($a, $b) => ($b['file_size'] ?? $b['width'] ?? 0) <=> ($a['file_size'] ?? $a['width'] ?? 0));

            return $sizes ? [$sizes[0]['file_id'], 'image/jpeg'] : [null, null];
        }

        $doc = $message['document'] ?? null;
        $mime = is_array($doc) ? strtolower((string) ($doc['mime_type'] ?? '')) : '';
        if (is_string($doc['file_id'] ?? null) && (in_array($mime, ClaudeInvoiceReader::IMAGE_TYPES, true) || $mime === 'application/pdf')) {
            return [$doc['file_id'], $mime];
        }

        return [null, null];
    }

    /** The real type from the file's first bytes (Telegram's header is often application/octet-stream). */
    private function mime(?string $declared, ?string $header, string $body): ?string
    {
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($body, 0, 4096)) ?: '';
        foreach ([$sniffed, $header, $declared] as $type) {
            $type = strtolower(trim(explode(';', (string) $type)[0]));
            if (in_array($type, ClaudeInvoiceReader::IMAGE_TYPES, true) || $type === 'application/pdf') {
                return $type;
            }
        }

        return null;
    }

    /** The Telegram message (HTML) for an accepted invoice, in Uzbek. */
    public static function summary(array $data): string
    {
        $data = self::clean($data); // every text field is a short string from here on
        $t = fn ($v) => e(trim((string) (is_scalar($v) ? $v : '')));
        $n = fn ($v) => is_numeric($v) ? self::number((float) $v) : $t($v);

        $date = $data['invoice_date'] ?? null;
        if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            $date = "{$m[3]}.{$m[2]}.{$m[1]}";
        }
        $party = function ($p) use ($t) {
            $p = is_array($p) ? $p : [];
            $name = $t($p['name'] ?? '') ?: '—';

            return $name.(filled($p['inn'] ?? null) ? ' (STIR '.$t($p['inn']).')' : '');
        };

        $head = ['🧾 <b>Hisob-faktura № '.($t($data['invoice_number'] ?? '') ?: '—').'</b>'.($date ? ' — '.$t($date) : '')];
        $head[] = '<b>Yetkazib beruvchi:</b> '.$party($data['supplier'] ?? null);
        $head[] = '<b>Xaridor:</b> '.$party($data['buyer'] ?? null);

        $rows = [];
        foreach (array_values(array_filter((array) ($data['items'] ?? []), 'is_array')) as $i => $item) {
            $qty = $n($item['quantity'] ?? '').(filled($item['unit'] ?? null) ? ' '.$t($item['unit']) : '');
            $rows[] = ($i + 1).'. '.($t($item['name'] ?? '') ?: '—').' — '.$qty.' × '.$n($item['price'] ?? '').' = '.$n($item['amount'] ?? '');
        }

        $foot = [];
        if (is_numeric($data['total_amount'] ?? null)) {
            $foot[] = 'Summa (QQSsiz): '.$n($data['total_amount']);
        }
        if (is_numeric($data['total_vat'] ?? null)) {
            $foot[] = 'QQS: '.$n($data['total_vat']);
        }
        $foot[] = '<b>Jami: '.(is_numeric($data['total_with_vat'] ?? null) ? $n($data['total_with_vat']) : '—').' soʻm</b>';
        if (filled($data['warning'] ?? null)) {
            // <code>: text read from the image never becomes a live link or @mention here.
            $foot[] = '⚠️ Ogohlantirish: <code>'.$t($data['warning']).'</code>';
        }
        $foot[] = '<i>AI oʻqidi — asl hujjat bilan tekshiring.</i>';

        // Keep within Telegram's limit: drop rows from the end and say how many are left out.
        $build = fn (array $r, int $hidden) => implode("\n", $head)."\n\n"
            .implode("\n", $r).($hidden ? "\n… yana {$hidden} ta qator" : '')
            ."\n\n".implode("\n", $foot);
        $shown = $rows;
        while (mb_strlen($build($shown, count($rows) - count($shown))) > self::TELEGRAM_MAX - 50 && $shown) {
            array_pop($shown);
        }

        return $build($shown, count($rows) - count($shown));
    }

    /** 8248000 → "8 248 000", 1502820.5 → "1 502 820,50". */
    private static function number(float $v): string
    {
        $decimals = abs($v - round($v)) < 0.005 ? 0 : 2;

        return number_format($v, $decimals, ',', ' ');
    }
}
