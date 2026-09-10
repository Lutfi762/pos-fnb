<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\TakePayment;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Pembayaran')] class extends Component {
    public Order $order;

    public string $methodId = '';

    /** Typed as text so the cashier can key "50.000" the way it is printed. */
    public string $amount = '';

    public string $reference = '';

    public function mount(Order $order): void
    {
        abort_unless($order->outlet_id === app(CurrentOutlet::class)->getOrFail()->getKey(), 403);

        $this->order = $order->load(['items', 'payments']);

        // The first tender is almost always the whole bill, so it is prefilled.
        $this->amount = Money::format($order->outstanding(), withPrefix: false);
        $this->methodId = (string) $this->methods->first()?->getKey();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        return PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function method(): ?PaymentMethod
    {
        return $this->methods->firstWhere('id', $this->methodId);
    }

    /**
     * What is still owed, read fresh so a split payment counts down on screen.
     */
    #[Computed]
    public function outstanding(): int
    {
        return $this->order->outstanding();
    }

    /**
     * Whether this bill belongs to a drawer that is currently open.
     *
     * Cash taken outside a shift still banks, but it will not show up in
     * anybody's count at the end of the night, so the screen says so.
     */
    #[Computed]
    public function hasShift(): bool
    {
        return $this->order->cashier_shift_id !== null;
    }

    /**
     * Change for the amount typed, shown before the drawer opens.
     */
    #[Computed]
    public function change(): int
    {
        if ($this->method?->opens_cash_drawer !== true) {
            return 0;
        }

        return max(0, Money::parse($this->amount) - $this->outstanding);
    }

    /**
     * Round notes a cashier is likely to be handed, above what is owed.
     *
     * @return list<int>
     */
    #[Computed]
    public function quickAmounts(): array
    {
        $owed = $this->outstanding;

        return collect([5000, 10000, 20000, 50000, 100000])
            // The next whole note up, for each denomination the guest may hold.
            ->map(fn (int $note): int => (int) (ceil($owed / $note) * $note))
            ->filter(fn (int $value): bool => $value > $owed)
            ->unique()
            ->sort()
            ->take(3)
            ->values()
            ->all();
    }

    public function setAmount(int $amount): void
    {
        $this->amount = Money::format($amount, withPrefix: false);
    }

    /**
     * Put the typed amount against the bill.
     */
    public function take(): void
    {
        $method = $this->method;

        if (! $method instanceof PaymentMethod) {
            return;
        }

        $this->validate(
            ['reference' => [$method->needs_reference ? 'required' : 'nullable', 'string', 'max:60']],
            attributes: ['reference' => __('payments.reference')],
        );

        $tendered = Money::parse($this->amount);

        if ($tendered <= 0) {
            $this->addError('amount', __('payments.amount_required'));

            return;
        }

        app(TakePayment::class)->handle(
            $this->order,
            $method,
            auth()->user(),
            $tendered,
            $tendered,
            $this->reference === '' ? null : $this->reference,
        );

        $this->order->refresh();
        unset($this->outstanding, $this->change);

        if ($this->order->outstanding() === 0) {
            $this->redirectRoute('pos.receipt', ['order' => $this->order], navigate: true);

            return;
        }

        // Split payment: line up the rest and let the cashier pick a method.
        $this->reset('reference');
        $this->setAmount($this->order->outstanding());

        Flux::toast(text: __('payments.partial', [
            'amount' => Money::format($this->order->outstanding()),
        ]));
    }

    /**
     * Undo a mis-keyed tender while the bill is still open.
     */
    public function voidLast(): void
    {
        app(TakePayment::class)->voidLast($this->order);

        $this->order->refresh()->load('payments');
        unset($this->outstanding, $this->change);

        $this->setAmount($this->order->outstanding());

        Flux::toast(variant: 'warning', text: __('payments.voided'));
    }
}; ?>

<div class="pay-shell">
    {{-- What is owed, big enough to read from arm's length. --}}
    <section class="pay-due" aria-label="{{ __('payments.outstanding') }}">
        <flux:text>{{ $order->number }}</flux:text>

        <p class="pay-due-label">{{ __('payments.outstanding') }}</p>
        <x-money :amount="$this->outstanding" class="pay-due-amount" data-test="outstanding" />

        @if ($order->paid_total > 0)
            <p class="pay-paid">
                {{ __('payments.paid') }}
                <x-money :amount="$order->paid_total" data-test="paid-total" />
            </p>
        @endif

        <ul class="pay-lines">
            @foreach ($order->items as $item)
                <li wire:key="i-{{ $item->id }}">
                    <span>{{ (int) $item->quantity }}&times; {{ $item->product_name }}</span>
                    <x-money :amount="$item->line_total" />
                </li>
            @endforeach
        </ul>
    </section>

    <section class="pay-form" aria-label="{{ __('payments.title') }}">
        @unless ($this->hasShift)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-shift">
                <flux:callout.text>
                    {{ __('payments.no_shift') }}
                    <flux:callout.link :href="route('pos.shift')" wire:navigate>{{ __('shifts.open') }}</flux:callout.link>
                </flux:callout.text>
            </flux:callout>
        @endunless

        {{-- Methods as tiles: a cashier picks by shape, not by reading. --}}
        <div class="pay-methods" role="group" aria-label="{{ __('payments.method') }}">
            @foreach ($this->methods as $method)
                <button
                    type="button"
                    wire:key="pm-{{ $method->id }}"
                    wire:click="$set('methodId', '{{ $method->id }}')"
                    @class(['pay-method', 'pay-method-on' => $methodId === $method->id])
                    data-test="payment-method"
                >{{ $method->name }}</button>
            @endforeach
        </div>

        <flux:input
            wire:model.live.debounce.200ms="amount"
            inputmode="numeric"
            class="pay-amount"
            :label="__('payments.amount')"
            data-test="pay-amount"
        />

        @if ($this->method?->opens_cash_drawer)
            <div class="pay-quick" role="group" aria-label="{{ __('payments.quick') }}">
                <flux:button size="sm" variant="subtle" wire:click="setAmount({{ $this->outstanding }})">
                    {{ __('payments.exact') }}
                </flux:button>

                @foreach ($this->quickAmounts as $quick)
                    <flux:button size="sm" variant="subtle" wire:click="setAmount({{ $quick }})" data-test="quick-amount">
                        {{ \App\Support\Money::format($quick, withPrefix: false) }}
                    </flux:button>
                @endforeach
            </div>
        @endif

        @if ($this->method?->needs_reference)
            <flux:input
                wire:model="reference"
                :label="__('payments.reference')"
                :placeholder="__('payments.reference_placeholder')"
                data-test="pay-reference"
            />
        @endif

        @if ($this->change > 0)
            <div class="pay-change" data-test="change">
                <span>{{ __('payments.change') }}</span>
                <x-money :amount="$this->change" />
            </div>
        @endif

        <div class="pay-actions">
            @if ($order->payments->isNotEmpty())
                <flux:button variant="subtle" class="pos-action" wire:click="voidLast" data-test="void-last">
                    {{ __('payments.void_last') }}
                </flux:button>
            @endif

            <flux:button variant="primary" class="pos-action" wire:click="take" data-test="settle">
                {{ __('payments.settle') }}
            </flux:button>
        </div>
    </section>
</div>
