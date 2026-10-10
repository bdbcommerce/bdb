<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeEntry;
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

    public function create()
    {
        return view('admin.knowledge.form', ['entry' => new KnowledgeEntry]);
    }

    public function store(Request $request)
    {
        KnowledgeEntry::create($this->validated($request));

        return redirect()->route('admin.knowledge.index')->with('success', 'Knowledge entry created.');
    }

    public function edit(KnowledgeEntry $knowledge)
    {
        return view('admin.knowledge.form', ['entry' => $knowledge]);
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
            'answer_uz' => 'required|string|max:3000',
            'answer_ru' => 'required|string|max:3000',
            'answer_en' => 'required|string|max:3000',
            'keywords' => 'nullable|string|max:255',
            'link' => 'nullable|url|max:255',
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
