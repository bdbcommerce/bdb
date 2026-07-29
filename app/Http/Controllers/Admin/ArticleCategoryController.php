<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;

class ArticleCategoryController extends Controller
{
    public function index()
    {
        $categories = ArticleCategory::orderBy('sort_order')->get();
        return view('admin.article-categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.article-categories.form');
    }

    public function edit(ArticleCategory $articleCategory)
    {
        return view('admin.article-categories.form', ['articleCategory' => $articleCategory]);
    }

    public function destroy(ArticleCategory $articleCategory)
    {
        $articleCategory->delete();
        return redirect()->route('admin.article-categories.index')->with('success', 'Category deleted.');
    }
}
