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
        // firstOrCreate makes this idempotent — safe to run on a production DB
        // that already has an admin user.
        User::firstOrCreate(
            ['email' => 'admin@bdb.uz'],
            [
                'name' => 'BDB Admin',
                'password' => bcrypt('admin123'),
                'is_admin' => true,
            ]
        );

        $this->call([
            ArticleCategorySeeder::class,
        ]);
    }
}
