<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\PaymentMethod;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->owner = User::factory()->atOutlet($this->outlet, Role::Owner)->create();

    $this->cash = PaymentMethod::create(['name' => 'Tunai', 'type' => 'cash', 'mode' => PaymentMethod::MANUAL, 'opens_cash_drawer' => true, 'sort_order' => 0]);
    $this->qris = PaymentMethod::create(['name' => 'QRIS', 'type' => 'qris', 'mode' => PaymentMethod::GATEWAY_QRIS, 'fee_percent' => 0.7, 'sort_order' => 1]);
    $this->card = PaymentMethod::create(['name' => 'Kartu Debit', 'type' => 'card', 'mode' => PaymentMethod::MANUAL, 'needs_reference' => true, 'fee_percent' => 1, 'sort_order' => 2]);

    $this->actingAs($this->owner);
    app(CurrentOutlet::class)->set($this->outlet);
});

it('lets the owner add a hand-keyed method', function (): void {
    Livewire::test('pages::payments.methods')
        ->call('create')
        ->set('name', 'GoPay EDC')
        ->set('type', 'ewallet')
        ->set('feePercent', '1,5')
        ->set('needsReference', true)
        ->call('save')
        ->assertHasNoErrors();

    $method = PaymentMethod::query()->where('name', 'GoPay EDC')->sole();

    expect($method->type)->toBe('ewallet')
        ->and($method->mode)->toBe(PaymentMethod::MANUAL)
        ->and($method->fee_percent)->toBe('1.50')
        ->and($method->needs_reference)->toBeTrue()
        ->and($method->opens_cash_drawer)->toBeFalse()
        ->and($method->sort_order)->toBe(3)
        ->and(AuditLog::query()->where('action', 'payment_method.created')->exists())->toBeTrue();
});

it('switches QRIS between dynamic and static', function (): void {
    Livewire::test('pages::payments.methods')
        ->call('edit', $this->qris->id)
        ->assertSet('mode', PaymentMethod::GATEWAY_QRIS)
        ->set('mode', PaymentMethod::STATIC_QRIS)
        ->call('save')
        ->assertHasNoErrors();

    $qris = $this->qris->refresh();

    // A static code is matched by its reference later, so one is required.
    expect($qris->mode)->toBe(PaymentMethod::STATIC_QRIS)
        ->and($qris->needs_reference)->toBeTrue()
        ->and($qris->isStaticQris())->toBeTrue();
});

it('never switches off the last cash method', function (): void {
    Livewire::test('pages::payments.methods')
        ->call('edit', $this->cash->id)
        ->set('isActive', false)
        ->call('save')
        ->assertHasErrors(['isActive']);

    expect($this->cash->refresh()->is_active)->toBeTrue();
});

it('switches a method off and keeps it off the till', function (): void {
    Livewire::test('pages::payments.methods')
        ->call('edit', $this->card->id)
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->card->refresh()->is_active)->toBeFalse()
        ->and(PaymentMethod::query()->where('is_active', true)->pluck('name')->all())->not->toContain('Kartu Debit');
});

it('refuses a fee over the limit and a duplicate name', function (): void {
    Livewire::test('pages::payments.methods')
        ->call('create')
        ->set('name', 'QRIS')
        ->set('type', 'card')
        ->set('feePercent', '15')
        ->call('save')
        ->assertHasErrors(['name', 'feePercent']);
});

it('reorders the list the cashier sees', function (): void {
    Livewire::test('pages::payments.methods')->call('move', $this->card->id, -1);

    expect(PaymentMethod::query()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Tunai', 'Kartu Debit', 'QRIS']);
});

it('is the owner\'s alone', function (): void {
    $manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();

    $this->actingAs($manager)->get(route('payment-methods.index'))->assertForbidden();
    $this->actingAs($this->owner)->get(route('payment-methods.index'))->assertOk()->assertSee('Kartu Debit');
});
