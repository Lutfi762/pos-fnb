<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'name' => Str::title(implode(' ', (array) fake()->unique()->words(2))),
            'sku' => Str::upper(Str::random(8)),
            'category' => fake()->randomElement(['Bahan Baku', 'Kemasan']),
            'average_cost' => fake()->numberBetween(5, 200) * 1000,
            'is_active' => true,
        ];
    }
}
