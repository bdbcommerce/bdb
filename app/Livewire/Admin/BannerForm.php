<?php

namespace App\Livewire\Admin;

use App\Models\Banner;
use Livewire\Component;
use Livewire\WithFileUploads;

class BannerForm extends Component
{
    use WithFileUploads;

    public ?Banner $banner = null;

    public string $titleUz = '';
    public string $titleRu = '';
    public string $titleEn = '';
    public string $subtitleUz = '';
    public string $subtitleRu = '';
    public string $subtitleEn = '';
    public string $buttonTextUz = '';
    public string $buttonTextRu = '';
    public string $buttonTextEn = '';
    public string $buttonUrl = '';
    public bool $isActive = true;
    public int $sortOrder = 0;
    public $image;
    public ?string $existingImage = null;

    public function mount(?int $bannerId = null): void
    {
        if ($bannerId) {
            $this->banner = Banner::findOrFail($bannerId);
            $this->titleUz     = $this->banner->getTranslation('title', 'uz', false) ?? '';
            $this->titleRu     = $this->banner->getTranslation('title', 'ru', false) ?? '';
            $this->titleEn     = $this->banner->getTranslation('title', 'en', false) ?? '';
            $this->subtitleUz  = $this->banner->getTranslation('subtitle', 'uz', false) ?? '';
            $this->subtitleRu  = $this->banner->getTranslation('subtitle', 'ru', false) ?? '';
            $this->subtitleEn  = $this->banner->getTranslation('subtitle', 'en', false) ?? '';
            $this->buttonTextUz = $this->banner->getTranslation('button_text', 'uz', false) ?? '';
            $this->buttonTextRu = $this->banner->getTranslation('button_text', 'ru', false) ?? '';
            $this->buttonTextEn = $this->banner->getTranslation('button_text', 'en', false) ?? '';
            $this->buttonUrl   = $this->banner->button_url ?? '';
            $this->isActive    = $this->banner->is_active;
            $this->sortOrder   = $this->banner->sort_order;
            $this->existingImage = $this->banner->image;
        }
    }

    public function save(): void
    {
        $this->validate([
            'titleUz'  => 'required|string|max:255',
            'titleRu'  => 'required|string|max:255',
            'titleEn'  => 'required|string|max:255',
            'image'    => $this->banner ? 'nullable|image|max:4096' : 'nullable|image|max:4096',
            'buttonUrl' => 'nullable|url|max:500',
        ]);

        $imagePath = $this->existingImage;
        if ($this->image) {
            if ($this->existingImage) {
                \Storage::disk('public')->delete($this->existingImage);
            }
            $imagePath = $this->image->store('banners', 'public');
        }

        $data = [
            'title'       => ['uz' => $this->titleUz, 'ru' => $this->titleRu, 'en' => $this->titleEn],
            'subtitle'    => ['uz' => $this->subtitleUz, 'ru' => $this->subtitleRu, 'en' => $this->subtitleEn],
            'button_text' => ['uz' => $this->buttonTextUz, 'ru' => $this->buttonTextRu, 'en' => $this->buttonTextEn],
            'button_url'  => $this->buttonUrl ?: null,
            'image'       => $imagePath,
            'is_active'   => $this->isActive,
            'sort_order'  => $this->sortOrder,
        ];

        if ($this->banner) {
            $this->banner->update($data);
        } else {
            Banner::create($data);
        }

        $this->redirectRoute('admin.banners.index');
    }

    public function render()
    {
        return view('livewire.admin.banner-form');
    }
}
