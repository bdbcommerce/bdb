<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Industry;

class IndustriesController extends Controller
{
    public function __invoke()
    {
        $industries = Industry::active()->withCount(['customers' => fn ($q) => $q->where('is_active', true)])->get();
        return view('site.industries', compact('industries'));
    }
}
