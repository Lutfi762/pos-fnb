<?php

declare(strict_types=1);

namespace Database\Seeders;

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
        $this->call([
            OutletSeeder::class,
            UserSeeder::class,
            PaymentMethodSeeder::class,
            CatalogSeeder::class,
            InventorySeeder::class,

            // Recipes need both the menu and the ingredients to exist first.
            RecipeSeeder::class,
        ]);
    }
}
