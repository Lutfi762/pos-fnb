<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\ScreenLock;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->cashier = User::factory()->withPin()->atOutlet($this->outlet)->create();
});

it('blocks every screen while locked', function (): void {
    $this->actingAs($this->cashier)->get(route('dashboard'))->assertOk();

    $this->post(route('lock.store'))->assertRedirect(route('lock'));

    $this->get(route('dashboard'))->assertRedirect(route('lock'));
    $this->get(route('lock'))->assertOk();
});

it('unlocks with the right pin', function (): void {
    $this->actingAs($this->cashier)->get(route('dashboard'));
    $this->post(route('lock.store'));

    Livewire::test('pages::lock')
        ->set('userId', $this->cashier->id)
        ->set('pin', '123456')
        ->call('unlock')
        ->assertHasNoErrors();

    expect(app(ScreenLock::class)->isLocked())->toBeFalse();
    $this->get(route('dashboard'))->assertOk();
});

it('rejects a wrong pin and stays locked', function (): void {
    $this->actingAs($this->cashier)->get(route('dashboard'));
    $this->post(route('lock.store'));

    Livewire::test('pages::lock')
        ->set('userId', $this->cashier->id)
        ->set('pin', '000000')
        ->call('unlock')
        ->assertHasErrors('pin');

    expect(app(ScreenLock::class)->isLocked())->toBeTrue();
});

it('hands the terminal to another staff member at the same outlet', function (): void {
    $manager = User::factory()->withPin('987654')->atOutlet($this->outlet, Role::Manager)->create();

    $this->actingAs($this->cashier)->get(route('dashboard'));
    $this->post(route('lock.store'));

    Livewire::test('pages::lock')
        ->set('userId', $manager->id)
        ->set('pin', '987654')
        ->call('unlock')
        ->assertHasNoErrors();

    expect(Auth::id())->toBe($manager->id)
        ->and(session(CurrentOutlet::SESSION_KEY))->toBe($this->outlet->id);
});

it('hides staff from other outlets', function (): void {
    $stranger = User::factory()->withPin()->atOutlet(Outlet::factory()->create())->create();

    $this->actingAs($this->cashier)->get(route('dashboard'));
    $this->post(route('lock.store'));

    Livewire::test('pages::lock')
        ->set('userId', $stranger->id)
        ->set('pin', '123456')
        ->call('unlock')
        ->assertHasErrors('pin');

    expect(Auth::id())->toBe($this->cashier->id);
});

it('never unlocks for a user without a pin', function (): void {
    $noPin = User::factory()->atOutlet($this->outlet)->create();

    $this->actingAs($this->cashier)->get(route('dashboard'));
    $this->post(route('lock.store'));

    Livewire::test('pages::lock')
        ->set('userId', $noPin->id)
        ->set('pin', '123456')
        ->call('unlock')
        ->assertHasErrors('pin');
});
