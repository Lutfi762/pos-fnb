<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Modifier>
 */
class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'name' => fake()->word(),
            'price' => fake()->randomElement([0, 3000, 5000]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
