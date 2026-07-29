<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Brand;

class BrandsController extends Controller
{
    public function __invoke()
    {
        $brands = Brand::active()->get();
        return view('site.brands', compact('brands'));
    }
}
