<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Industry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::with('industry')->orderBy('sort_order')->get();

        return view('admin.customers.index', compact('customers'));
    }

    public function create()
    {
        $industries = Industry::orderBy('sort_order')->get();

        return view('admin.customers.form', ['customer' => new Customer, 'industries' => $industries]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:200',
            'logo' => 'nullable|image|max:2048',
        ]);

        Customer::create($this->prepareData($request));

        return redirect()->route('admin.customers.index')->with('success', 'Customer created.');
    }

    public function edit(Customer $customer)
    {
        $industries = Industry::orderBy('sort_order')->get();

        return view('admin.customers.form', compact('customer', 'industries'));
    }

    public function update(Request $request, Customer $customer)
    {
        $request->validate([
            'name' => 'required|string|max:200',
            'logo' => 'nullable|image|max:2048',
        ]);

        $customer->update($this->prepareData($request, $customer->logo));

        return redirect()->route('admin.customers.index')->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer)
    {
        if ($customer->logo) {
            Storage::disk('public')->delete($customer->logo);
        }
        $customer->delete();

        return redirect()->route('admin.customers.index')->with('success', 'Customer deleted.');
    }

    private function prepareData(Request $request, ?string $existingLogo = null): array
    {
        $logo = $existingLogo;
        if ($request->hasFile('logo')) {
            if ($existingLogo) {
                Storage::disk('public')->delete($existingLogo);
            }
            $logo = $request->file('logo')->store('customers', 'public');
        }

        return [
            'name' => $request->input('name'),
            'description' => ['uz' => $request->description_uz, 'ru' => $request->description_ru, 'en' => $request->description_en],
            'logo' => $logo,
            'website' => $request->input('website'),
            'industry_id' => $request->input('industry_id') ?: null,
            'sort_order' => $request->integer('sort_order', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
