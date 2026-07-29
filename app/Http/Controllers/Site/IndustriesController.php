<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Industry;

class IndustriesController extends Controller
{
    public function __invoke()
    {
        $industries = Industry::active()->withCount('customers')->get();
        return view('site.industries', compact('industries'));
    }
}
