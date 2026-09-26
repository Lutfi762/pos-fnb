<?php

use App\Domain\Audit\Audit;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\CashMovement;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\Supervisors;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Shift kasir')] class extends Component {
    /** A variance bigger than this needs a manager to sign the count off. */
    public const APPROVAL_THRESHOLD = 10000;

    public string $openingCash = '';

    public string $countedCash = '';

    public string $note = '';

    public string $managerPin = '';

    public string $cashDirection = 'out';

    public string $cashAmount = '';

    public string $cashReason = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * The shift this cashier has open right now, if any.
     */
    #[Computed]
    public function shift(): ?CashierShift
    {
        return app(ShiftRegister::class)->current($this->outlet, auth()->user());
    }

    /**
     * @return array{orders: int, gross: int, methods: Collection<int, object>, cash_in: int, cash_out: int}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $shift = $this->shift;

        return $shift === null ? null : app(ShiftRegister::class)->summary($shift);
    }

    /**
     * @return Collection<int, CashMovement>
     */
    #[Computed]
    public function movements(): Collection
    {
        return $this->shift?->cashMovements()->with('user')->latest('moved_at')->get() ?? collect();
    }

    #[Computed]
    public function expected(): int
    {
        $shift = $this->shift;

        return $shift === null ? 0 : app(ShiftRegister::class)->expectedCash($shift);
    }

    /**
     * The variance as typed so far. Only shown once the cashier has entered
     * a count: seeing the target first turns a count into a guess.
     */
    #[Computed]
    public function difference(): int
    {
        return Money::parse($this->countedCash) - $this->expected;
    }

    #[Computed]
    public function needsApproval(): bool
    {
        return $this->countedCash !== '' && abs($this->difference) > self::APPROVAL_THRESHOLD;
    }

    public function open(): void
    {
        $opening = Money::parse($this->openingCash);

        app(ShiftRegister::class)->open($this->outlet, auth()->user(), $opening);

        unset($this->shift, $this->summary, $this->expected, $this->movements);

        Flux::toast(variant: 'success', text: __('shifts.opened', ['amount' => Money::format($opening)]));

        $this->reset('openingCash');
    }

    /**
     * Cash in or out of the drawer, with the reason written down.
     */
    public function moveCash(): void
    {
        $shift = $this->shift;

        if ($shift === null) {
            return;
        }

        $this->validate([
            'cashDirection' => ['required', 'in:in,out'],
            'cashAmount' => ['required', 'string'],
            'cashReason' => ['required', 'string', 'max:255'],
        ], attributes: [
            'cashAmount' => __('shifts.amount'),
            'cashReason' => __('shifts.reason'),
        ]);

        $amount = Money::parse($this->cashAmount);

        if ($amount <= 0) {
            $this->addError('cashAmount', __('shifts.amount_required'));

            return;
        }

        $movement = app(ShiftRegister::class)->moveCash($shift, auth()->user(), $this->cashDirection, $amount, $this->cashReason);

        app(Audit::class)->record('cash.moved', $movement, after: [
            'direction' => $movement->direction,
            'amount' => $movement->amount,
            'reason' => $movement->reason,
        ]);

        unset($this->summary, $this->expected, $this->movements);
        $this->reset('cashAmount', 'cashReason');

        Flux::toast(variant: 'success', text: __($movement->direction === CashMovement::IN ? 'shifts.cash_in_saved' : 'shifts.cash_out_saved', [
            'amount' => Money::format($movement->amount),
        ]));
    }

    public function close(): void
    {
        $shift = $this->shift;

        if ($shift === null) {
            return;
        }

        $this->validate(['countedCash' => ['required', 'string']], attributes: ['countedCash' => __('shifts.counted_cash')]);

        $approver = null;

        // A drawer that is out by more than the threshold is closed with a
        // written reason and a manager's PIN, never by the cashier alone.
        if ($this->needsApproval) {
            $this->validate(['note' => ['required', 'string', 'max:500']], attributes: ['note' => __('shifts.note')]);

            $approver = app(Supervisors::class)->approve($this->outlet, $this->managerPin);
            $this->reset('managerPin');

            if ($approver === null) {
                $this->addError('managerPin', __('shifts.pin_invalid'));

                return;
            }
        }

        $closed = app(ShiftRegister::class)->close(
            $shift,
            Money::parse($this->countedCash),
            $this->note === '' ? null : $this->note,
        );

        app(Audit::class)->record('shift.closed', $closed, after: [
            'counted' => $closed->closing_cash,
            'expected' => $closed->expected_cash,
            'difference' => $closed->cash_difference,
        ], note: $closed->note, approvedBy: $approver);

        unset($this->shift, $this->summary, $this->expected, $this->movements);
        $this->reset('countedCash', 'note');

        Flux::toast(variant: 'success', text: __('shifts.closed', ['amount' => Money::format($closed->cash_difference)]));
    }
}; ?>

<div class="shift-shell">
    <flux:heading size="xl" class="font-display">{{ __('shifts.title') }}</flux:heading>

    @if ($this->shift === null)
        {{-- No drawer open. Count the float in and start. --}}
        <x-empty-state
            icon="banknotes"
            :heading="__('shifts.none_open')"
            :description="__('shifts.none_open_description')"
        />

        <form wire:submit="open" class="shift-card">
            <flux:input
                wire:model="openingCash"
                inputmode="numeric"
                :label="__('shifts.opening_cash')"
                data-test="opening-cash"
            />

            <flux:button type="submit" variant="primary" class="pos-action" data-test="open-shift">
                {{ __('shifts.open') }}
            </flux:button>
        </form>
    @else
        {{-- What the drawer did this shift. The expected total is left out on
             purpose until the cashier has counted. --}}
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

            @if ($this->summary['cash_in'] > 0)
                <div>
                    <dt>{{ __('shifts.cash_in') }}</dt>
                    <dd><x-money :amount="$this->summary['cash_in']" data-test="shift-cash-in" /></dd>
                </div>
            @endif

            @if ($this->summary['cash_out'] > 0)
                <div>
                    <dt>{{ __('shifts.cash_out') }}</dt>
                    <dd><x-money :amount="-$this->summary['cash_out']" data-test="shift-cash-out" /></dd>
                </div>
            @endif
        </dl>

        {{-- Cash in and out. A drawer that pays for parking needs a line that
             says so, or the count at the end is short for no reason. --}}
        <details class="shift-card shift-moves" @if ($errors->hasAny(['cashAmount', 'cashReason'])) open @endif>
            <summary class="shift-moves-summary">{{ __('shifts.move_cash') }}</summary>

            <form wire:submit="moveCash" class="grid gap-3">
                <flux:radio.group wire:model="cashDirection" variant="segmented" size="sm">
                    <flux:radio value="out" :label="__('shifts.cash_out')" />
                    <flux:radio value="in" :label="__('shifts.cash_in')" />
                </flux:radio.group>

                <flux:input wire:model="cashAmount" inputmode="numeric" :label="__('shifts.amount')" data-test="cash-amount" />
                <flux:input wire:model="cashReason" :label="__('shifts.reason')" :placeholder="__('shifts.reason_placeholder')" data-test="cash-reason" />

                <flux:button type="submit" data-test="save-cash-move">{{ __('shifts.save_move') }}</flux:button>
            </form>

            @if ($this->movements->isNotEmpty())
                <ul class="shift-move-list">
                    @foreach ($this->movements as $movement)
                        <li wire:key="cm-{{ $movement->id }}">
                            <span>
                                {{ $movement->reason }}
                                <span class="block text-sm text-content-muted">{{ $movement->moved_at->format('H:i') }} &middot; {{ $movement->user?->name }}</span>
                            </span>
                            <x-money :amount="$movement->signedAmount()" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </details>

        <form wire:submit="close" class="shift-card">
            <flux:input
                wire:model.live.debounce.300ms="countedCash"
                inputmode="numeric"
                :label="__('shifts.counted_cash')"
                :description="__('shifts.count_first')"
                data-test="counted-cash"
            />

            @if ($countedCash !== '')
                <div class="shift-reveal">
                    <p class="shift-expected-line">
                        <span>{{ __('shifts.expected_cash') }}</span>
                        <x-money :amount="$this->expected" data-test="shift-expected" />
                    </p>

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
                </div>
            @endif

            <flux:input
                wire:model="note"
                :label="__('shifts.note')"
                :placeholder="__('shifts.note_placeholder')"
                data-test="shift-note"
            />

            @if ($this->needsApproval)
                <flux:callout variant="warning" icon="shield-exclamation">
                    <flux:callout.text>{{ __('shifts.approval_needed', ['limit' => Money::format(self::APPROVAL_THRESHOLD)]) }}</flux:callout.text>
                </flux:callout>

                <flux:input
                    wire:model="managerPin"
                    type="password"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="off"
                    :label="__('shifts.manager_pin')"
                    data-test="manager-pin"
                />
            @endif

            <flux:button type="submit" variant="primary" class="pos-action" data-test="close-shift">
                {{ __('shifts.close') }}
            </flux:button>
        </form>
    @endif
</div>
