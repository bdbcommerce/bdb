<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class ArticleCategory extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'slug'];

    protected $fillable = ['name', 'slug', 'sort_order'];

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
