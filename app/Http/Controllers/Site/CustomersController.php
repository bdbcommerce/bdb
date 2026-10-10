<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Industry;

class CustomersController extends Controller
{
    public function __invoke()
    {
        $customers = Customer::active()->with('industry')->get();
        $industries = Industry::active()->get();

        return view('site.customers', compact('customers', 'industries'));
    }
}
