<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Livewire\Livewire;

it('sends a user with several outlets to the picker', function (): void {
    $user = User::factory()->create();
    $user->outlets()->attach(Outlet::factory()->count(2)->create(), ['role' => Role::Cashier->value]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('outlet.select'));
});

it('skips the picker when the user has exactly one outlet', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->atOutlet($outlet)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(session(CurrentOutlet::SESSION_KEY))->toBe($outlet->id);
});

it('remembers the chosen outlet as the default for next time', function (): void {
    $user = User::factory()->create();
    $outlets = Outlet::factory()->count(2)->create();
    $user->outlets()->attach($outlets, ['role' => Role::Cashier->value]);

    $chosen = $outlets->last();

    $this->actingAs($user);

    Livewire::test('pages::outlet.select')
        ->call('choose', $chosen->id)
        ->assertRedirect(route('dashboard', absolute: false));

    expect(session(CurrentOutlet::SESSION_KEY))->toBe($chosen->id)
        ->and($user->refresh()->last_outlet_id)->toBe($chosen->id);
});

it('refuses an outlet the user is not assigned to', function (): void {
    $user = User::factory()->atOutlet(Outlet::factory()->create())->create();
    $other = Outlet::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::outlet.select')
        ->call('choose', $other->id)
        ->assertForbidden();
});

it('drops the session outlet once access is revoked', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->atOutlet($outlet)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->outlets()->detach($outlet);

    // actingAs keeps one User instance alive across requests; a real request
    // would load it fresh, so drop the stale relation cache here.
    $user->unsetRelation('outlets');

    $this->get(route('dashboard'))->assertRedirect(route('outlet.select'));
    expect(session(CurrentOutlet::SESSION_KEY))->toBeNull();
});

it('lets an owner open an outlet created after they were hired', function (): void {
    $user = User::factory()->atOutlet(Outlet::factory()->create(), Role::Owner)->create();
    $fresh = Outlet::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::outlet.select')->call('choose', $fresh->id);

    expect(session(CurrentOutlet::SESSION_KEY))->toBe($fresh->id);
});
