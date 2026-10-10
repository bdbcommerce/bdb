<?php

namespace App\Models;

use App\Models\Concerns\FlushesChatKnowledge;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A fact the AI chat assistant is allowed to use. Admins fill these in
 * as question/answer pairs grouped by type.
 */
class KnowledgeEntry extends Model
{
    use FlushesChatKnowledge, HasTranslations;

    public const TYPES = [
        'faq' => 'FAQ',
        'product' => 'Product / Service',
        'asl_belgi' => 'Asl Belgi / Marking',
        'pricing' => 'Pricing & Ordering',
        'delivery' => 'Delivery & Warranty',
        'support' => 'Support & Service',
        'company' => 'Company',
    ];

    public array $translatable = ['question', 'answer'];

    protected $fillable = [
        'type', 'question', 'answer', 'keywords', 'link', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('type')->orderBy('sort_order');
    }
}
