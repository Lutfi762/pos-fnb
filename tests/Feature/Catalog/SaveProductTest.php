<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
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
    $this->category = Category::factory()->create();

    $this->actingAs($this->manager);
    app(CurrentOutlet::class)->set($this->outlet);
});

it('creates a product with its variants', function (): void {
    Livewire::test('pages::catalog.edit')
        ->set('name', 'Es Teh Manis')
        ->set('categoryId', $this->category->id)
        ->set('station', 'bar')
        ->set('variants', [
            ['id' => null, 'name' => 'Reguler', 'price' => '10.000', 'is_active' => true],
            ['id' => null, 'name' => 'Jumbo', 'price' => 'Rp15.000', 'is_active' => true],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('catalog.index'));

    $product = Product::where('name', 'Es Teh Manis')->firstOrFail();

    expect($product->station)->toBe('bar')
        ->and($product->variants)->toHaveCount(2);

    // Typed rupiah is stored as a whole-rupiah integer, never a float.
    expect($product->variants->firstWhere('name', 'Reguler')->base_price)->toBe(10000)
        ->and($product->variants->firstWhere('name', 'Jumbo')->base_price)->toBe(15000);
});

it('marks the first variant as the default one', function (): void {
    Livewire::test('pages::catalog.edit')
        ->set('name', 'Kopi Susu')
        ->set('categoryId', $this->category->id)
        ->set('variants', [
            ['id' => null, 'name' => 'Reguler', 'price' => '25000', 'is_active' => true],
            ['id' => null, 'name' => 'Besar', 'price' => '30000', 'is_active' => true],
        ])
        ->call('save');

    $variants = Product::where('name', 'Kopi Susu')->firstOrFail()->variants->sortBy('sort_order')->values();

    expect($variants[0]->is_default)->toBeTrue()
        ->and($variants[1]->is_default)->toBeFalse();
});

it('refuses a product without a name or a category', function (): void {
    Livewire::test('pages::catalog.edit')
        ->set('name', '')
        ->set('categoryId', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'categoryId' => 'required']);
});

it('refuses a variant without a name', function (): void {
    Livewire::test('pages::catalog.edit')
        ->set('name', 'Indomie Goreng')
        ->set('categoryId', $this->category->id)
        ->set('variants', [['id' => null, 'name' => '', 'price' => '15000', 'is_active' => true]])
        ->call('save')
        ->assertHasErrors(['variants.0.name' => 'required']);
});

it('loads an existing product into the form', function (): void {
    $product = Product::factory()->for($this->category)->create(['name' => 'Mie Ayam']);
    ProductVariant::factory()->for($product)->create(['name' => 'Reguler', 'base_price' => 32000]);

    Livewire::test('pages::catalog.edit', ['product' => $product])
        ->assertSet('name', 'Mie Ayam')
        ->assertSet('variants.0.name', 'Reguler')
        ->assertSet('variants.0.price', '32.000');
});

it('soft deletes a variant the user removed, keeping order history intact', function (): void {
    $product = Product::factory()->for($this->category)->create();
    $keep = ProductVariant::factory()->for($product)->create(['name' => 'Reguler', 'sort_order' => 0]);
    $drop = ProductVariant::factory()->for($product)->create(['name' => 'Besar', 'sort_order' => 1]);

    Livewire::test('pages::catalog.edit', ['product' => $product])
        ->call('removeVariant', 1)
        ->call('save')
        ->assertHasNoErrors();

    expect($keep->fresh()->trashed())->toBeFalse()
        ->and($drop->fresh()->trashed())->toBeTrue();
});

it('always keeps one variant row in the form', function (): void {
    Livewire::test('pages::catalog.edit')
        ->call('removeVariant', 0)
        ->assertCount('variants', 1);
});
