<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Gram', 'Mililiter', 'Buah']),
            'code' => Str::lower(Str::random(5)),
        ];
    }
}
