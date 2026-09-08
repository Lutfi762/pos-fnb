<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\OutletVariantPrice;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;

it('falls back to the base price when an outlet has no override', function (): void {
    $outlet = Outlet::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 25_000]);

    expect($variant->load('outletPrices')->priceFor($outlet))->toBe(25_000);
});

it('uses the outlet override when there is one', function (): void {
    $outlet = Outlet::factory()->create();
    $other = Outlet::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 25_000]);

    OutletVariantPrice::create([
        'outlet_id' => $outlet->id,
        'product_variant_id' => $variant->id,
        'price' => 30_000,
    ]);

    $variant->load('outletPrices');

    expect($variant->priceFor($outlet))->toBe(30_000)
        ->and($variant->priceFor($other))->toBe(25_000);
});

it('keeps money as an integer, never a float or a string', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => 25_000]);

    expect($variant->fresh()->base_price)->toBeInt();
});
