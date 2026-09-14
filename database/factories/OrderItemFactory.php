<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->numberBetween(10, 50) * 1000;

        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_name' => fake()->randomElement(['Nasi Goreng', 'Es Teh Manis', 'Kopi Susu']),
            'variant_name' => 'Reguler',
            'station' => 'kitchen',
            'prep_minutes' => 10,
            'quantity' => 1,
            'unit_price' => $price,
            'modifier_total' => 0,
            'discount_total' => 0,
            'line_total' => $price,
            'status' => OrderItemStatus::Pending,
        ];
    }

    /**
     * A line already fired at its station.
     */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderItemStatus::Sent,
            'sent_at' => now(),
        ]);
    }
}
