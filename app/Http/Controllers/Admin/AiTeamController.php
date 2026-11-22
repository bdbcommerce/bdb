<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AiTeamMock;

/** AI Sales & Support Team — MOCK-UP pages to agree the structure; they show sample data and save nothing. */
class AiTeamController extends Controller
{
    public const PAGES = ['team', 'clients', 'test-chat', 'test-cases', 'audit', 'gaps'];

    public function show(string $page = 'team')
    {
        return view('admin.ai-team.'.$page, ['employees' => AiTeamMock::employees()]);
    }

    public function employee(string $slug)
    {
        $employee = AiTeamMock::employee($slug);
        abort_unless($employee, 404);

        return view('admin.ai-team.employee', ['employee' => $employee, 'versions' => AiTeamMock::versions()]);
    }
}
