<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\AiErrorAlerts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Speech to text with Groq Whisper (OpenAI-compatible /audio/transcriptions).
 * The key is GROQ_API_KEY, else the key of any Groq model in Admin → AI settings.
 */
class VoiceTranscriber
{
    public const ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';

    /** Whisper models Groq serves; large-v3 is the most accurate for Uzbek. */
    public const MODELS = ['whisper-large-v3', 'whisper-large-v3-turbo'];

    public function __construct(private AiErrorAlerts $alerts) {}

    public function key(): ?string
    {
        if (filled($key = config('services.groq.key'))) {
            return $key;
        }

        $models = AiModel::where('base_url', 'like', '%api.groq.com%')->whereNotNull('api_key')
            ->orderByDesc('is_active')->orderBy('priority')->get();
        foreach ($models as $model) {
            if (strtolower((string) parse_url((string) $model->base_url, PHP_URL_HOST)) !== 'api.groq.com') {
                continue;
            }
            try {
                if (filled($key = $model->api_key)) {
                    return $key;
                }
            } catch (Throwable) {
                // This key no longer decrypts (APP_KEY changed): try the next one.
            }
        }

        return null;
    }

    /**
     * The real length of an Ogg Opus file (Telegram voice), from the granule position of its last page
     * (48 kHz), or null for anything else. Telegram's "duration" is set by the sender's app.
     */
    public static function oggSeconds(string $audio): ?float
    {
        if (! str_starts_with($audio, 'OggS') || ! str_contains(substr($audio, 0, 512), 'OpusHead')) {
            return null;
        }
        // Walk the pages from the start: each must follow the previous one exactly, up to the end of the file.
        $length = strlen($audio);
        $pos = 0;
        $granule = 0;
        while ($pos < $length) {
            if (substr($audio, $pos, 4) !== 'OggS' || $pos + 27 > $length) {
                return null;
            }
            $segments = ord($audio[$pos + 26]);
            if ($pos + 27 + $segments > $length) {
                return null;
            }
            $body = array_sum(array_map('ord', str_split(substr($audio, $pos + 27, $segments)) ?: []));
            $page = unpack('P', substr($audio, $pos + 6, 8))[1];
            if ($page > 0) {
                $granule = max($granule, $page);
            }
            $pos += 27 + $segments + $body;
        }

        return $pos === $length && $granule > 0 ? $granule / 48000 : null;
    }

    public function available(): bool
    {
        return filled($this->key());
    }

    public function model(): string
    {
        $model = (string) config('services.groq.transcribe_model');

        return in_array($model, self::MODELS, true) ? $model : self::MODELS[0];
    }

    /** Groq picks the decoder from the file name: Telegram voice is ".oga" (Ogg Opus), which Groq knows as ".ogg". */
    public static function filename(?string $name, string $mime = ''): ?string
    {
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = match (strtolower(trim(explode(';', $mime)[0]))) {
                '', 'audio/ogg', 'audio/opus', 'application/ogg' => 'ogg',
                'audio/mpeg', 'audio/mp3' => 'mp3',
                'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
                'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
                'audio/flac', 'audio/x-flac' => 'flac',
                'audio/webm' => 'webm',
                default => 'unsupported',
            };
        }
        $ext = match ($ext) {
            'oga', 'opus' => 'ogg',
            'flac', 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'ogg', 'wav', 'webm' => $ext,
            default => null, // e.g. .aac, .amr, .wma: Whisper on Groq can't read them
        };

        return $ext === null ? null : ($ext === 'ogg' ? 'voice' : 'audio').'.'.$ext;
    }

    /** Text Whisper is known to invent for silent or noise-only clips (subtitle credits, "thanks for watching"). */
    public static function isHallucination(string $text): bool
    {
        return (bool) preg_match('/^(?:(?:редактор )?субтитр.*|продолжение следует|спасибо за просмотр|thank you(?: for watching)?|thanks for watching|you)[\s\p{P}]*$/iu', $text)
            && mb_strlen($text) < 80;
    }

    /** Report a voice file that could not be fetched from Telegram (muted repeats, like the AI alerts). */
    public function downloadFailed(): void
    {
        Log::warning('Voice transcription: download from Telegram failed');
        $this->alerts->report('Voice transcription: could not download a voice message from Telegram', 'getFile or the download failed, or the file was over the size limit.', 'Telegram bot');
    }

    /**
     * @return array{text: string|null, seconds: float|null}|null null when Groq failed (admins alerted); text null for
     *                                                            silence; seconds = the audio length Groq measured (verbose_json), counted against the daily budget
     */
    public function transcribe(string $audio, string $filename = 'voice.ogg'): ?array
    {
        $key = $this->key();
        if (! $key) {
            $this->alerts->report('Voice transcription: no Groq API key', 'Add a Groq key in Admin → AI settings (any Groq model) or GROQ_API_KEY in .env.', 'Telegram bot');

            return null;
        }

        try {
            $response = Http::withToken($key)->timeout(30)
                ->attach('file', $audio, $filename)
                ->post(self::ENDPOINT, ['model' => $this->model(), 'response_format' => 'verbose_json', 'temperature' => '0']);
        } catch (Throwable $e) {
            $this->fail('Voice transcription: Groq did not respond', $e->getMessage(), $key);

            return null;
        }

        if ($response->failed()) {
            $this->fail("Voice transcription: Groq HTTP {$response->status()}", (string) $response->json('error.message', $response->body()), $key);

            return null;
        }

        $text = $response->json('text');
        $text = is_string($text) ? trim(preg_replace('/\s+/u', ' ', $text) ?? '') : '';

        $seconds = $response->json('duration');

        return ['text' => $text !== '' ? mb_substr($text, 0, 3000) : null, 'seconds' => is_numeric($seconds) ? (float) $seconds : null];
    }

    private function fail(string $reason, string $details, string $key): void
    {
        $details = AiErrorAlerts::withoutSecrets($details, $key, config('services.groq.key'));
        Log::warning($reason, ['error' => mb_substr($details, 0, 500)]);
        $this->alerts->report($reason, $details, 'Telegram bot', model: $this->model());
    }
}
