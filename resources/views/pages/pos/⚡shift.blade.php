<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\ShiftRegister;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Shift kasir')] class extends Component {
    public string $openingCash = '';

    public string $countedCash = '';

    public string $note = '';

    /**
     * The shift this cashier has open right now, if any.
     */
    #[Computed]
    public function shift(): ?CashierShift
    {
        return app(ShiftRegister::class)->current(
            app(CurrentOutlet::class)->getOrFail(),
            auth()->user(),
        );
    }

    /**
     * @return array{orders: int, gross: int, methods: \Illuminate\Support\Collection<int, object>}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $shift = $this->shift;

        return $shift === null ? null : app(ShiftRegister::class)->summary($shift);
    }

    #[Computed]
    public function expected(): int
    {
        $shift = $this->shift;

        return $shift === null ? 0 : app(ShiftRegister::class)->expectedCash($shift);
    }

    /**
     * The variance as typed so far, so a cashier sees it before committing.
     */
    #[Computed]
    public function difference(): int
    {
        return Money::parse($this->countedCash) - $this->expected;
    }

    public function open(): void
    {
        app(ShiftRegister::class)->open(
            app(CurrentOutlet::class)->getOrFail(),
            auth()->user(),
            Money::parse($this->openingCash),
        );

        unset($this->shift, $this->summary, $this->expected);

        Flux::toast(variant: 'success', text: __('shifts.opened', [
            'amount' => Money::format(Money::parse($this->openingCash)),
        ]));

        $this->reset('openingCash');
    }

    public function close(): void
    {
        $shift = $this->shift;

        if ($shift === null) {
            return;
        }

        $closed = app(ShiftRegister::class)->close(
            $shift,
            Money::parse($this->countedCash),
            $this->note === '' ? null : $this->note,
        );

        unset($this->shift, $this->summary, $this->expected);
        $this->reset('countedCash', 'note');

        Flux::toast(variant: 'success', text: __('shifts.closed', [
            'amount' => Money::format($closed->cash_difference),
        ]));
    }
}; ?>

<div class="shift-shell">
    <flux:heading size="xl">{{ __('shifts.title') }}</flux:heading>

    @if ($this->shift === null)
        {{-- No drawer open. Count the float in and start. --}}
        <x-empty-state
            icon="banknotes"
            :heading="__('shifts.none_open')"
            :description="__('shifts.none_open_description')"
        />

        <div class="shift-card">
            <flux:input
                wire:model="openingCash"
                inputmode="numeric"
                :label="__('shifts.opening_cash')"
                data-test="opening-cash"
            />

            <flux:button variant="primary" class="pos-action" wire:click="open" data-test="open-shift">
                {{ __('shifts.open') }}
            </flux:button>
        </div>
    @else
        {{-- What the drawer did this shift, before it gets counted. --}}
        <dl class="shift-summary">
            <div>
                <dt>{{ __('shifts.opened_at') }}</dt>
                <dd>{{ $this->shift->opened_at->translatedFormat('d M Y H:i') }}</dd>
            </div>
            <div>
                <dt>{{ __('shifts.opening_cash') }}</dt>
                <dd><x-money :amount="$this->shift->opening_cash" /></dd>
            </div>
            <div>
                <dt>{{ __('shifts.orders') }}</dt>
                <dd class="numeric tabular-nums">{{ $this->summary['orders'] }}</dd>
            </div>
            <div>
                <dt>{{ __('shifts.gross') }}</dt>
                <dd><x-money :amount="$this->summary['gross']" data-test="shift-gross" /></dd>
            </div>

            @foreach ($this->summary['methods'] as $method)
                <div wire:key="sm-{{ $method->name }}">
                    <dt>{{ $method->name }}</dt>
                    <dd><x-money :amount="$method->total" /></dd>
                </div>
            @endforeach

            <div class="shift-expected">
                <dt>{{ __('shifts.expected_cash') }}</dt>
                <dd><x-money :amount="$this->expected" data-test="shift-expected" /></dd>
            </div>
        </dl>

        <div class="shift-card">
            <flux:input
                wire:model.live.debounce.300ms="countedCash"
                inputmode="numeric"
                :label="__('shifts.counted_cash')"
                data-test="counted-cash"
            />

            @if ($countedCash !== '')
                <p @class(['shift-difference', 'shift-difference-off' => $this->difference !== 0]) data-test="shift-difference">
                    <span>
                        @if ($this->difference > 0)
                            {{ __('shifts.over') }}
                        @elseif ($this->difference < 0)
                            {{ __('shifts.short') }}
                        @else
                            {{ __('shifts.balanced') }}
                        @endif
                    </span>

                    <x-money :amount="$this->difference" />
                </p>
            @endif

            <flux:input
                wire:model="note"
                :label="__('shifts.note')"
                :placeholder="__('shifts.note_placeholder')"
                data-test="shift-note"
            />

            <flux:button variant="primary" class="pos-action" wire:click="close" data-test="close-shift">
                {{ __('shifts.close') }}
            </flux:button>
        </div>
    @endif
</div>
