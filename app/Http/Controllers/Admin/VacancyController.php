<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VacancyController extends Controller
{
    private const LOCALES = ['uz', 'ru', 'en'];

    public function index()
    {
        $vacancies = Vacancy::withCount('applications')->orderBy('sort_order')->orderByDesc('id')->get();

        return view('admin.vacancies.index', compact('vacancies'));
    }

    public function create()
    {
        return view('admin.vacancies.form', ['vacancy' => new Vacancy(['is_active' => true, 'employment_type' => 'full_time'])]);
    }

    public function store(Request $request)
    {
        Vacancy::create($this->validated($request));

        return redirect()->route('admin.vacancies.index')->with('success', 'Vacancy created.');
    }

    public function edit(Vacancy $vacancy)
    {
        return view('admin.vacancies.form', compact('vacancy'));
    }

    public function update(Request $request, Vacancy $vacancy)
    {
        $vacancy->update($this->validated($request));

        return redirect()->route('admin.vacancies.index')->with('success', 'Vacancy updated.');
    }

    public function destroy(Vacancy $vacancy)
    {
        $vacancy->delete(); // applications stay (vacancy_title is kept)

        return redirect()->route('admin.vacancies.index')->with('success', 'Vacancy deleted.');
    }

    private function validated(Request $request): array
    {
        $rules = [
            'department' => ['required', Rule::in(Vacancy::DEPARTMENTS)],
            'employment_type' => ['required', Rule::in(Vacancy::EMPLOYMENT_TYPES)],
            'salary' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
        foreach (self::LOCALES as $l) {
            $rules["title_$l"] = [$l === 'uz' ? 'required' : 'nullable', 'string', 'max:200'];
            $rules["location_$l"] = ['nullable', 'string', 'max:200'];
            foreach (['description', 'requirements', 'offer'] as $f) {
                $rules["{$f}_$l"] = ['nullable', 'string', 'max:5000'];
            }
        }
        $request->validate($rules);

        $t = fn (string $f) => collect(self::LOCALES)->mapWithKeys(fn ($l) => [$l => (string) $request->input("{$f}_$l", '')])->all();

        return [
            'title' => $t('title'),
            'location' => $t('location'),
            'description' => $t('description'),
            'requirements' => $t('requirements'),
            'offer' => $t('offer'),
            'department' => $request->input('department'),
            'employment_type' => $request->input('employment_type'),
            'salary' => $request->input('salary') ?: null,
            'sort_order' => $request->integer('sort_order', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
