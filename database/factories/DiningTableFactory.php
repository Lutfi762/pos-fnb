<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\DiningTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiningTable>
 */
class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'name' => 'M'.fake()->unique()->numberBetween(1, 999),
            'area' => fake()->randomElement(['Indoor', 'Outdoor']),
            'seats' => fake()->randomElement([2, 4, 6]),
            'is_active' => true,
        ];
    }
}
