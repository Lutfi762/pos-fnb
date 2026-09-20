<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\OrderNumber;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['code' => 'MKM']);
});

/**
 * The registry reads the current request, so each "terminal" in these tests is
 * a request carrying its own cookie, exactly as a second tablet would.
 */
function terminal(?string $fingerprint = null): DeviceRegistry
{
    $request = Request::create('/pos');

    if (is_string($fingerprint)) {
        $request->cookies->set(DeviceRegistry::COOKIE, $fingerprint);
    }

    app()->instance('request', $request);

    return new DeviceRegistry($request);
}

it('registers a terminal the first time it is seen', function (): void {
    $device = terminal()->current($this->outlet);

    expect(Device::query()->count())->toBe(1)
        ->and($device->outlet_id)->toBe($this->outlet->getKey())
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->name)->toBe('Perangkat tanpa nama');
});

it('keeps one row for a terminal that comes back', function (): void {
    $first = terminal()->current($this->outlet);
    $again = terminal($first->fingerprint)->current($this->outlet);

    expect($again->getKey())->toBe($first->getKey())
        ->and(Device::query()->count())->toBe(1);
});

it('gives two terminals two rows', function (): void {
    terminal()->current($this->outlet);
    terminal()->current($this->outlet);

    expect(Device::query()->count())->toBe(2);
});

it('moves a terminal carried to another outlet instead of cloning it', function (): void {
    $other = Outlet::factory()->create();

    $device = terminal()->current($this->outlet);
    $moved = terminal($device->fingerprint)->current($other);

    expect($moved->getKey())->toBe($device->getKey())
        ->and($moved->outlet_id)->toBe($other->getKey())
        ->and(Device::query()->count())->toBe(1);
});

it('numbers bills per terminal so two tills never collide', function (): void {
    $till = terminal();
    $kiosk = terminal();

    $numbers = new OrderNumber($till);
    $prefix = $numbers->devicePrefix($this->outlet);

    expect($prefix)->toHaveLength(4)
        ->and((new OrderNumber($kiosk))->devicePrefix($this->outlet))->not->toBe($prefix);
});

it('keeps the same bill prefix after the session is thrown away', function (): void {
    $user = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->actingAs($user);

    $device = terminal()->current($this->outlet);
    $before = (new OrderNumber(terminal($device->fingerprint)))->devicePrefix($this->outlet);

    // What a screen lock timeout or an overnight logout does to a tablet.
    session()->flush();

    expect((new OrderNumber(terminal($device->fingerprint)))->devicePrefix($this->outlet))->toBe($before);
});
