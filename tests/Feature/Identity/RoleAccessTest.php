<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'outlet', 'role:manager,owner'])
        ->get('_test/managers-only', fn (): string => 'ok');
});

it('lets an allowed role through', function (): void {
    $outlet = Outlet::factory()->create();
    $manager = User::factory()->atOutlet($outlet, Role::Manager)->create();

    $this->actingAs($manager)->get('_test/managers-only')->assertOk();
});

it('blocks a role that is not listed', function (): void {
    $outlet = Outlet::factory()->create();
    $cashier = User::factory()->atOutlet($outlet, Role::Cashier)->create();

    $this->actingAs($cashier)->get('_test/managers-only')->assertForbidden();
});

it('judges the role held at the current outlet, not elsewhere', function (): void {
    $kemang = Outlet::factory()->create(['name' => 'A Kemang']);
    $senopati = Outlet::factory()->create(['name' => 'B Senopati']);

    $user = User::factory()->create();
    $user->outlets()->attach($kemang, ['role' => Role::Manager->value]);
    $user->outlets()->attach($senopati, ['role' => Role::Cashier->value]);

    $this->actingAs($user);

    session(['current_outlet_id' => $kemang->id]);
    $this->get('_test/managers-only')->assertOk();

    session(['current_outlet_id' => $senopati->id]);
    $this->get('_test/managers-only')->assertForbidden();
});
