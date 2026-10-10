<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;

class NewsController extends Controller
{
    public function index()
    {
        $categories = ArticleCategory::orderBy('sort_order')->get();
        $articles   = Article::published()->with('category')->paginate(9);

        return view('site.news.index', compact('categories', 'articles'));
    }

    public function category(string $locale, string $categorySlug)
    {
        $locale   = app()->getLocale();
        $category = ArticleCategory::all()
            ->first(fn($c) => $c->getTranslation('slug', $locale, false) === $categorySlug);

        abort_if(! $category, 404);

        $categories = ArticleCategory::orderBy('sort_order')->get();
        $articles   = Article::published()
            ->where('article_category_id', $category->id)
            ->with('category')
            ->paginate(9);

        return view('site.news.index', compact('categories', 'articles', 'category'));
    }

    public function show(string $locale, string $categorySlug, string $slug)
    {
        $locale  = app()->getLocale();
        $article = Article::published()->get()
            ->first(fn($a) => $a->getTranslation('slug', $locale, false) === $slug);

        abort_if(! $article, 404);

        $related = Article::published()
            ->where('article_category_id', $article->article_category_id)
            ->where('id', '!=', $article->id)
            ->with('category')
            ->limit(3)
            ->get();

        return view('site.news.show', compact('article', 'related'));
    }
}
