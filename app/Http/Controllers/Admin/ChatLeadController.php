<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatLead;

class ChatLeadController extends Controller
{
    public function index()
    {
        $leads = ChatLead::latest()->paginate(30);

        return view('admin.chat-leads.index', compact('leads'));
    }

    public function update(ChatLead $chatLead)
    {
        $chatLead->update(['is_handled' => ! $chatLead->is_handled]);

        return back();
    }

    public function destroy(ChatLead $chatLead)
    {
        $chatLead->delete();

        return back()->with('success', 'Lead deleted.');
    }
}
