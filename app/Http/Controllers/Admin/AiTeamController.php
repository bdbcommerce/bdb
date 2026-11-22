<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEmployee;

/** AI Sales & Support Team — MOCK-UP pages (client portraits and training) to agree the structure; they save nothing. */
class AiTeamController extends Controller
{
    public const PAGES = ['clients', 'test-cases', 'audit', 'gaps'];

    public function show(string $page)
    {
        return view('admin.ai-team.'.$page, ['employees' => AiEmployee::orderBy('sort')->orderBy('id')->get()]);
    }
}
