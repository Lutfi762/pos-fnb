<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\SaveStaff;
use App\Domain\Identity\StaffPins;
use App\Domain\Sales\Supervisors;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['name' => 'A Kemang']);
    $this->other = Outlet::factory()->create(['name' => 'B Senopati']);

    $this->owner = User::factory()->atOutlet($this->outlet, Role::Owner)->create(['pin_hash' => '246810']);
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create(['pin_hash' => '135790']);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create(['pin_hash' => '102030']);
});

function staffAs(User $user, Outlet $outlet): void
{
    test()->actingAs($user);
    app(CurrentOutlet::class)->set($outlet);
}

it('lets an owner hire a manager who can sign in and approve', function (): void {
    staffAs($this->owner, $this->outlet);

    Livewire::test('pages::staff.index')
        ->call('create')
        ->set('name', 'Rina Manajer')
        ->set('email', 'Rina@Meja.test')
        ->set('role', 'manager')
        ->set('password', 'rahasia-rina')
        ->set('pin', '582914')
        ->call('save')
        ->assertHasNoErrors();

    $rina = User::query()->where('email', 'rina@meja.test')->sole();

    expect($rina->is_active)->toBeTrue()
        ->and($rina->email_verified_at)->not->toBeNull()
        ->and($rina->roleAt($this->outlet))->toBe(Role::Manager)
        ->and($rina->verifyPin('582914'))->toBeTrue()
        ->and(app(Supervisors::class)->approve($this->outlet, '582914')?->is($rina))->toBeTrue();

    $log = AuditLog::query()->where('action', 'staff.created')->sole();

    // The audit trail says a PIN was set, never what it was.
    expect($log->after['pin_changed'])->toBeTrue()
        ->and(json_encode($log->after))->not->toContain('582914')
        ->and(json_encode($log->after))->not->toContain('rahasia-rina');
});

it('lets an owner place staff at several outlets', function (): void {
    staffAs($this->owner, $this->outlet);

    Livewire::test('pages::staff.index')
        ->call('create')
        ->set('name', 'Budi Kasir')
        ->set('email', 'budi@meja.test')
        ->set('role', 'cashier')
        ->set('password', 'password-budi')
        ->set('pin', '736251')
        ->set('outletIds', [$this->outlet->id, $this->other->id])
        ->call('save')
        ->assertHasNoErrors();

    $budi = User::query()->where('email', 'budi@meja.test')->sole();

    expect($budi->roleAt($this->outlet))->toBe(Role::Cashier)
        ->and($budi->roleAt($this->other))->toBe(Role::Cashier);
});

it('keeps a manager to the floor staff of their own outlet', function (): void {
    staffAs($this->manager, $this->outlet);

    $page = Livewire::test('pages::staff.index');

    expect(array_map(fn (Role $role): string => $role->value, $page->instance()->roles))
        ->toBe(['cashier', 'kitchen', 'warehouse']);

    $page->call('create')
        ->set('name', 'Calon Manajer')
        ->set('email', 'calon@meja.test')
        ->set('role', 'manager')
        ->set('password', 'password-calon')
        ->set('pin', '736251')
        ->call('save')
        ->assertHasErrors('role');

    // Even past the form, the action itself refuses.
    expect(fn () => app(SaveStaff::class)->handle($this->manager, $this->outlet, [
        'name' => 'Calon Manajer', 'email' => 'calon@meja.test', 'role' => 'owner', 'password' => 'password-calon', 'pin' => '736251',
    ]))->toThrow(HttpException::class);

    // A manager hires at their outlet only, whatever the request says.
    $page->call('create')
        ->set('name', 'Dewi Dapur')
        ->set('email', 'dewi@meja.test')
        ->set('role', 'kitchen')
        ->set('password', 'password-dewi')
        ->set('pin', '736251')
        ->set('outletIds', [$this->other->id])
        ->call('save')
        ->assertHasNoErrors();

    $dewi = User::query()->where('email', 'dewi@meja.test')->sole()->load('outlets');

    expect($dewi->outlets->modelKeys())->toBe([$this->outlet->id]);
});

it('stops a manager from editing an owner, another manager, or themselves', function (): void {
    $peer = User::factory()->atOutlet($this->outlet, Role::Manager)->create();

    staffAs($this->manager, $this->outlet);

    expect(SaveStaff::canManage($this->manager, $this->outlet, $this->owner))->toBeFalse()
        ->and(SaveStaff::canManage($this->manager, $this->outlet, $peer))->toBeFalse()
        ->and(SaveStaff::canManage($this->manager, $this->outlet, $this->manager))->toBeFalse()
        ->and(SaveStaff::canManage($this->manager, $this->outlet, $this->cashier))->toBeTrue();

    Livewire::test('pages::staff.index')->call('edit', $this->owner->id)->assertForbidden();
    Livewire::test('pages::staff.index')->call('toggleActive', $peer->id)->assertForbidden();
    Livewire::test('pages::staff.index')->call('toggleActive', $this->manager->id)->assertForbidden();

    expect($peer->fresh()->is_active)->toBeTrue();
});

it('edits a cashier without touching their secrets unless asked', function (): void {
    staffAs($this->manager, $this->outlet);

    Livewire::test('pages::staff.index')
        ->call('edit', $this->cashier->id)
        ->assertSet('name', $this->cashier->name)
        ->set('name', 'Kasir Pagi')
        ->set('role', 'warehouse')
        ->call('save')
        ->assertHasNoErrors();

    $cashier = $this->cashier->fresh();

    expect($cashier->name)->toBe('Kasir Pagi')
        ->and($cashier->roleAt($this->outlet))->toBe(Role::Warehouse)
        ->and($cashier->verifyPin('102030'))->toBeTrue();
});

it('refuses a PIN a colleague already uses', function (): void {
    staffAs($this->owner, $this->outlet);

    Livewire::test('pages::staff.index')
        ->call('create')
        ->set('name', 'Kembar')
        ->set('email', 'kembar@meja.test')
        ->set('role', 'cashier')
        ->set('password', 'password-kembar')
        ->set('pin', '135790')
        ->call('save')
        ->assertHasErrors(['pin']);

    expect(User::query()->where('email', 'kembar@meja.test')->exists())->toBeFalse();
});

it('allows the same PIN at an outlet that shares no till', function (): void {
    $elsewhere = User::factory()->atOutlet($this->other, Role::Cashier)->create(['pin_hash' => '918273']);

    app(StaffPins::class)->assertUsable('918273', null, [$this->outlet->id], false);

    // An owner stands at every till, so the same PIN is refused for one.
    expect(fn () => app(StaffPins::class)->assertUsable('918273', null, [$this->outlet->id], true))
        ->toThrow(ValidationException::class);

    // And staff anywhere are refused a PIN an owner holds.
    expect(fn () => app(StaffPins::class)->assertUsable('246810', $elsewhere, [$this->other->id], false))
        ->toThrow(ValidationException::class);
});

it('refuses PINs that are easy to guess or the wrong length', function (string $pin): void {
    expect(fn () => app(StaffPins::class)->assertUsable($pin, null, [$this->outlet->id], false))
        ->toThrow(ValidationException::class);
})->with(['000000', '777777', '123456', '987654', '12345', '1234567', '12a456']);

it('signs a deactivated person out of the session they already have', function (): void {
    staffAs($this->manager, $this->outlet);

    Livewire::test('pages::staff.index')
        ->call('toggleActive', $this->cashier->id)
        ->assertHasNoErrors();

    expect($this->cashier->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'staff.deactivated')->exists())->toBeTrue();

    $this->actingAs($this->cashier->fresh());

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    // And they no longer approve or unlock anything.
    expect(app(Supervisors::class)->approve($this->outlet, '102030'))->toBeNull();
});

it('keeps cashiers out of staff management', function (): void {
    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);

    $this->get(route('staff.index'))->assertForbidden();
});

it('shows the staff list to a manager', function (): void {
    staffAs($this->manager, $this->outlet);

    $this->get(route('staff.index'))
        ->assertOk()
        ->assertSee($this->cashier->name)
        ->assertSee('data-test="staff-edit-'.$this->cashier->id.'"', escape: false)
        ->assertDontSee('data-test="staff-edit-'.$this->owner->id.'"', escape: false);
});

it('refuses to approve with a PIN two supervisors share', function (): void {
    // Older data from before PINs were checked for clashes.
    $this->manager->forceFill(['pin_hash' => '246810'])->save();

    $supervisors = app(Supervisors::class);

    expect($supervisors->approve($this->outlet, '246810'))->toBeNull()
        ->and($supervisors->wasAmbiguous())->toBeTrue()
        ->and($supervisors->refusal('salah'))->toBe(__('staff.pin_ambiguous'))
        // Not a wrong guess, so it does not count toward the lockout.
        ->and($supervisors->lockedOut($this->outlet))->toBeFalse();

    expect($supervisors->approve($this->outlet, '999999'))->toBeNull()
        ->and($supervisors->wasAmbiguous())->toBeFalse()
        ->and($supervisors->refusal('salah'))->toBe('salah');
});

it('lets anyone set their own PIN after confirming it', function (): void {
    staffAs($this->cashier, $this->outlet);

    Livewire::test('pages::settings.pin')
        ->set('pin', '582914')
        ->set('pin_confirmation', '582913')
        ->call('save')
        ->assertHasErrors(['pin']);

    Livewire::test('pages::settings.pin')
        ->set('pin', '135790')
        ->set('pin_confirmation', '135790')
        ->call('save')
        ->assertHasErrors(['pin']);

    Livewire::test('pages::settings.pin')
        ->set('pin', '582914')
        ->set('pin_confirmation', '582914')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('pin', '');

    expect($this->cashier->fresh()->verifyPin('582914'))->toBeTrue();
});

it('asks for the password again before the PIN page opens', function (): void {
    staffAs($this->cashier, $this->outlet);

    $this->get(route('pin.edit'))->assertRedirect(route('password.confirm'));
});
