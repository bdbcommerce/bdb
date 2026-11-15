<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvoiceParserSetting;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Admin → Invoice parser → Settings. */
class InvoiceParserSettingController extends Controller
{
    public function edit()
    {
        return view('admin.invoice-parser.settings', [
            'settings' => InvoiceParserSetting::current(),
            'models' => InvoiceParserSetting::MODELS,
            'defaultPrompt' => InvoiceParserSetting::defaultPrompt(),
            'telegramReady' => app(TelegramApi::class)->configured(),
        ]);
    }

    public function update(Request $request)
    {
        $chatId = ['nullable', 'string', 'max:64', 'regex:/^-?\d+$/'];
        $data = $request->validate([
            'enabled' => ['boolean'],
            'remove_key' => ['boolean'],
            'model' => ['required', Rule::in(array_keys(InvoiceParserSetting::MODELS))],
            'source_chat_id' => $chatId,
            'source_from_ids' => ['nullable', 'string', 'max:1000', 'regex:/^[\s,;]*(-?\d{1,20}([\s,;]+-?\d{1,20})*)?[\s,;]*$/'],
            'target_chat_id' => [...$chatId, 'different:source_chat_id'],
            'json_chat_id' => [...$chatId, 'different:source_chat_id'],
            'prompt' => ['nullable', 'string', 'max:20000'],
            'notify_skipped' => ['boolean'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:5000'],
        ], [
            'source_chat_id.regex' => 'A chat ID is a number, e.g. -1001234567890 for a group.',
            'target_chat_id.regex' => 'A chat ID is a number, e.g. -1001234567890 for a group.',
            'target_chat_id.different' => 'The invoice chat must differ from the source chat.',
            'json_chat_id.regex' => 'A chat ID is a number, e.g. -1001234567890 for a group.',
            'json_chat_id.different' => 'The JSON chat must differ from the source chat.',
            'source_from_ids.regex' => 'From IDs are numbers separated by commas, e.g. 123456789, 987654321.',
        ]);

        $settings = InvoiceParserSetting::current();
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'model' => $data['model'],
            'source_chat_id' => $data['source_chat_id'] ?? null,
            'source_from_ids' => implode(', ', InvoiceParserSetting::parseIds($data['source_from_ids'] ?? null)) ?: null,
            'target_chat_id' => $data['target_chat_id'] ?? null,
            'json_chat_id' => $data['json_chat_id'] ?? null,
            // Same text as the built-in prompt: store nothing, so later improvements to it apply.
            'prompt' => $prompt === '' || $prompt === trim(InvoiceParserSetting::defaultPrompt()) ? null : $prompt,
            'notify_skipped' => $request->boolean('notify_skipped'),
            'daily_limit' => (int) $data['daily_limit'],
        ]);
        // The key lives in .env (ANTHROPIC_API_KEY); an older key saved here can only be removed.
        if ($request->boolean('remove_key')) {
            $settings->api_key = null;
        }

        if ($settings->enabled && ! ($settings->apiKey() && $settings->source_chat_id && $settings->hasResultChat())) {
            return back()->withInput($request->except('api_key'))->withErrors(['enabled' => 'To switch it on, set ANTHROPIC_API_KEY in .env, the source chat ID and the invoice chat or the JSON chat.']);
        }
        $settings->save();

        return back()->with('success', 'Invoice parser settings saved.');
    }

    /** One tiny Claude request with the saved key and model. */
    public function test(ClaudeInvoiceReader $reader)
    {
        $settings = InvoiceParserSetting::current();
        if (! $settings->apiKey()) {
            return back()->withErrors(['api_key' => 'Set ANTHROPIC_API_KEY in .env first.']);
        }

        try {
            $model = $reader->ping($settings->apiKey(), $settings->modelId());
        } catch (Throwable $e) {
            return back()->withErrors(['api_key' => 'Claude API check failed — '.str_replace((string) $settings->apiKey(), '***', $e->getMessage())]);
        }

        return back()->with('success', "Claude API works (model {$model}).");
    }
}
