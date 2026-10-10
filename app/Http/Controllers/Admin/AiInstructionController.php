<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInstruction;
use App\Services\ChatAssistant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Markdown rules and information for the AI assistant, plus a preview of the full prompt.
 */
class AiInstructionController extends Controller
{
    public function index()
    {
        return view('admin.ai-instructions.index', [
            'main' => AiInstruction::main(),
            'instructions' => AiInstruction::where('kind', '!=', AiInstruction::MAIN)->orderBy('kind')->orderBy('sort_order')->orderBy('id')->get(),
            'kinds' => AiInstruction::KINDS,
            'channels' => AiInstruction::CHANNELS,
        ]);
    }

    public function create()
    {
        return $this->form(new AiInstruction(['kind' => 'rules', 'channel' => 'all', 'is_active' => true]));
    }

    public function store(Request $request)
    {
        AiInstruction::create($this->validated($request));

        return redirect()->route('admin.ai-instructions.index')->with('success', 'Saved. The AI uses it from the next question.');
    }

    public function edit(AiInstruction $aiInstruction)
    {
        return $this->form($aiInstruction);
    }

    public function update(Request $request, AiInstruction $aiInstruction)
    {
        // The main prompt only has its text edited; it stays one active, all-channel document.
        $aiInstruction->update($aiInstruction->isMain()
            ? $request->validate(['content' => 'required|string|max:'.AiInstruction::MAX_LENGTH])
            : $this->validated($request));

        return redirect()->route('admin.ai-instructions.index')->with('success', 'Saved. The AI uses it from the next question.');
    }

    public function destroy(AiInstruction $aiInstruction)
    {
        if ($aiInstruction->isMain()) {
            return back()->withErrors(['content' => 'The main prompt cannot be deleted. Use "Reset to default" instead.']);
        }

        $aiInstruction->delete();

        return redirect()->route('admin.ai-instructions.index')->with('success', 'Deleted.');
    }

    /** Put the main prompt back to the built-in text. */
    public function reset()
    {
        AiInstruction::main()->update(['content' => ChatAssistant::DEFAULT_PROMPT, 'is_active' => true]);

        return redirect()->route('admin.ai-instructions.edit', AiInstruction::main())->with('success', 'Main prompt reset to the default text.');
    }

    /** Exactly what the AI receives (rules + site data + staff info) for a language and channel. */
    public function prompt(Request $request, ChatAssistant $assistant)
    {
        $locale = in_array($request->query('locale'), ['uz', 'ru', 'en'], true) ? $request->query('locale') : 'ru';
        $channel = $request->query('channel') === 'telegram' ? 'telegram' : 'web';
        $prompt = $assistant->promptPreview($locale, $channel);

        return view('admin.ai-instructions.prompt', compact('locale', 'channel', 'prompt'));
    }

    private function form(AiInstruction $instruction)
    {
        return view('admin.ai-instructions.form', [
            'instruction' => $instruction,
            'kinds' => AiInstruction::KINDS,
            'channels' => AiInstruction::CHANNELS,
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'kind' => ['required', Rule::in(array_keys(AiInstruction::KINDS))],
            'channel' => ['required', Rule::in(array_keys(AiInstruction::CHANNELS))],
            'content' => 'required|string|max:'.AiInstruction::MAX_LENGTH,
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);

        return $data + ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) ($data['sort_order'] ?? 0)];
    }
}
