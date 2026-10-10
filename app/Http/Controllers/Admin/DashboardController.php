<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\ChatLead;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'brands' => Brand::count(),
            'industries' => Industry::count(),
            'customers' => Customer::count(),
            'articles' => Article::count(),
            'published_articles' => Article::where('is_published', true)->count(),
            'banners' => Banner::count(),
            'new_leads' => ChatLead::where('is_handled', false)->count(),
            'leads' => ChatLead::count(),
            'knowledge' => KnowledgeEntry::count(),
            'active_knowledge' => KnowledgeEntry::where('is_active', true)->count(),
        ];

        $recentLeads = ChatLead::latest()->take(5)->get();

        return view('admin.dashboard', compact('stats', 'recentLeads'));
    }
}
