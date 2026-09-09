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
    $this->outlet = Outlet::factory()->create([
        'tax_percent' => 0,
        'service_charge_percent' => 0,
        'cash_rounding' => 0,
    ]);

    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);

    $this->product = Product::factory()->for(Category::factory())->create();

    ProductVariant::factory()->for($this->product)->create([
        'base_price' => 100000,
        'is_default' => true,
    ]);
});

it('lets a cashier give a small discount on their own', function (): void {
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->product->id)
        ->set('billDiscountType', 'amount')
        ->set('billDiscountValue', 10000)
        ->call('applyBillDiscount')
        ->assertHasNoErrors()
        ->assertSet('billDiscountValue', 10000);
});

it('holds back a large discount until a supervisor signs it', function (): void {
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->product->id)
        ->set('billDiscountType', 'percent')
        ->set('billDiscountValue', 50)
        ->call('applyBillDiscount')
        // 50% of 100.000 is over the limit, so the discount never sticks.
        ->assertDispatched('needs-approval')
        ->assertSet('billDiscountValue', 0);
});

it('accepts a large discount once a manager types their PIN', function (): void {
    $manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();
    $manager->forceFill(['pin_hash' => '4821'])->save();

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->product->id)
        ->set('billDiscountType', 'percent')
        ->set('billDiscountValue', 50)
        ->set('supervisorPin', '4821')
        ->call('applyBillDiscount')
        ->assertHasNoErrors()
        ->assertSet('billDiscountValue', 50)
        // The PIN is wiped the moment it has been used.
        ->assertSet('supervisorPin', '');
});

it('rejects the PIN of someone who is not a supervisor', function (): void {
    $this->cashier->forceFill(['pin_hash' => '1111'])->save();

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->product->id)
        ->set('billDiscountType', 'percent')
        ->set('billDiscountValue', 50)
        ->set('supervisorPin', '1111')
        ->call('applyBillDiscount')
        ->assertHasErrors('supervisorPin')
        ->assertSet('billDiscountValue', 0);
});
