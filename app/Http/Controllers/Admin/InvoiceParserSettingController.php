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
            'api_key' => ['nullable', 'string', 'max:300', 'regex:/^\S+$/'],
            'remove_key' => ['boolean'],
            'model' => ['required', Rule::in(array_keys(InvoiceParserSetting::MODELS))],
            'source_chat_id' => $chatId,
            'target_chat_id' => [...$chatId, 'different:source_chat_id'],
            'prompt' => ['nullable', 'string', 'max:20000'],
            'notify_skipped' => ['boolean'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:5000'],
        ], [
            'source_chat_id.regex' => 'A chat ID is a number, e.g. -1001234567890 for a group.',
            'target_chat_id.regex' => 'A chat ID is a number, e.g. -1001234567890 for a group.',
            'target_chat_id.different' => 'The destination chat must differ from the source chat.',
        ]);

        $settings = InvoiceParserSetting::current();
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'model' => $data['model'],
            'source_chat_id' => $data['source_chat_id'] ?? null,
            'target_chat_id' => $data['target_chat_id'] ?? null,
            // Same text as the built-in prompt: store nothing, so later improvements to it apply.
            'prompt' => $prompt === '' || $prompt === trim(InvoiceParserSetting::defaultPrompt()) ? null : $prompt,
            'notify_skipped' => $request->boolean('notify_skipped'),
            'daily_limit' => (int) $data['daily_limit'],
        ]);
        if ($request->boolean('remove_key')) {
            $settings->api_key = null;
        } elseif (filled($data['api_key'] ?? null)) {
            $settings->api_key = $data['api_key']; // blank field = keep the saved key
        }

        if ($settings->enabled && ! ($settings->apiKey() && $settings->source_chat_id && $settings->target_chat_id)) {
            return back()->withInput($request->except('api_key'))->withErrors(['enabled' => 'To switch it on, set the Claude API key and both chat IDs.']);
        }
        $settings->save();

        return back()->with('success', 'Invoice parser settings saved.');
    }

    /** One tiny Claude request with the saved key and model. */
    public function test(ClaudeInvoiceReader $reader)
    {
        $settings = InvoiceParserSetting::current();
        if (! $settings->apiKey()) {
            return back()->withErrors(['api_key' => 'Save a Claude API key first.']);
        }

        try {
            $model = $reader->ping($settings->apiKey(), $settings->modelId());
        } catch (Throwable $e) {
            return back()->withErrors(['api_key' => 'Claude API check failed — '.str_replace((string) $settings->apiKey(), '***', $e->getMessage())]);
        }

        return back()->with('success', "Claude API works (model {$model}).");
    }
}
