<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => Str::title(implode(' ', (array) fake()->unique()->words(2))),
            'sku' => Str::upper(Str::random(8)),
            'description' => fake()->sentence(),
            'station' => fake()->randomElement(['kitchen', 'bar']),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
