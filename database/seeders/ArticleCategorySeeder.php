<?php

namespace Database\Seeders;

use App\Models\ArticleCategory;
use Illuminate\Database\Seeder;

class ArticleCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Skip if categories already exist — prevents duplicates on re-seed.
        if (ArticleCategory::count() > 0) {
            $this->command->info('Article categories already seeded, skipping.');
            return;
        }

        $categories = [
            [
                'name' => ['uz' => 'Auto ID yangiliklari', 'ru' => 'Новости Auto ID', 'en' => 'Auto ID News'],
                'slug' => ['uz' => 'auto-id-yangiliklari', 'ru' => 'novosti-auto-id', 'en' => 'auto-id-news'],
                'sort_order' => 1,
            ],
            [
                'name' => ['uz' => 'Soliq siyosati', 'ru' => 'Налоговая политика', 'en' => 'Tax Policy'],
                'slug' => ['uz' => 'soliq-siyosati', 'ru' => 'nalogovaya-politika', 'en' => 'tax-policy'],
                'sort_order' => 2,
            ],
            [
                'name' => ['uz' => 'Asl Belgi', 'ru' => 'Асл Белги', 'en' => 'Asl Belgi'],
                'slug' => ['uz' => 'asl-belgi', 'ru' => 'asl-belgi', 'en' => 'asl-belgi'],
                'sort_order' => 3,
            ],
        ];

        foreach ($categories as $category) {
            ArticleCategory::create($category);
        }
    }
}
