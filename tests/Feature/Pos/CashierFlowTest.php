<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\DiningTable;
use App\Domain\Sales\Models\Order;
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

    // One plain drink, priced so the totals are easy to read in a failure.
    $this->coffee = Product::factory()->for(Category::factory())->create(['name' => 'Kopi Susu']);
    $this->variant = ProductVariant::factory()->for($this->coffee)->create([
        'name' => 'Reguler',
        'base_price' => 20000,
        'is_default' => true,
    ]);
});

it('lets a cashier open the till', function (): void {
    $this->get(route('pos.index'))->assertOk()->assertSee(__('pos.title'));
});

it('adds a tapped product straight to the cart', function (): void {
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->assertSet('cart.0.product_name', 'Kopi Susu')
        ->assertSet('cart.0.quantity', 1)
        ->assertSet('cart.0.unit_price', 20000);
});

it('stacks a second tap onto the same line', function (): void {
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('tapProduct', $this->coffee->id)
        ->assertCount('cart', 1)
        ->assertSet('cart.0.quantity', 2);
});

it('opens the modifier sheet when the product asks a question', function (): void {
    $group = ModifierGroup::factory()->create(['name' => 'Level Gula', 'min_select' => 1, 'max_select' => 1]);
    $this->coffee->modifierGroups()->attach($group);

    $less = Modifier::factory()->for($group, 'group')->create(['name' => 'Less Sugar', 'price' => 0]);
    Modifier::factory()->for($group, 'group')->create(['name' => 'Normal', 'price' => 2000]);

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->assertCount('cart', 0)
        ->assertSet('modifierProductId', $this->coffee->id)
        ->call('toggleModifier', $group->id, $less->id)
        ->call('confirmModifiers')
        ->assertCount('cart', 1)
        ->assertSet('cart.0.modifiers.0.modifier_name', 'Less Sugar');
});

it('refuses to confirm while a required group is untouched', function (): void {
    $group = ModifierGroup::factory()->create(['min_select' => 1, 'max_select' => 1]);
    $this->coffee->modifierGroups()->attach($group);
    Modifier::factory()->for($group, 'group')->create();

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('confirmModifiers')
        ->assertHasErrors('modifiers')
        ->assertCount('cart', 0);
});

it('adds a paid modifier to the price of the line', function (): void {
    $group = ModifierGroup::factory()->create(['min_select' => 0, 'max_select' => 2]);
    $this->coffee->modifierGroups()->attach($group);
    $shot = Modifier::factory()->for($group, 'group')->create(['name' => 'Extra Shot', 'price' => 5000]);

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('toggleModifier', $group->id, $shot->id)
        ->call('confirmModifiers')
        ->assertSet('cart.0.modifier_total', 5000);
});

it('counts up and down, and drops the line at zero', function (): void {
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('increase', 0)
        ->assertSet('cart.0.quantity', 2)
        ->call('decrease', 0)
        ->assertSet('cart.0.quantity', 1)
        ->call('decrease', 0)
        ->assertCount('cart', 0);
});

it('saves a bill and leaves it open for payment', function (): void {
    $table = DiningTable::factory()->for($this->outlet)->create(['name' => 'M1']);

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('increase', 0)
        ->call('chooseTable', $table->id)
        ->call('saveBill')
        ->assertHasNoErrors()
        ->assertCount('cart', 0);

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Open)
        ->and($order->grand_total)->toBe(40000)
        ->and($order->dining_table_id)->toBe($table->id)
        ->and($order->items()->sole()->product_name)->toBe('Kopi Susu');
});

it('will not save a dine in bill without a table', function (): void {
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('saveBill')
        ->assertHasErrors('tableId');

    expect(Order::count())->toBe(0);
});

it('resumes an open bill and adds a round to the same order', function (): void {
    $table = DiningTable::factory()->for($this->outlet)->create();

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->call('chooseTable', $table->id)
        ->call('saveBill');

    Livewire::test('pages::pos.index')
        ->call('resumeOrder', Order::query()->sole()->id)
        ->assertCount('cart', 1)
        ->assertSet('cart.0.product_name', 'Kopi Susu')
        ->call('increase', 0)
        ->call('saveBill')
        ->assertHasNoErrors();

    expect(Order::count())->toBe(1)
        ->and(Order::query()->sole()->grand_total)->toBe(40000);
});

it('drops the table when the order turns into take away', function (): void {
    $table = DiningTable::factory()->for($this->outlet)->create();

    Livewire::test('pages::pos.index')
        ->call('chooseTable', $table->id)
        ->assertSet('tableId', $table->id)
        ->set('orderType', 'take_away')
        ->assertSet('tableId', '');
});

it('closes the sheet on screen once the choice is in the cart', function (): void {
    $group = ModifierGroup::factory()->create(['min_select' => 1, 'max_select' => 1]);
    $this->coffee->modifierGroups()->attach($group);
    $less = Modifier::factory()->for($group, 'group')->create(['name' => 'Less Sugar', 'price' => 0]);

    // Confirming reads the product, then clears it. The rendered page must
    // follow the cleared state, not the value read earlier in the request.
    Livewire::test('pages::pos.index')
        ->call('tapProduct', $this->coffee->id)
        ->assertSeeHtml('data-test="modifier-sheet"')
        ->call('toggleModifier', $group->id, $less->id)
        ->call('confirmModifiers')
        ->assertDontSeeHtml('data-test="modifier-sheet"')
        ->assertSee(trans_choice('pos.items_count', 1, ['count' => 1]));
});

it('counts the cart in words a cashier reads', function (): void {
    expect(trans_choice('pos.items_count', 0, ['count' => 0]))->toBe('Belum ada item')
        ->and(trans_choice('pos.items_count', 1, ['count' => 1]))->toBe('1 item')
        ->and(trans_choice('pos.items_count', 12, ['count' => 12]))->toBe('12 item');
});

it('renders menu, cart, and sheet together on every tap', function (): void {
    // A Livewire island re-renders only itself when an action fires inside
    // it: a menu card inside one never updated the cart in the browser.
    $template = file_get_contents(resource_path('views/pages/pos/⚡index.blade.php'))
        .file_get_contents(resource_path('views/pages/pos/cart.blade.php'));

    expect($template)->not->toContain('@island');
});
