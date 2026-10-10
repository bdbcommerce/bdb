<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Services\ChatAssistant;
use App\Services\ClientRequestResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnowledgeEntryController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('type');
        $entries = KnowledgeEntry::query()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('type')->orderBy('sort_order')
            ->get();

        return view('admin.knowledge.index', compact('entries', 'type'));
    }

    /** ?client_request=ID answers a group of customer questions; &draft=1 asks the AI for a first draft. */
    public function create(Request $request, ChatAssistant $assistant)
    {
        $entry = new KnowledgeEntry;
        $fromRequest = $request->integer('client_request') ? ClientRequest::find($request->integer('client_request')) : null;
        $questions = $fromRequest ? ClientRequest::sameGroupAs($fromRequest)->open()->latest()->pluck('question')->unique()->values() : collect();
        $draftFailed = false;

        if ($fromRequest) {
            // Without a draft, start from the customer's own question.
            $entry->setTranslation('question', $fromRequest->locale, $fromRequest->question);

            // Back here after a failed save, the admin's edited text is in old input: don't draft again.
            if ($request->boolean('draft') && ! $request->session()->hasOldInput()) {
                $draft = config('services.gemini.key') ? $assistant->draftKnowledge($questions->all() ?: [$fromRequest->question]) : null;
                if ($draft) {
                    $entry->fill(['type' => $draft['type'], 'keywords' => $draft['keywords']])
                        ->setTranslations('question', $draft['question'])
                        ->setTranslations('answer', $draft['answer']);
                } else {
                    $draftFailed = true;
                }
            }
        }

        return view('admin.knowledge.form', compact('entry', 'fromRequest', 'questions', 'draftFailed'));
    }

    public function store(Request $request, ClientRequestResolver $resolver)
    {
        $entry = KnowledgeEntry::create($this->validated($request));

        // Created to answer customer questions: resolve them and let the AI match similar ones.
        if ($fromRequest = ClientRequest::find($request->integer('client_request_id'))) {
            return redirect()->route('admin.client-requests.show', $fromRequest)
                ->with('success', 'Knowledge entry created. '.$resolver->answerWith($fromRequest, $entry));
        }

        return redirect()->route('admin.knowledge.index')->with('success', 'Knowledge entry created.');
    }

    public function edit(KnowledgeEntry $knowledge)
    {
        return view('admin.knowledge.form', ['entry' => $knowledge, 'fromRequest' => null, 'questions' => collect(), 'draftFailed' => false]);
    }

    public function update(Request $request, KnowledgeEntry $knowledge)
    {
        $knowledge->update($this->validated($request));

        return redirect()->route('admin.knowledge.index')->with('success', 'Knowledge entry updated.');
    }

    public function destroy(KnowledgeEntry $knowledge)
    {
        $knowledge->delete();

        return redirect()->route('admin.knowledge.index')->with('success', 'Knowledge entry deleted.');
    }

    private function validated(Request $request): array
    {
        $request->validate([
            'type' => ['required', Rule::in(array_keys(KnowledgeEntry::TYPES))],
            'question_uz' => 'required|string|max:300',
            'question_ru' => 'required|string|max:300',
            'question_en' => 'required|string|max:300',
            'answer_uz' => ['required', 'string', 'max:3000', $noPlaceholder = 'not_regex:/\[TO FILL/i'],
            'answer_ru' => ['required', 'string', 'max:3000', $noPlaceholder],
            'answer_en' => ['required', 'string', 'max:3000', $noPlaceholder],
            'keywords' => 'nullable|string|max:255',
            'link' => 'nullable|url|max:255',
        ], [
            // An AI draft's gaps must be filled in before customers can be told the answer.
            'answer_*.not_regex' => 'Replace every [TO FILL: …] placeholder in the :attribute with the real information.',
        ]);

        return [
            'type' => $request->type,
            'question' => ['uz' => $request->question_uz, 'ru' => $request->question_ru, 'en' => $request->question_en],
            'answer' => ['uz' => $request->answer_uz, 'ru' => $request->answer_ru, 'en' => $request->answer_en],
            'keywords' => $request->keywords,
            'link' => $request->link,
            'sort_order' => $request->integer('sort_order', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
