<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;

it('lets a manager open the catalog', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->atOutlet($outlet, Role::Manager)->create();

    $this->actingAs($user)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertSee(__('catalog.title'));
});

it('keeps a cashier out of the catalog', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->atOutlet($outlet, Role::Cashier)->create();

    $this->actingAs($user)
        ->get(route('catalog.index'))
        ->assertForbidden();
});

it('hides the catalog link from a cashier', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->atOutlet($outlet, Role::Cashier)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('catalog.index'));
});

it('requires authentication', function (): void {
    $this->get(route('catalog.index'))->assertRedirect(route('login'));
});
