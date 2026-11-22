<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin Telegram Bot API client. Never throws: a Telegram outage must not
 * break lead capture or the webhook.
 */
class TelegramApi
{
    /** Telegram folders of photos, voice, audio, video and stickers (file_path "photos/file_1.jpg"). */
    public const MEDIA_FOLDERS = ['photos', 'voice', 'music', 'videos', 'video_notes', 'stickers', 'animations', 'thumbnails', 'profile_photos'];

    public const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'oga', 'ogg', 'opus', 'mp3', 'm4a', 'wav', 'flac', 'mp4', 'm4v', 'mov', 'webm', 'tgs'];

    /** Pictures sent "as a file": downloaded only when the bytes really are a picture. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'bmp', 'tif', 'tiff'];

    /** Never downloaded, whoever asks: apps, programs, scripts, archives, web pages, macro documents. */
    public const NEVER = [
        'apk', 'xapk', 'apks', 'apkm', 'aab', 'obb', 'ipa', 'exe', 'msi', 'msix', 'appx', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl',
        'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'mjs', 'wsf', 'wsh', 'hta', 'jar', 'sh', 'bash', 'dll', 'sys', 'lnk', 'app', 'dmg',
        'pkg', 'deb', 'rpm', 'run', 'bin', 'iso', 'img', 'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'cab', 'html', 'htm',
        'xhtml', 'svg', 'php', 'py', 'pl', 'rb', 'docm', 'xlsm', 'pptm',
    ];

    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /** @return array<string, mixed>|null the "result" field, or null on failure */
    public function call(string $method, array $params = [], int $timeout = 10): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::timeout($timeout)->asJson()->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method,
                $params,
            );
        } catch (Throwable $e) {
            // Connection errors quote the request URL, which contains the bot token.
            $error = str_replace((string) config('services.telegram.bot_token'), '***', $e->getMessage());
            Log::warning("Telegram {$method} failed", ['error' => $error]);

            return null;
        }

        if ($response->failed() || ! $response->json('ok')) {
            Log::warning("Telegram {$method} failed", ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return (array) $response->json('result');
    }

    /**
     * Download a file a customer sent. Bots can only fetch files up to 20 MB.
     * The download URL contains the bot token, so it must never reach the browser.
     *
     * Only photos, voice, audio, video and stickers, and pictures sent as a file, are ever downloaded. Other documents
     * only when the caller names their extension in $documents (invoice PDFs, our own CVs); apps such as .apk,
     * programs, archives and web pages never (NEVER). For other callers a picture sent as a file must really be one.
     *
     * @param  list<string>  $documents  extra document extensions this caller accepts ('' = no extension)
     * @return array{body: string, type: string|null, name: string}|null
     */
    public function downloadFile(string $fileId, ?int $maxBytes = null, array $documents = []): ?array
    {
        $info = $this->call('getFile', ['file_id' => $fileId]);
        $path = $info['file_path'] ?? null;
        if (! $path) {
            return null;
        }
        if (! self::mayDownload((string) $path, $documents)) {
            Log::notice('Telegram file not downloaded: this type is never downloaded', ['path' => mb_substr((string) $path, 0, 120)]);

            return null;
        }
        // $maxBytes: refuse before downloading when Telegram's own file_size is larger (checked again after).
        if ($maxBytes !== null && is_numeric($info['file_size'] ?? null) && (int) $info['file_size'] > $maxBytes) {
            return null;
        }

        try {
            $response = Http::timeout(30)->get('https://api.telegram.org/file/bot'.config('services.telegram.bot_token').'/'.$path);
        } catch (Throwable $e) {
            Log::warning('Telegram file download failed', [
                'error' => str_replace((string) config('services.telegram.bot_token'), '***', $e->getMessage()),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Telegram file download failed', ['status' => $response->status()]);

            return null;
        }
        if ($maxBytes !== null && strlen($response->body()) > $maxBytes) {
            return null;
        }
        // Callers that accept documents check the bytes themselves (invoice parser) or sent the file themselves (CVs).
        if ($documents === [] && ! self::bytesMatch((string) $path, $response->body())) {
            Log::notice('Telegram file dropped: its content does not match its name', ['path' => mb_substr((string) $path, 0, 120)]);

            return null;
        }

        return [
            'body' => $response->body(),
            'type' => $response->header('Content-Type') ?: null,
            'name' => basename($path),
        ];
    }

    /** Whether a Telegram file_path may be downloaded at all (see downloadFile). */
    public static function mayDownload(string $path, array $documents = []): bool
    {
        $folder = strtolower(explode('/', $path)[0]);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::NEVER, true) || str_contains($path, '..')) {
            return false;
        }
        if (in_array($folder, self::MEDIA_FOLDERS, true)) {
            return in_array($ext, self::MEDIA_EXTENSIONS, true);
        }

        return $folder === 'documents' && (in_array($ext, self::IMAGE_EXTENSIONS, true) || in_array($ext, $documents, true));
    }

    /** A picture, PDF or extension-less file sent as a document must really be a picture or a PDF. */
    private static function bytesMatch(string $path, string $body): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (strtolower(explode('/', $path)[0]) !== 'documents' || ! in_array($ext, [...self::IMAGE_EXTENSIONS, 'pdf', ''], true)) {
            return true;
        }
        $isPdf = str_starts_with(ltrim(substr($body, 0, 1024)), '%PDF-');
        $isImage = str_starts_with((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body), 'image/');

        return $ext === 'pdf' ? $isPdf : ($ext === '' ? $isPdf || $isImage : $isImage);
    }

    /**
     * Upload a file to a chat (multipart; bots may upload up to 50 MB).
     *
     * @return array<string, mixed>|null the sent message, or null on failure
     */
    public function sendDocument(int|string $chatId, string $contents, string $filename, array $extra = [], int $timeout = 60): ?array
    {
        return $this->upload('sendDocument', 'document', $chatId, $contents, $filename, $extra, $timeout);
    }

    /** Upload a photo (Telegram compresses it; max 10 MB, width + height ≤ 10000). Null on failure. */
    public function sendPhoto(int|string $chatId, string $contents, string $filename, array $extra = [], int $timeout = 60): ?array
    {
        return $this->upload('sendPhoto', 'photo', $chatId, $contents, $filename, $extra, $timeout);
    }

    private function upload(string $method, string $field, int|string $chatId, string $contents, string $filename, array $extra, int $timeout): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        // Multipart fields are strings: nested values (reply_parameters…) go as JSON.
        $extra = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $extra);

        try {
            $response = Http::timeout($timeout)
                ->attach($field, $contents, $filename)
                ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method, ['chat_id' => $chatId] + $extra);
        } catch (Throwable $e) {
            Log::warning("Telegram {$method} failed", [
                'error' => str_replace((string) config('services.telegram.bot_token'), '***', $e->getMessage()),
            ]);

            return null;
        }

        if ($response->failed() || ! $response->json('ok')) {
            Log::warning("Telegram {$method} failed", ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return (array) $response->json('result');
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = [], int $timeout = 10): bool
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra, $timeout) !== null;
    }
}
