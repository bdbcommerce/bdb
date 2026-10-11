<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\AiCheckCommand;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Services\AiErrorAlerts;
use App\Services\ChatAssistant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AI settings: the models the assistant uses, per task and by priority, with free presets.
 */
class AiModelController extends Controller
{
    public function index(AiErrorAlerts $alerts)
    {
        return view('admin.ai-models.index', [
            'models' => $models = AiModel::orderBy('priority')->orderBy('id')->get(),
            // Presets not added yet.
            'presets' => array_filter(AiModel::PRESETS, fn ($p) => ! $models->contains(fn ($m) => $m->provider === $p['provider'] && $m->model === $p['model'])),
            'tasks' => AiModel::TASKS,
            'providers' => AiModel::PROVIDERS,
            'env' => ['model' => config('services.gemini.model'), 'key' => filled(config('services.gemini.key'))],
            'alertChat' => $alerts->chatId() && filled(config('services.telegram.bot_token')) ? $alerts->chatId() : null,
        ]);
    }

    /** ?preset=groq-llama-70b pre-fills the form with a free model. */
    public function create(Request $request)
    {
        $preset = is_string($key = $request->query('preset')) ? (AiModel::PRESETS[$key] ?? null) : null;
        $model = new AiModel([
            'name' => $preset['name'] ?? '',
            'provider' => $preset['provider'] ?? 'openai',
            'model' => $preset['model'] ?? '',
            'base_url' => $preset['base_url'] ?? null,
            'tasks' => array_keys(AiModel::TASKS),
            'priority' => $preset['priority'] ?? (AiModel::max('priority') ?? 0) + 10,
            'is_active' => true,
        ]);

        return $this->form($model, $preset);
    }

    public function store(Request $request)
    {
        $model = AiModel::create($this->validated($request));

        return redirect()->route('admin.ai-models.index')
            ->with('success', "{$model->name} added. Use “Test” to check that it answers.");
    }

    public function edit(AiModel $aiModel)
    {
        return $this->form($aiModel, null);
    }

    public function update(Request $request, AiModel $aiModel)
    {
        $data = $this->validated($request, $aiModel);
        $aiModel->update($data + ($request->boolean('remove_key') ? ['api_key' => null] : []));

        return redirect()->route('admin.ai-models.index')->with('success', "{$aiModel->name} saved.");
    }

    public function destroy(AiModel $aiModel)
    {
        $aiModel->delete();

        return redirect()->route('admin.ai-models.index')->with('success', 'Model removed.');
    }

    /** One real request to this model only. */
    public function test(AiModel $aiModel, ChatAssistant $assistant)
    {
        $error = $assistant->testModel($aiModel);

        return redirect()->route('admin.ai-models.index')->with(...($error === null
            ? ['success', "{$aiModel->name} works."]
            : ['ai_error', "{$aiModel->name} failed: {$error}".PHP_EOL.'What to do: '.AiCheckCommand::hint($error)]));
    }

    /** Use a paused model again right away. */
    public function resume(AiModel $aiModel)
    {
        $aiModel->forceFill(['cooldown_until' => null])->save();

        return redirect()->route('admin.ai-models.index')->with('success', "{$aiModel->name} is used again.");
    }

    private function form(AiModel $model, ?array $preset)
    {
        return view('admin.ai-models.form', [
            'model' => $model,
            'preset' => $preset ?? $model->preset(),
            'tasks' => AiModel::TASKS,
            'providers' => AiModel::PROVIDERS,
            'envKey' => filled(config('services.gemini.key')),
        ]);
    }

    private function validated(Request $request, ?AiModel $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'provider' => ['required', Rule::in(array_keys(AiModel::PROVIDERS))],
            'model' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9._:\/@-]+$/'],
            'base_url' => ['nullable', 'required_if:provider,openai', 'url:http,https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*' => [Rule::in(array_keys(AiModel::TASKS))],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ], [
            'model.regex' => 'The model name may contain letters, digits and . _ : / @ - only (e.g. llama-3.3-70b-versatile).',
            'tasks.required' => 'Choose at least one job for this model.',
        ]);

        // A blank key keeps the saved one; Gemini models may use GEMINI_API_KEY from .env (optional).
        $hasKey = filled($data['api_key'] ?? null)
            || ($existing && filled($existing->api_key) && ! $request->boolean('remove_key'))
            || ($data['provider'] === 'gemini' && filled(config('services.gemini.key')));
        if ($request->boolean('is_active') && ! $hasKey) {
            throw ValidationException::withMessages([
                'api_key' => 'Paste the API key to switch this model on (see "How to get the key"), or untick Active.',
            ]);
        }
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }
        if ($data['provider'] === 'gemini') {
            $data['base_url'] = null;
        }

        return [
            'is_active' => $request->boolean('is_active'),
            'tasks' => array_values(array_unique($data['tasks'])),
        ] + $data;
    }
}
