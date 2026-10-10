<?php

namespace App\Models;

use App\Models\Concerns\FlushesChatKnowledge;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class CompanyInfo extends Model
{
    use FlushesChatKnowledge, HasTranslations;

    protected $table = 'company_info';

    public array $translatable = ['address', 'slogan', 'about'];

    protected $fillable = [
        'address', 'slogan', 'about',
        'phone', 'email',
        'telegram', 'instagram', 'facebook', 'youtube', 'linkedin',
        'map_embed', 'google_analytics', 'yandex_metrika',
    ];

    public static function instance(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
