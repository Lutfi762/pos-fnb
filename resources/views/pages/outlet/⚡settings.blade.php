<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\SaveStaff;
use App\Domain\Identity\UpdateOutletClock;
use App\Domain\Identity\UpdateOutletSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Outlet settings')] class extends Component {
    public string $timezone = 'Asia/Jakarta';

    public int $dayStartsAt = 4;

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public string $receiptHeader = '';

    public string $receiptFooter = '';

    public bool $hasKitchenDisplay = true;

    public string $taxPercent = '0';

    public string $servicePercent = '0';

    public bool $priceIncludesTax = true;

    public int $cashRounding = 0;

    public function mount(): void
    {
        $outlet = $this->outlet->refresh();

        $this->timezone = (string) $outlet->getAttribute('timezone');
        $this->dayStartsAt = (int) $outlet->getAttribute('day_starts_at');

        $this->name = (string) $outlet->name;
        $this->phone = (string) $outlet->phone;
        $this->address = (string) $outlet->address;
        $this->receiptHeader = (string) $outlet->receipt_header;
        $this->receiptFooter = (string) $outlet->receipt_footer;
        $this->hasKitchenDisplay = (bool) $outlet->has_kitchen_display;

        $this->taxPercent = self::percent($outlet->tax_percent);
        $this->servicePercent = self::percent($outlet->service_charge_percent);
        $this->priceIncludesTax = (bool) $outlet->price_includes_tax;
        $this->cashRounding = (int) $outlet->cash_rounding;
    }

    /**
     * Tax, service, and rounding are the owner's to change.
     */
    #[Computed]
    public function ownsPricing(): bool
    {
        return SaveStaff::roleOf(auth()->user(), $this->outlet) === Role::Owner;
    }

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * Today's trading day as the settings on screen would make it, so the
     * owner sees what the hour means before saving it.
     *
     * @return array{date: string, from: string, to: string}
     */
    #[Computed]
    public function example(): array
    {
        $zone = in_array($this->timezone, UpdateOutletClock::ZONES, true) ? $this->timezone : 'Asia/Jakarta';
        $hour = max(0, min(UpdateOutletClock::LATEST_START, $this->dayStartsAt));
        $start = now()->setTimezone($zone)->subHours($hour)->startOfDay()->addHours($hour);

        return [
            'date' => $start->translatedFormat('l, d M Y'),
            'from' => $start->translatedFormat('d M H:i'),
            'to' => $start->addDay()->subMinute()->translatedFormat('d M H:i'),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'timezone' => ['required', 'in:'.implode(',', UpdateOutletClock::ZONES)],
            'dayStartsAt' => ['required', 'integer', 'min:0', 'max:'.UpdateOutletClock::LATEST_START],
        ], attributes: [
            'timezone' => __('outlet.settings.timezone'),
            'dayStartsAt' => __('outlet.settings.day_starts_at'),
        ]);

        app(UpdateOutletClock::class)->handle($this->outlet, $this->timezone, $this->dayStartsAt, auth()->user());

        Flux::toast(variant: 'success', text: __('outlet.settings.saved'));
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'receiptHeader' => ['nullable', 'string', 'max:120'],
            'receiptFooter' => ['nullable', 'string', 'max:500'],
            'hasKitchenDisplay' => ['boolean'],
        ], attributes: [
            'name' => __('outlet.settings.name'),
            'phone' => __('outlet.settings.phone'),
            'address' => __('outlet.settings.address'),
            'receiptHeader' => __('outlet.settings.receipt_header'),
            'receiptFooter' => __('outlet.settings.receipt_footer'),
        ]);

        app(UpdateOutletSettings::class)->profile($this->outlet, [
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'receipt_header' => $this->receiptHeader,
            'receipt_footer' => $this->receiptFooter,
            'has_kitchen_display' => $this->hasKitchenDisplay,
        ], auth()->user());

        unset($this->outlet);

        Flux::toast(variant: 'success', text: __('outlet.settings.saved'));
    }

    public function savePricing(): void
    {
        abort_unless($this->ownsPricing, 403);

        // People type "11,5" as often as "11.5".
        $this->taxPercent = str_replace(',', '.', trim($this->taxPercent));
        $this->servicePercent = str_replace(',', '.', trim($this->servicePercent));

        $this->validate([
            'taxPercent' => ['required', 'numeric', 'min:0', 'max:'.UpdateOutletSettings::MAX_TAX_PERCENT],
            'servicePercent' => ['required', 'numeric', 'min:0', 'max:'.UpdateOutletSettings::MAX_SERVICE_PERCENT],
            'priceIncludesTax' => ['boolean'],
            'cashRounding' => ['required', 'integer', 'in:'.implode(',', UpdateOutletSettings::ROUNDING)],
        ], attributes: [
            'taxPercent' => __('outlet.settings.tax_percent'),
            'servicePercent' => __('outlet.settings.service_percent'),
            'cashRounding' => __('outlet.settings.cash_rounding'),
        ]);

        app(UpdateOutletSettings::class)->pricing($this->outlet, [
            'tax_percent' => $this->taxPercent,
            'service_charge_percent' => $this->servicePercent,
            'price_includes_tax' => $this->priceIncludesTax,
            'cash_rounding' => (int) $this->cashRounding,
        ], auth()->user());

        Flux::toast(variant: 'success', text: __('outlet.settings.pricing_saved'));
    }

    /**
     * "11.00" reads better as "11", "12.50" as "12,5".
     */
    private static function percent(mixed $value): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));
    }
}; ?>

<div class="flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" class="font-display">{{ __('outlet.settings.title') }}</flux:heading>
        <flux:text class="mt-1 text-content-muted">{{ __('outlet.settings.subtitle', ['outlet' => $this->outlet->name]) }}</flux:text>
    </div>

    <form wire:submit="saveProfile" class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5" data-test="outlet-profile">
        <flux:heading size="lg">{{ __('outlet.settings.profile_heading') }}</flux:heading>

        <flux:input wire:model="name" :label="__('outlet.settings.name')" required data-test="outlet-name" />
        <flux:input wire:model="phone" type="tel" :label="__('outlet.settings.phone')" data-test="outlet-phone" />
        <flux:textarea wire:model="address" :label="__('outlet.settings.address')" rows="2" data-test="outlet-address" />
        <flux:input wire:model="receiptHeader" :label="__('outlet.settings.receipt_header')" :description="__('outlet.settings.receipt_header_hint')" data-test="outlet-receipt-header" />
        <flux:textarea wire:model="receiptFooter" :label="__('outlet.settings.receipt_footer')" :description="__('outlet.settings.receipt_footer_hint')" rows="2" data-test="outlet-receipt-footer" />
        <flux:switch wire:model="hasKitchenDisplay" :label="__('outlet.settings.kitchen_display')" :description="__('outlet.settings.kitchen_display_hint')" data-test="outlet-kitchen-display" />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" class="min-h-11" data-test="outlet-profile-save">{{ __('outlet.settings.save') }}</flux:button>
        </div>
    </form>

    <form wire:submit="savePricing" class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5" data-test="outlet-pricing">
        <div>
            <flux:heading size="lg">{{ __('outlet.settings.pricing_heading') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">{{ $this->ownsPricing ? __('outlet.settings.pricing_hint') : __('outlet.settings.pricing_owner_only') }}</flux:text>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <flux:input wire:model="taxPercent" inputmode="decimal" :label="__('outlet.settings.tax_percent')" :disabled="! $this->ownsPricing" class="tabular-nums" data-test="outlet-tax">
                <x-slot name="iconTrailing"><span class="text-content-muted" aria-hidden="true">%</span></x-slot>
            </flux:input>
            <flux:input wire:model="servicePercent" inputmode="decimal" :label="__('outlet.settings.service_percent')" :disabled="! $this->ownsPricing" class="tabular-nums" data-test="outlet-service">
                <x-slot name="iconTrailing"><span class="text-content-muted" aria-hidden="true">%</span></x-slot>
            </flux:input>
        </div>

        <flux:switch wire:model="priceIncludesTax" :label="__('outlet.settings.price_includes_tax')" :description="__('outlet.settings.price_includes_tax_hint')" :disabled="! $this->ownsPricing" data-test="outlet-includes-tax" />

        <flux:select wire:model="cashRounding" :label="__('outlet.settings.cash_rounding')" :description="__('outlet.settings.cash_rounding_hint')" :disabled="! $this->ownsPricing" data-test="outlet-rounding">
            @foreach (\App\Domain\Identity\UpdateOutletSettings::ROUNDING as $step)
                <flux:select.option :value="$step">{{ $step === 0 ? __('outlet.settings.no_rounding') : __('outlet.settings.round_to', ['amount' => \App\Support\Money::format($step)]) }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->ownsPricing)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:link :href="route('payment-methods.index')" wire:navigate class="inline-flex min-h-11 items-center" data-test="outlet-payment-methods">{{ __('payment_methods.title') }}</flux:link>
                <flux:button type="submit" variant="primary" class="min-h-11" data-test="outlet-pricing-save">{{ __('outlet.settings.save') }}</flux:button>
            </div>
        @endif
    </form>

    <form wire:submit="save" class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5">
        <flux:heading size="lg">{{ __('outlet.settings.clock_heading') }}</flux:heading>

        <flux:select wire:model.live="timezone" :label="__('outlet.settings.timezone')" data-test="outlet-timezone">
            @foreach (\App\Domain\Identity\UpdateOutletClock::ZONES as $zone)
                <flux:select.option :value="$zone">{{ __('outlet.settings.zones.'.$zone) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="dayStartsAt" :label="__('outlet.settings.day_starts_at')" :description="__('outlet.settings.day_starts_hint')" data-test="outlet-day-start">
            @foreach (range(0, \App\Domain\Identity\UpdateOutletClock::LATEST_START) as $hour)
                <flux:select.option :value="$hour">{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}.00</flux:select.option>
            @endforeach
        </flux:select>

        <flux:callout icon="clock" data-test="outlet-example">
            <flux:callout.text>
                {{ __('outlet.settings.example', $this->example) }}
                <span class="mt-1 block">{{ __('outlet.settings.applies_hint') }}</span>
            </flux:callout.text>
        </flux:callout>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" class="min-h-11" data-test="outlet-save">{{ __('outlet.settings.save') }}</flux:button>
        </div>
    </form>
</div>
