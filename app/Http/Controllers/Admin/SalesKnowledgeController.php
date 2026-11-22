<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesKnowledge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin → Sales assistant → Knowledge: one tab per kind (product questions, business sets, Asl Belgi categories…). */
class SalesKnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $kind = is_string($k = $request->query('kind')) && isset(SalesKnowledge::KINDS[$k]) ? $k : 'product';

        return view('admin.sales-knowledge.index', [
            'kind' => $kind,
            'entries' => SalesKnowledge::where('kind', $kind)->orderBy('sort')->orderBy('id')->get(),
            'counts' => SalesKnowledge::query()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
        ]);
    }

    public function create(Request $request)
    {
        $kind = is_string($k = $request->query('kind')) && isset(SalesKnowledge::KINDS[$k]) ? $k : 'product';

        return view('admin.sales-knowledge.form', ['entry' => new SalesKnowledge(['kind' => $kind, 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $entry = SalesKnowledge::create($this->validated($request) + ['sort' => (int) SalesKnowledge::where('kind', $request->input('kind'))->max('sort') + 10]);

        return redirect()->route('admin.sales-knowledge.index', ['kind' => $entry->kind])->with('success', 'Saved.');
    }

    public function edit(SalesKnowledge $salesKnowledge)
    {
        return view('admin.sales-knowledge.form', ['entry' => $salesKnowledge]);
    }

    public function update(Request $request, SalesKnowledge $salesKnowledge)
    {
        $salesKnowledge->update($this->validated($request));

        return redirect()->route('admin.sales-knowledge.index', ['kind' => $salesKnowledge->kind])->with('success', 'Saved.');
    }

    public function destroy(SalesKnowledge $salesKnowledge)
    {
        $salesKnowledge->delete();

        return redirect()->route('admin.sales-knowledge.index', ['kind' => $salesKnowledge->kind])->with('success', 'Deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SalesKnowledge::KINDS))],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['body'] = str_replace("\r\n", "\n", $data['body']);
        $data['is_active'] = $request->boolean('is_active');
        if (! isset($data['sort'])) {
            unset($data['sort']);
        }

        return $data;
    }
}
