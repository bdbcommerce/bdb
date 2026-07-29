<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;

class ContactController extends Controller
{
    public function __invoke()
    {
        return view('site.contact');
    }
}
