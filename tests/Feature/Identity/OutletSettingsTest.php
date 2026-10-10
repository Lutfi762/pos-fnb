<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\CalculateTotals;
use App\Domain\Sales\CartLine;
use App\Domain\Sales\TaxProfile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create([
        'name' => 'Meja Kemang',
        'tax_percent' => 0,
        'service_charge_percent' => 0,
        'price_includes_tax' => true,
        'cash_rounding' => 0,
        'has_kitchen_display' => true,
    ]);

    $this->owner = User::factory()->atOutlet($this->outlet, Role::Owner)->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();

    app(CurrentOutlet::class)->set($this->outlet);
});

it('lets a manager change the outlet profile and receipt lines', function (): void {
    $this->actingAs($this->manager);

    Livewire::test('pages::outlet.settings')
        ->assertSet('name', 'Meja Kemang')
        ->set('phone', '021 555 0101')
        ->set('receiptHeader', 'MEJA - KEMANG')
        ->set('receiptFooter', 'Wi-Fi: mejakopi')
        ->set('hasKitchenDisplay', false)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $outlet = $this->outlet->refresh();

    expect($outlet->receipt_header)->toBe('MEJA - KEMANG')
        ->and($outlet->receipt_footer)->toBe('Wi-Fi: mejakopi')
        ->and($outlet->phone)->toBe('021 555 0101')
        ->and($outlet->has_kitchen_display)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'outlet.profile_changed')->count())->toBe(1);
});

it('treats a blank receipt header as the outlet name', function (): void {
    $this->outlet->update(['receipt_header' => 'Lama']);
    $this->actingAs($this->manager);

    Livewire::test('pages::outlet.settings')
        ->set('receiptHeader', '   ')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($this->outlet->refresh()->receipt_header)->toBeNull();
});

it('keeps tax and rounding away from managers', function (): void {
    $this->actingAs($this->manager);

    Livewire::test('pages::outlet.settings')
        ->assertSee(__('outlet.settings.pricing_owner_only'))
        ->assertDontSee('data-test="outlet-pricing-save"', escape: false)
        ->set('taxPercent', '11')
        ->call('savePricing')
        ->assertForbidden();

    expect((float) $this->outlet->refresh()->tax_percent)->toBe(0.0);
});

it('lets the owner set tax, service, and rounding for the next bill', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::outlet.settings')
        ->set('taxPercent', '11')
        ->set('servicePercent', '5')
        ->set('priceIncludesTax', false)
        ->set('cashRounding', 1000)
        ->call('savePricing')
        ->assertHasNoErrors();

    $outlet = $this->outlet->refresh();

    expect($outlet->tax_percent)->toBe('11.00')
        ->and($outlet->service_charge_percent)->toBe('5.00')
        ->and($outlet->price_includes_tax)->toBeFalse()
        ->and($outlet->cash_rounding)->toBe(1000)
        ->and(AuditLog::query()->where('action', 'outlet.pricing_changed')->sole()->before['cash_rounding'])->toBe(0);

    // Rp100.000 + 5% service = 105.000; 11% tax on that = 11.550;
    // 116.550 rounded to the nearest thousand = 117.000.
    $totals = app(CalculateTotals::class)->handle([new CartLine(unitPrice: 100_000)], TaxProfile::fromOutlet($outlet));

    expect($totals->serviceChargeTotal)->toBe(5_000)
        ->and($totals->taxTotal)->toBe(11_550)
        ->and($totals->rounding)->toBe(450)
        ->and($totals->grandTotal)->toBe(117_000);
});

it('reads a decimal comma and refuses impossible rates', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::outlet.settings')
        ->set('taxPercent', '12,5')
        ->call('savePricing')
        ->assertHasNoErrors();

    expect($this->outlet->refresh()->tax_percent)->toBe('12.50');

    Livewire::test('pages::outlet.settings')
        ->assertSet('taxPercent', '12,5')
        ->set('taxPercent', '40')
        ->set('servicePercent', '-1')
        ->set('cashRounding', 250)
        ->call('savePricing')
        ->assertHasErrors(['taxPercent', 'servicePercent', 'cashRounding']);
});
