<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Outlet;
use App\Models\User;

it('defaults to Indonesian', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Masuk ke akun Anda');
});

it('switches the interface language for the session', function (): void {
    $this->from(route('login'))
        ->post(route('locale.store', 'en'))
        ->assertRedirect(route('login'));

    expect(session('locale'))->toBe('en');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Log in to your account')
        ->assertDontSee('Masuk ke akun Anda');
});

it('rejects a locale the interface is not translated into', function (): void {
    $this->post(route('locale.store', 'id'), [])->assertRedirect();

    $this->post('/locale/fr')->assertNotFound();

    expect(session('locale'))->toBe('id');
});

it('keeps the language choice on the device, not the account', function (): void {
    $user = User::factory()->atOutlet(Outlet::factory()->create())->create();

    $this->post(route('locale.store', 'en'));
    $this->actingAs($user);

    // "Keluar" in Indonesian: the sidebar is proof the whole chrome switched,
    // not only the page body.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Log out')
        ->assertDontSee('Keluar');
});

it('offers the switcher on the locked screen so a new shift can change it', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->withPin('123456')->atOutlet($outlet)->create();

    $this->actingAs($user);
    $this->post(route('lock.store'));

    $this->get(route('lock'))
        ->assertOk()
        ->assertSee('data-test="language-switcher"', escape: false);
});
