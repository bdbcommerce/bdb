<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Industry;
use Illuminate\Http\Request;

class IndustryController extends Controller
{
    public function index()
    {
        $industries = Industry::orderBy('sort_order')->get();
        return view('admin.industries.index', compact('industries'));
    }

    public function create()
    {
        return view('admin.industries.form', ['industry' => new Industry]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_uz' => 'required|string|max:200',
            'name_ru' => 'required|string|max:200',
            'name_en' => 'required|string|max:200',
        ]);

        Industry::create($this->prepareData($request));

        return redirect()->route('admin.industries.index')->with('success', 'Industry created.');
    }

    public function edit(Industry $industry)
    {
        return view('admin.industries.form', compact('industry'));
    }

    public function update(Request $request, Industry $industry)
    {
        $request->validate([
            'name_uz' => 'required|string|max:200',
            'name_ru' => 'required|string|max:200',
            'name_en' => 'required|string|max:200',
        ]);

        $industry->update($this->prepareData($request));

        return redirect()->route('admin.industries.index')->with('success', 'Industry updated.');
    }

    public function destroy(Industry $industry)
    {
        $industry->delete();
        return redirect()->route('admin.industries.index')->with('success', 'Industry deleted.');
    }

    private function prepareData(Request $request): array
    {
        return [
            'name'        => ['uz' => $request->name_uz, 'ru' => $request->name_ru, 'en' => $request->name_en],
            'description' => ['uz' => $request->description_uz, 'ru' => $request->description_ru, 'en' => $request->description_en],
            'icon'        => $request->input('icon'),
            'sort_order'  => $request->integer('sort_order', 0),
            'is_active'   => $request->boolean('is_active'),
        ];
    }
}
