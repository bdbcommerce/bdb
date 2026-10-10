<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = config('app.admin');

        if (blank($admin['password'])) {
            $this->command?->warn('ADMIN_PASSWORD is not set in .env — skipping admin user.');
        } else {
            // firstOrCreate makes this idempotent — safe to run on a production DB
            // that already has an admin user.
            User::firstOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'password' => bcrypt($admin['password']),
                    'is_admin' => true,
                ]
            );
        }

        $this->call([
            ArticleCategorySeeder::class,
            HeroBannerSeeder::class,
            AiModelSeeder::class, // free AI models, switched off until a key is added
            DemoContentSeeder::class,
        ]);

    }
}
