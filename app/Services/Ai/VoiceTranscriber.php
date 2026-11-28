<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\VoiceTranscription;
use App\Services\AiErrorAlerts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Speech to text. NeuronAI (POST {url}/stt/transcribe, key NEURONAI_API_KEY) is the transcriber; while that key
 * is empty the older Groq Whisper (OpenAI-compatible /audio/transcriptions) is used: GROQ_API_KEY, else the key
 * of any Groq model in Admin → AI settings.
 */
class VoiceTranscriber
{
    public const ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';

    /** Whisper models Groq serves; large-v3 is the most accurate for Uzbek. */
    public const MODELS = ['whisper-large-v3', 'whisper-large-v3-turbo'];

    /** The `language` values NeuronAI accepts. */
    public const NEURONAI_LANGUAGES = ['auto', 'uz', 'kaa', 'ru', 'en', 'kk', 'tk', 'tg', 'tr', 'az', 'ja', 'de', 'ko'];

    public function __construct(private AiErrorAlerts $alerts) {}

    /** neuronai | groq */
    public function provider(): string
    {
        return filled(config('services.neuronai.key')) ? 'neuronai' : 'groq';
    }

    /**
     * NeuronAI bills every second of audio. Callers then send only what they could measure themselves — Telegram
     * voice (Ogg Opus, see oggSeconds) from registered customers — never a file whose length only the sender states.
     */
    public function paid(): bool
    {
        return $this->provider() === 'neuronai';
    }

    public function providerName(): string
    {
        return $this->provider() === 'neuronai' ? 'NeuronAI' : 'Groq Whisper';
    }

    public function key(): ?string
    {
        if (filled($key = config('services.neuronai.key'))) {
            return $key;
        }
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
        if ($this->provider() === 'neuronai') {
            return 'neuronai-stt';
        }
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

    /** What the last transcribe() call learned besides the text: language, cost, request_id, error. */
    private array $last = [];

    /**
     * transcribe() for a Telegram voice message, kept in `voice_transcriptions`: the Telegram file ID with the
     * text that came back (or why none did).
     *
     * @param  array{file_id?: mixed, file_unique_id?: mixed, telegram_id?: mixed, message_id?: mixed, source?: string}  $voice
     * @return array{text: string|null, seconds: float|null}|null
     */
    public function transcribeVoice(array $voice, string $audio, string $filename = 'voice.ogg'): ?array
    {
        $this->last = [];
        $result = $this->transcribe($audio, $filename);

        if (is_string($voice['file_id'] ?? null) && $voice['file_id'] !== '') {
            try {
                $short = fn ($value, int $max) => is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $max) : null;
                VoiceTranscription::create([
                    'file_id' => mb_substr($voice['file_id'], 0, 255),
                    'file_unique_id' => $short($voice['file_unique_id'] ?? null, 64),
                    'telegram_id' => $short($voice['telegram_id'] ?? null, 32),
                    'message_id' => is_numeric($voice['message_id'] ?? null) ? (int) $voice['message_id'] : null,
                    'source' => $short($voice['source'] ?? null, 20) ?? 'bot',
                    'provider' => $this->provider(),
                    'status' => $result === null ? 'failed' : ($result['text'] === null ? 'empty' : 'ok'),
                    'text' => $result['text'] ?? null,
                    'language' => $short($this->last['language'] ?? null, 10),
                    'seconds' => $result['seconds'] ?? null,
                    'cost' => is_numeric($this->last['cost'] ?? null) ? (float) $this->last['cost'] : null,
                    'request_id' => $short($this->last['request_id'] ?? null, 64),
                    'error' => $result === null ? $short($this->last['error'] ?? 'failed', 255) : null,
                ]);
            } catch (Throwable $e) {
                Log::warning('Voice transcription: not saved', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }

        return $result;
    }

    /**
     * @return array{text: string|null, seconds: float|null}|null null when the provider failed (admins alerted); text null for
     *                                                            silence; seconds = the audio length the provider measured, counted against the daily budget
     */
    public function transcribe(string $audio, string $filename = 'voice.ogg'): ?array
    {
        $key = $this->key();
        if (! $key) {
            $this->last = ['error' => 'no API key'];
            $this->alerts->report('Voice transcription: no API key', 'Set NEURONAI_API_KEY in .env (a key with the stt:write permission from my.neuronai.uz → API keys).', 'Telegram bot');

            return null;
        }
        $neuron = $this->provider() === 'neuronai';
        $name = $neuron ? 'NeuronAI' : 'Groq';

        try {
            // No redirects: a 307 would re-post the customer's recording to another address.
            $request = Http::withToken($key)->timeout(30)->connectTimeout(10)->withoutRedirecting()->acceptJson()->attach('file', $audio, $filename);
            $response = $neuron
                ? $request->post(config('services.neuronai.url').'/stt/transcribe', ['language' => $this->language()])
                : $request->post(self::ENDPOINT, ['model' => $this->model(), 'response_format' => 'verbose_json', 'temperature' => '0']);
        } catch (Throwable $e) {
            $this->fail("Voice transcription: {$name} did not respond", $e->getMessage(), $key);

            return null;
        }

        if ($response->failed()) {
            // The code is the provider's text: only a plain short one is shown (the reason is also the alert's mute key).
            $code = $response->json('error.code');
            $code = $neuron && is_string($code) && preg_match('/\A[a-z0-9_]{1,40}\z/', $code) ? " ({$code})" : '';
            $message = $response->json('error.message');
            $this->fail("Voice transcription: {$name} HTTP {$response->status()}{$code}", is_string($message) ? $message : mb_substr($response->body(), 0, 500), $key);

            return null;
        }

        // A 200 that is not the answer (an error object, a login or proxy page): a failure, not silence.
        if ($neuron && ! is_array($response->json('data'))) {
            $message = $response->json('error.message');
            $this->fail('Voice transcription: NeuronAI sent an answer without data', is_string($message) ? $message : mb_substr($response->body(), 0, 500), $key);

            return null;
        }

        // NeuronAI: {"data": {"text", "duration_seconds", …}}; Groq: {"text", "duration"}.
        $text = $response->json($neuron ? 'data.text' : 'text');
        $text = is_string($text) ? trim(preg_replace('/\s+/u', ' ', $text) ?? '') : '';

        $seconds = $response->json($neuron ? 'data.duration_seconds' : 'duration');
        $this->last = $neuron
            ? ['language' => $response->json('data.language'), 'cost' => $response->json('data.cost'), 'request_id' => $response->json('request_id')]
            : ['language' => $response->json('language')];

        return ['text' => $text !== '' ? mb_substr($text, 0, 3000) : null, 'seconds' => is_numeric($seconds) ? (float) $seconds : null];
    }

    /** The language sent to NeuronAI: NEURONAI_LANGUAGE when it is one NeuronAI knows, else auto. */
    public function language(): string
    {
        $language = strtolower(trim((string) config('services.neuronai.language')));

        return in_array($language, self::NEURONAI_LANGUAGES, true) ? $language : 'auto';
    }

    private function fail(string $reason, string $details, string $key): void
    {
        $details = AiErrorAlerts::withoutSecrets($details, $key, config('services.groq.key'), config('services.neuronai.key'));
        $reason = AiErrorAlerts::withoutSecrets($reason, $key, config('services.groq.key'), config('services.neuronai.key'));
        $this->last = ['error' => preg_replace('/^Voice transcription: /', '', $reason)];
        Log::warning($reason, ['error' => mb_substr($details, 0, 500)]);
        $this->alerts->report($reason, $details, 'Telegram bot', model: $this->model());
    }
}
