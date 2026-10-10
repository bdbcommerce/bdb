<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::orderBy('sort_order')->get();

        return view('admin.brands.index', compact('brands'));
    }

    public function create()
    {
        return view('admin.brands.form', ['brand' => new Brand]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_uz' => 'required|string|max:200',
            'name_ru' => 'required|string|max:200',
            'name_en' => 'required|string|max:200',
            'logo' => 'nullable|image|max:2048',
            'website' => 'nullable|url|max:255',
        ]);

        $data = $this->prepareData($request);
        Brand::create($data);

        return redirect()->route('admin.brands.index')->with('success', 'Brand created.');
    }

    public function edit(Brand $brand)
    {
        return view('admin.brands.form', compact('brand'));
    }

    public function update(Request $request, Brand $brand)
    {
        $request->validate([
            'name_uz' => 'required|string|max:200',
            'name_ru' => 'required|string|max:200',
            'name_en' => 'required|string|max:200',
            'logo' => 'nullable|image|max:2048',
            'website' => 'nullable|url|max:255',
        ]);

        $data = $this->prepareData($request, $brand->logo);
        $brand->update($data);

        return redirect()->route('admin.brands.index')->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand)
    {
        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }
        $brand->delete();

        return redirect()->route('admin.brands.index')->with('success', 'Brand deleted.');
    }

    private function prepareData(Request $request, ?string $existingLogo = null): array
    {
        $logo = $existingLogo;
        if ($request->hasFile('logo')) {
            if ($existingLogo) {
                Storage::disk('public')->delete($existingLogo);
            }
            $logo = $request->file('logo')->store('brands', 'public');
        }

        return [
            'name' => ['uz' => $request->name_uz, 'ru' => $request->name_ru, 'en' => $request->name_en],
            'description' => ['uz' => $request->description_uz, 'ru' => $request->description_ru, 'en' => $request->description_en],
            'logo' => $logo,
            'website' => $request->website,
            'sort_order' => $request->integer('sort_order', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
