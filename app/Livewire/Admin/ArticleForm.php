<?php

namespace App\Livewire\Admin;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ArticleForm extends Component
{
    use WithFileUploads;

    public ?Article $article = null;

    public string $articleCategoryId = '';

    public string $titleUz = '';

    public string $titleRu = '';

    public string $titleEn = '';

    public string $slugUz = '';

    public string $slugRu = '';

    public string $slugEn = '';

    public string $excerptUz = '';

    public string $excerptRu = '';

    public string $excerptEn = '';

    public string $contentUz = '';

    public string $contentRu = '';

    public string $contentEn = '';

    public $featuredImage = null;

    public ?string $existingImage = null;

    public bool $isPublished = false;

    public string $publishedAt = '';

    public int $sortOrder = 0;

    public function mount(?int $articleId = null): void
    {
        if ($articleId) {
            $this->article = Article::findOrFail($articleId);
            $this->articleCategoryId = (string) $this->article->article_category_id;
            $this->titleUz = $this->article->getTranslation('title', 'uz', false) ?? '';
            $this->titleRu = $this->article->getTranslation('title', 'ru', false) ?? '';
            $this->titleEn = $this->article->getTranslation('title', 'en', false) ?? '';
            $this->slugUz = $this->article->getTranslation('slug', 'uz', false) ?? '';
            $this->slugRu = $this->article->getTranslation('slug', 'ru', false) ?? '';
            $this->slugEn = $this->article->getTranslation('slug', 'en', false) ?? '';
            $this->excerptUz = $this->article->getTranslation('excerpt', 'uz', false) ?? '';
            $this->excerptRu = $this->article->getTranslation('excerpt', 'ru', false) ?? '';
            $this->excerptEn = $this->article->getTranslation('excerpt', 'en', false) ?? '';
            $this->contentUz = $this->article->getTranslation('content', 'uz', false) ?? '';
            $this->contentRu = $this->article->getTranslation('content', 'ru', false) ?? '';
            $this->contentEn = $this->article->getTranslation('content', 'en', false) ?? '';
            $this->existingImage = $this->article->featured_image;
            $this->isPublished = $this->article->is_published;
            $this->publishedAt = $this->article->published_at?->format('Y-m-d\TH:i') ?? '';
            $this->sortOrder = $this->article->sort_order;
        }
    }

    public function updatedTitleUz(): void
    {
        $this->slugUz = $this->makeSlug($this->titleUz);
    }

    public function updatedTitleRu(): void
    {
        $this->slugRu = $this->makeSlug($this->titleRu);
    }

    public function updatedTitleEn(): void
    {
        $this->slugEn = $this->makeSlug($this->titleEn);
    }

    public function save(): void
    {
        $this->validate([
            'articleCategoryId' => 'required|exists:article_categories,id',
            'titleUz' => 'required|string|max:500',
            'titleRu' => 'required|string|max:500',
            'titleEn' => 'required|string|max:500',
            'contentUz' => 'required|string',
            'contentRu' => 'required|string',
            'contentEn' => 'required|string',
            'featuredImage' => 'nullable|image|max:4096',
        ]);

        $image = $this->existingImage;
        if ($this->featuredImage) {
            if ($this->existingImage) {
                Storage::disk('public')->delete($this->existingImage);
            }
            $image = $this->featuredImage->store('articles', 'public');
        }

        $data = [
            'article_category_id' => $this->articleCategoryId,
            'title' => ['uz' => $this->titleUz,   'ru' => $this->titleRu,   'en' => $this->titleEn],
            'slug' => [
                'uz' => $this->slugUz ?: $this->makeSlug($this->titleUz),
                'ru' => $this->slugRu ?: $this->makeSlug($this->titleRu),
                'en' => $this->slugEn ?: $this->makeSlug($this->titleEn),
            ],
            'excerpt' => ['uz' => $this->excerptUz, 'ru' => $this->excerptRu, 'en' => $this->excerptEn],
            'content' => ['uz' => $this->contentUz, 'ru' => $this->contentRu, 'en' => $this->contentEn],
            'featured_image' => $image,
            'is_published' => $this->isPublished,
            'published_at' => $this->isPublished ? ($this->publishedAt ?: now()->toDateTimeString()) : null,
            'sort_order' => $this->sortOrder,
        ];

        if ($this->article) {
            $this->article->update($data);
        } else {
            Article::create($data);
        }

        $this->redirectRoute('admin.articles.index');
    }

    private function makeSlug(string $text): string
    {
        $cyrillicMap = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $lower = mb_strtolower($text);
        $transliterated = strtr($lower, $cyrillicMap);

        return Str::slug($transliterated);
    }

    public function render()
    {
        return view('livewire.admin.article-form', [
            'categories' => ArticleCategory::orderBy('sort_order')->get(),
        ]);
    }
}
