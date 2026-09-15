<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\RecipeItem;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\SaveOrder;
use App\Models\User;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create([
        'tax_percent' => 0,
        'service_charge_percent' => 0,
        'cash_rounding' => 0,
    ]);

    $this->cashier = User::factory()->atOutlet($this->outlet)->create();

    $this->variant = ProductVariant::factory()
        ->for(Product::factory()->for(Category::factory())->create(['name' => 'Kopi Susu']))
        ->create(['name' => 'Reguler', 'base_price' => 20_000, 'is_default' => true]);

    // 20 gram of beans at 200.000 per kilo, plus a 1.500 cup: 5.500 a serving.
    $beans = InventoryItem::factory()->create(['average_cost' => 200_000]);
    $cup = InventoryItem::factory()->create(['average_cost' => 1_500]);

    RecipeItem::create([
        'product_variant_id' => $this->variant->getKey(),
        'inventory_item_id' => $beans->getKey(),
        'quantity' => 0.02,
    ]);

    RecipeItem::create([
        'product_variant_id' => $this->variant->getKey(),
        'inventory_item_id' => $cup->getKey(),
        'quantity' => 1,
    ]);
});

/**
 * Ring up a quantity of the seeded drink and hand back the saved line.
 */
function ring(int $quantity): OrderItem
{
    $order = app(SaveOrder::class)->handle(null, test()->outlet, test()->cashier, [[
        'product_id' => test()->variant->product_id,
        'product_variant_id' => test()->variant->getKey(),
        'product_name' => 'Kopi Susu',
        'variant_name' => 'Reguler',
        'quantity' => $quantity,
        'unit_price' => 20_000,
        'modifiers' => [],
    ]]);

    return $order->items()->firstOrFail();
}

it('freezes the ingredient cost on the line', function (): void {
    expect(ring(1)->cost_total)->toBe(5_500);
});

it('costs the whole line, not one serving', function (): void {
    expect(ring(3)->cost_total)->toBe(16_500);
});

it('leaves a dish without a recipe at no cost rather than guessing one', function (): void {
    RecipeItem::query()->where('product_variant_id', $this->variant->getKey())->delete();

    expect(ring(2)->cost_total)->toBe(0);
});

it('keeps an old bill at the old cost after a price rise', function (): void {
    $sold = ring(1);

    // The next delivery arrives dearer. Last week's margin must not move.
    InventoryItem::query()->update(['average_cost' => 999_000]);

    expect($sold->fresh()->cost_total)->toBe(5_500)
        ->and(ring(1)->cost_total)->toBeGreaterThan(5_500);
});
