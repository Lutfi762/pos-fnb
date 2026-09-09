<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\OutletVariantPrice;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();

    $this->actingAs($this->manager);
    app(CurrentOutlet::class)->set($this->outlet);
});

it('shows the price of the current outlet, not the base price', function (): void {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->for(Category::factory()))
        ->create(['base_price' => 25000]);

    OutletVariantPrice::create([
        'outlet_id' => $this->outlet->id,
        'product_variant_id' => $variant->id,
        'price' => 30000,
        'is_available' => true,
    ]);

    Livewire::test('pages::catalog.index')
        ->assertSee('Rp30.000')
        ->assertDontSee('Rp25.000');
});

it('falls back to the base price when the outlet has no override', function (): void {
    ProductVariant::factory()
        ->for(Product::factory()->for(Category::factory()))
        ->create(['base_price' => 18000]);

    Livewire::test('pages::catalog.index')->assertSee('Rp18.000');
});

it('filters by name and by category', function (): void {
    $coffee = Category::factory()->create(['name' => 'Kopi']);
    $tea = Category::factory()->create(['name' => 'Teh']);

    Product::factory()->for($coffee)->create(['name' => 'Kopi Susu']);
    Product::factory()->for($tea)->create(['name' => 'Es Teh Manis']);

    Livewire::test('pages::catalog.index')
        ->set('search', 'Kopi')
        ->assertSee('Kopi Susu')
        ->assertDontSee('Es Teh Manis')
        ->set('search', '')
        ->set('categoryId', $tea->id)
        ->assertSee('Es Teh Manis')
        ->assertDontSee('Kopi Susu');
});

it('hides archived products until they are asked for', function (): void {
    $product = Product::factory()->for(Category::factory())->create(['name' => 'Menu Lama']);
    $product->delete();

    Livewire::test('pages::catalog.index')
        ->assertDontSee('Menu Lama')
        ->set('withArchived', true)
        ->assertSee('Menu Lama');
});

it('archives a product without erasing it', function (): void {
    $product = Product::factory()->for(Category::factory())->create();

    Livewire::test('pages::catalog.index')
        ->call('archive', $product->id)
        ->assertHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
});

it('restores an archived product', function (): void {
    $product = Product::factory()->for(Category::factory())->create();
    $product->delete();

    Livewire::test('pages::catalog.index')
        ->set('withArchived', true)
        ->call('restore', $product->id);

    expect($product->fresh()->trashed())->toBeFalse();
});
