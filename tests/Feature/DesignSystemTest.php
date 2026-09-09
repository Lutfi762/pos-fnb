<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Outlet;
use App\Models\User;

it('requires authentication', function (): void {
    $this->get(route('design-system'))->assertRedirect(route('login'));
});

it('renders the design system reference for a signed in user', function (): void {
    $this->actingAs(User::factory()->atOutlet(Outlet::factory()->create())->create());

    $this->get(route('design-system'))
        ->assertOk()
        ->assertSee('Sistem Desain')
        ->assertSee('Rp1.234.567');
});
