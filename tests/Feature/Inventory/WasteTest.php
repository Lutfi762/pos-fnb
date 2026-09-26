<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\LogWaste;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\MoveStock;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->kitchen = User::factory()->atOutlet($this->outlet, Role::Kitchen)->create();
    $this->milk = InventoryItem::factory()->create(['name' => 'Susu segar', 'average_cost' => 0]);

    // 2 litres at 18 a millilitre, then 2 more at 22: the average is 20.
    app(MoveStock::class)->record($this->outlet, $this->milk, StockMovementType::Purchase, 2000, unitCost: 18);
    app(MoveStock::class)->record($this->outlet, $this->milk, StockMovementType::Purchase, 2000, unitCost: 22);

    $this->actingAs($this->kitchen);
    app(CurrentOutlet::class)->set($this->outlet);
});

it('takes waste off the shelf and values it at the average cost', function (): void {
    $movement = app(LogWaste::class)->handle($this->outlet, $this->milk, 250, 'spilled', ' tumpah saat steaming ', $this->kitchen);

    expect((float) $movement->quantity)->toBe(-250.0)
        ->and($movement->unit_cost)->toBe(20)
        ->and($movement->reason)->toBe('spilled')
        ->and($movement->note)->toBe('tumpah saat steaming')
        ->and(app(LogWaste::class)->value($movement))->toBe(5_000)
        ->and((float) app(MoveStock::class)->level($this->outlet, $this->milk)->quantity)->toBe(3_750.0)
        ->and(AuditLog::query()->where('action', 'stock.wasted')->sole()->after['value'])->toBe(5_000);
});

it('rounds a fractional waste value to whole rupiah', function (): void {
    $movement = app(LogWaste::class)->handle($this->outlet, $this->milk, 0.025, 'expired', null, $this->kitchen);

    // 0,025 ml at 20 is half a rupiah.
    expect(app(LogWaste::class)->value($movement))->toBe(1);
});

it('refuses a reason that is not on the list', function (): void {
    app(LogWaste::class)->handle($this->outlet, $this->milk, 10, 'stolen', null, $this->kitchen);
})->throws(HttpException::class);

it('refuses to log nothing', function (): void {
    app(LogWaste::class)->handle($this->outlet, $this->milk, 0, 'spilled', null, $this->kitchen);
})->throws(HttpException::class);

it('lets the kitchen log waste from its page and totals the day', function (): void {
    Livewire::test('pages::stock.waste')
        ->set('itemId', $this->milk->id)
        ->set('quantity', '100')
        ->set('reason', 'wrong_order')
        ->call('save')
        ->assertHasNoErrors()
        ->set('itemId', $this->milk->id)
        ->set('quantity', '50,5')
        ->set('reason', 'spilled')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('total', 3_010)
        ->assertSee('Salah buat')
        ->set('reasonFilter', 'spilled')
        ->assertSet('total', 1_010);

    expect(StockMovement::query()->where('type', StockMovementType::Waste)->count())->toBe(2);
});

it('opens the waste page for the kitchen but keeps it from cashiers', function (): void {
    $this->get(route('stock.waste'))->assertOk();

    $cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($cashier)->get(route('stock.waste'))->assertForbidden();
});
