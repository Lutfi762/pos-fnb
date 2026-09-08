<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Tingkat Gula', 'Tingkat Es', 'Topping']),
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (): array => ['min_select' => 1, 'max_select' => 1]);
    }
}
