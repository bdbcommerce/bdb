<?php

namespace App\Livewire\Admin;

use App\Models\ArticleCategory;
use Illuminate\Support\Str;
use Livewire\Component;

class ArticleCategoryForm extends Component
{
    public ?ArticleCategory $category = null;

    public string $nameUz = '';
    public string $nameRu = '';
    public string $nameEn = '';
    public string $slugUz = '';
    public string $slugRu = '';
    public string $slugEn = '';
    public int $sortOrder = 0;

    public function mount(?int $categoryId = null): void
    {
        if ($categoryId) {
            $this->category = ArticleCategory::findOrFail($categoryId);
            $this->nameUz = $this->category->getTranslation('name', 'uz', false) ?? '';
            $this->nameRu = $this->category->getTranslation('name', 'ru', false) ?? '';
            $this->nameEn = $this->category->getTranslation('name', 'en', false) ?? '';
            $this->slugUz = $this->category->getTranslation('slug', 'uz', false) ?? '';
            $this->slugRu = $this->category->getTranslation('slug', 'ru', false) ?? '';
            $this->slugEn = $this->category->getTranslation('slug', 'en', false) ?? '';
            $this->sortOrder = $this->category->sort_order;
        }
    }

    public function updatedNameUz(): void
    {
        $this->slugUz = $this->makeSlug($this->nameUz);
    }

    public function updatedNameRu(): void
    {
        $this->slugRu = $this->makeSlug($this->nameRu);
    }

    public function updatedNameEn(): void
    {
        $this->slugEn = $this->makeSlug($this->nameEn);
    }

    public function save(): void
    {
        $this->validate([
            'nameUz' => 'required|string|max:200',
            'nameRu' => 'required|string|max:200',
            'nameEn' => 'required|string|max:200',
        ]);

        $data = [
            'name' => ['uz' => $this->nameUz, 'ru' => $this->nameRu, 'en' => $this->nameEn],
            'slug' => [
                'uz' => $this->slugUz ?: $this->makeSlug($this->nameUz),
                'ru' => $this->slugRu ?: $this->makeSlug($this->nameRu),
                'en' => $this->slugEn ?: $this->makeSlug($this->nameEn),
            ],
            'sort_order' => $this->sortOrder,
        ];

        if ($this->category) {
            $this->category->update($data);
        } else {
            ArticleCategory::create($data);
        }

        $this->redirectRoute('admin.article-categories.index');
    }

    private function makeSlug(string $text): string
    {
        // Cyrillic transliteration map for Russian slugs
        $cyrillicMap = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
            'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
            'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
            'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];

        $lower = mb_strtolower($text);
        $transliterated = strtr($lower, $cyrillicMap);

        return Str::slug($transliterated);
    }

    public function render()
    {
        return view('livewire.admin.article-category-form');
    }
}
