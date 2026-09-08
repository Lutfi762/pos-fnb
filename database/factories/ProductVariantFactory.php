<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Reguler', 'Besar', 'Kecil']),
            'sku' => Str::upper(Str::random(8)),
            'base_price' => fake()->numberBetween(10, 60) * 1000,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
