<?php

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\UpdateOutletClock;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Pengaturan outlet')] class extends Component {
    public string $timezone = 'Asia/Jakarta';

    public int $dayStartsAt = 4;

    public function mount(): void
    {
        $outlet = $this->outlet->refresh();

        $this->timezone = (string) $outlet->getAttribute('timezone');
        $this->dayStartsAt = (int) $outlet->getAttribute('day_starts_at');
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
}; ?>

<div class="flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" class="font-display">{{ __('outlet.settings.title') }}</flux:heading>
        <flux:text class="mt-1 text-content-muted">{{ __('outlet.settings.subtitle', ['outlet' => $this->outlet->name]) }}</flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5">
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
