<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Enums\OrderType;
use App\Domain\Sales\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'user_id' => User::factory(),
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'type' => OrderType::DineIn,
            'status' => OrderStatus::Open,
            'guest_count' => 2,
            'tax_percent' => 11,
            'service_charge_percent' => 5,
            'price_includes_tax' => true,
            'placed_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'paid_total' => $attributes['grand_total'] ?? 0,
        ]);
    }
}
