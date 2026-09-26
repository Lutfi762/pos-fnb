<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\RecipeItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\MoveStock;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\Models\Refund;
use App\Domain\Sales\RefundOrder;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create(['pin_hash' => '111111']);
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create(['pin_hash' => '654321']);

    $this->cash = PaymentMethod::create([
        'name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0, 'sort_order' => 1,
    ]);
    $this->qris = PaymentMethod::create([
        'name' => 'QRIS', 'type' => 'qris', 'opens_cash_drawer' => false, 'needs_reference' => false, 'fee_percent' => 0, 'sort_order' => 2,
    ]);

    $this->latte = ProductVariant::factory()->for(Product::factory()->for(Category::factory()))->create(['base_price' => 30_000]);
    $this->toast = ProductVariant::factory()->for(Product::factory()->for(Category::factory()))->create(['base_price' => 20_000]);

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

/**
 * A settled bill: two lattes at 30.000 and one toast at 20.000, with a
 * 10% bill discount and 11% tax on top, paid in cash yesterday.
 */
function refundableBill(object $test, ?PaymentMethod $method = null): Order
{
    $order = Order::factory()->for($test->outlet)->create([
        'user_id' => $test->cashier->id,
        'subtotal' => 80_000,
        'discount_total' => 8_000,
        'tax_percent' => 11,
        'tax_total' => 7_920,
        'grand_total' => 79_920,
        'created_at' => now()->subDay(),
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_variant_id' => $test->latte->id, 'product_name' => 'Latte', 'variant_name' => 'Reguler',
        'quantity' => 2, 'unit_price' => 30_000, 'line_total' => 60_000,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_variant_id' => $test->toast->id, 'product_name' => 'Roti Bakar', 'variant_name' => 'Reguler',
        'quantity' => 1, 'unit_price' => 20_000, 'line_total' => 20_000,
    ]);

    app(TakePayment::class)->handle($order, $method ?? $test->cash, $test->cashier, 79_920, tendered: 80_000, paidAt: now()->subDay());

    return $order->refresh()->load('items');
}

function refundLine(Order $order, string $name): OrderItem
{
    return $order->items->firstWhere('product_name', $name);
}

it('gives back the paid share of the lines chosen, discount and tax included', function (): void {
    $order = refundableBill($this);

    // One latte is 30.000 of an 80.000 subtotal: 3/8 of the 79.920 paid.
    $refund = app(RefundOrder::class)->handle(
        $order, $this->cashier, $this->manager, [refundLine($order, 'Latte')->id => 1], 'Terlalu manis', false, $this->cash,
    );

    expect($refund->amount)->toBe(29_970)
        ->and($refund->items->sole()->amount)->toBe(29_970)
        ->and((float) $refund->items->sole()->quantity)->toBe(1.0)
        ->and($order->refresh()->refunded_total)->toBe(29_970)
        ->and($order->refundable())->toBe(49_950);
});

it('gives back exactly what is left when the rest of the bill is refunded', function (): void {
    $order = refundableBill($this);
    $refunds = app(RefundOrder::class);

    $refunds->handle($order, $this->cashier, $this->manager, [refundLine($order, 'Latte')->id => 1], 'Satu', false, $this->cash);
    $last = $refunds->handle($order->refresh(), $this->cashier, $this->manager, [
        refundLine($order, 'Latte')->id => 1,
        refundLine($order, 'Roti Bakar')->id => 1,
    ], 'Sisanya', false, $this->cash);

    expect($last->amount)->toBe(79_920 - 29_970)
        ->and($last->items->sum('amount'))->toBe($last->amount)
        ->and($order->refresh()->refunded_total)->toBe(79_920)
        ->and($order->refundable())->toBe(0);
});

it('refuses to refund more than is left on a line', function (): void {
    $order = refundableBill($this);
    $refunds = app(RefundOrder::class);

    $refunds->handle($order, $this->cashier, $this->manager, [refundLine($order, 'Roti Bakar')->id => 1], 'Gosong', false, $this->cash);
    $refunds->handle($order->refresh(), $this->cashier, $this->manager, [refundLine($order, 'Roti Bakar')->id => 1], 'Lagi', false, $this->cash);
})->throws(HttpException::class);

it('refuses a refund approved by someone who is not a supervisor, however it is called', function (): void {
    $order = refundableBill($this);

    expect(fn () => app(RefundOrder::class)->handle(
        $order, $this->cashier, $this->cashier, [refundLine($order, 'Latte')->id => 1], 'Coba', false, $this->cash,
    ))->toThrow(HttpException::class);

    expect(Refund::query()->count())->toBe(0)
        ->and($order->refresh()->refunded_total)->toBe(0);
});

it('refuses a refund on a bill that is not settled', function (): void {
    $order = Order::factory()->for($this->outlet)->create(['user_id' => $this->cashier->id, 'subtotal' => 10_000, 'grand_total' => 10_000]);
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Teh', 'variant_name' => 'Reguler', 'quantity' => 1, 'unit_price' => 10_000, 'line_total' => 10_000,
    ]);

    app(RefundOrder::class)->handle($order, $this->cashier, $this->manager, [$item->id => 1], 'Batal', false, $this->cash);
})->throws(HttpException::class);

it('dates the refund today and leaves yesterday\'s bill as it was rung up', function (): void {
    $order = refundableBill($this);
    $paidAt = $order->paid_at->toDateTimeString();

    app(RefundOrder::class)->handle($order, $this->cashier, $this->manager, [refundLine($order, 'Latte')->id => 2], 'Salah pesan', false, $this->cash);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->grand_total)->toBe(79_920)
        ->and($order->subtotal)->toBe(80_000)
        ->and($order->paid_at->toDateTimeString())->toBe($paidAt)
        ->and($order->payments()->sum('amount'))->toBe(79_920)
        ->and($order->items()->sum('line_total'))->toBe(80_000)
        ->and(Refund::query()->sole()->refunded_at->isToday())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'order.refunded')->sole()->approved_by)->toBe($this->manager->id);
});

it('puts ingredients back only when the cashier says the goods can be resold', function (): void {
    $milk = InventoryItem::factory()->create(['average_cost' => 20]);
    RecipeItem::create(['product_variant_id' => $this->latte->id, 'inventory_item_id' => $milk->id, 'quantity' => 150]);
    app(MoveStock::class)->record($this->outlet, $milk, StockMovementType::Purchase, 1000, unitCost: 20);

    $order = refundableBill($this);
    $refunds = app(RefundOrder::class);
    $level = fn (): float => (float) app(MoveStock::class)->level($this->outlet, $milk)->quantity;

    expect($level())->toBe(700.0);

    $refunds->handle($order, $this->cashier, $this->manager, [refundLine($order, 'Latte')->id => 1], 'Sudah diminum', false, $this->cash);

    expect($level())->toBe(700.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Return)->count())->toBe(0);

    $refunds->handle($order->refresh(), $this->cashier, $this->manager, [refundLine($order, 'Latte')->id => 1], 'Belum disentuh', true, $this->cash);

    expect($level())->toBe(850.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Return)->sole()->source_id)->toBe(Refund::query()->where('restock', true)->sole()->id);
});

it('takes cash refunds out of the drawer the shift expects, and leaves QRIS refunds alone', function (): void {
    $shifts = app(ShiftRegister::class);
    $shift = $shifts->open($this->outlet, $this->cashier, 100_000);

    $order = refundableBill($this);
    $refunds = app(RefundOrder::class);

    $before = $shifts->expectedCash($shift);

    $refunds->handle($order, $this->cashier, $this->manager, [refundLine($order, 'Roti Bakar')->id => 1], 'Gosong', false, $this->cash);
    $afterCash = $shifts->expectedCash($shift);

    $refunds->handle($order->refresh(), $this->cashier, $this->manager, [refundLine($order, 'Latte')->id => 1], 'Dingin', false, $this->qris);

    // 20.000 of 80.000 is a quarter of 79.920.
    expect($before - $afterCash)->toBe(19_980)
        ->and($shifts->expectedCash($shift))->toBe($afterCash)
        ->and($shifts->summary($shift)['refunds'])->toBe(19_980);
});

it('refunds from the receipt once a supervisor types their PIN', function (): void {
    $order = refundableBill($this);
    $latte = refundLine($order, 'Latte');

    Livewire::test('pages::pos.receipt', ['order' => $order])
        ->call('startRefund')
        ->set("refundQuantities.{$latte->id}", '1')
        ->assertSet('refundEstimate', 29_970)
        ->set('refundReason', 'Terlalu manis')
        ->set('refundPin', '111111')
        ->call('refund')
        ->assertHasErrors('refundPin')
        ->set('refundPin', '654321')
        ->call('refund')
        ->assertHasNoErrors()
        ->assertSet('refunding', false)
        ->assertSee('Terlalu manis');

    expect(Refund::query()->sole()->approved_by)->toBe($this->manager->id)
        ->and($order->refresh()->refunded_total)->toBe(29_970);
});

it('will not refund from the receipt without a PIN', function (): void {
    $order = refundableBill($this);

    Livewire::test('pages::pos.receipt', ['order' => $order])
        ->call('startRefund')
        ->set('refundQuantities.'.refundLine($order, 'Latte')->id, '1')
        ->set('refundReason', 'Coba')
        ->call('refund')
        ->assertHasErrors('refundPin');

    expect(Refund::query()->count())->toBe(0);
});
