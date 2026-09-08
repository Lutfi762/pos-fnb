<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    protected $model = Outlet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Outlet '.fake()->unique()->city();

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'tax_percent' => 11,
            'service_charge_percent' => 5,
            'price_includes_tax' => true,
            'cash_rounding' => 100,
            'receipt_header' => $name,
            'receipt_footer' => 'Terima kasih atas kunjungan Anda.',
            'is_active' => true,
        ];
    }
}
