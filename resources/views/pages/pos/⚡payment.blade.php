<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sales\Enums\ChargeStatus;
use App\Domain\Sales\Gateway\FakeGateway;
use App\Domain\Sales\Gateway\GatewayException;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\QrisPayments;
use App\Domain\Sales\ReceiveWebhook;
use App\Domain\Sales\TakePayment;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::app')] #[Title('Pembayaran')] class extends Component {
    use WithFileUploads;

    public Order $order;

    /** The dynamic QRIS code on screen, if any. */
    #[Locked]
    public ?string $chargeId = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $proof = null;

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

        // A reload while a code is showing picks it back up rather than
        // issuing a second one the guest might also pay.
        $pending = $order->charges()->where('status', ChargeStatus::Pending)->latest()->first();

        if ($pending instanceof PaymentCharge) {
            $this->chargeId = $pending->getKey();
            $this->methodId = (string) $pending->payment_method_id;
        }
    }

    /**
     * Livewire brings the bill back without its lines on each request.
     */
    public function hydrate(): void
    {
        $this->order->loadMissing(['items', 'payments']);
    }

    #[Computed]
    public function charge(): ?PaymentCharge
    {
        return $this->chargeId === null
            ? null
            : PaymentCharge::query()->where('order_id', $this->order->getKey())->find($this->chargeId);
    }

    /**
     * The test button shows only with the fake gateway, and never in production.
     */
    #[Computed]
    public function canSimulate(): bool
    {
        return app(PaymentGateway::class) instanceof FakeGateway && ! app()->isProduction();
    }

    #[Computed]
    public function listensLive(): bool
    {
        return config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key'));
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

    public function updatedMethodId(): void
    {
        // Codes are locked in once shown: another method waits until the
        // code is paid, expires, or is cancelled.
        if ($this->charge?->status === ChargeStatus::Pending) {
            $this->methodId = (string) $this->charge->payment_method_id;
        }

        $this->resetErrorBag();
    }

    /**
     * Ask the gateway for a QRIS code for the amount typed.
     */
    public function showQris(): void
    {
        $method = $this->method;
        abort_unless($method instanceof PaymentMethod && $method->usesGateway(), 422);

        $amount = Money::parse($this->amount);

        if ($amount <= 0) {
            $this->addError('amount', __('payments.amount_required'));

            return;
        }

        if ($amount > $this->outstanding) {
            $this->addError('amount', __('qris.more_than_owed'));

            return;
        }

        try {
            $charge = app(QrisPayments::class)->start($this->order, $method, auth()->user(), $amount);
        } catch (GatewayException $exception) {
            $this->addError('amount', $exception->getMessage());

            return;
        }

        $this->chargeId = $charge->getKey();
        unset($this->charge);
    }

    /**
     * Polled while a code is showing, and called when the websocket says the
     * code changed. Moves on to the receipt once the bill is paid.
     */
    public function checkCharge(bool $force = false): void
    {
        $charge = $this->charge;

        if (! $charge instanceof PaymentCharge) {
            return;
        }

        $charge = app(QrisPayments::class)->check($charge, $force);
        unset($this->charge);

        if ($charge->status !== ChargeStatus::Paid) {
            return;
        }

        $this->order->refresh()->load(['items', 'payments']);
        unset($this->outstanding, $this->change);
        $this->chargeId = null;

        if ($this->order->outstanding() === 0) {
            $this->redirectRoute('pos.receipt', ['order' => $this->order], navigate: true);

            return;
        }

        $this->setAmount($this->order->outstanding());

        Flux::toast(variant: 'success', text: __('qris.paid', ['amount' => Money::format($charge->paid_amount)]).' '.__('payments.partial', [
            'amount' => Money::format($this->order->outstanding()),
        ]));
    }

    public function cancelCharge(): void
    {
        if ($this->charge instanceof PaymentCharge) {
            app(QrisPayments::class)->cancel($this->charge);
        }

        $this->chargeId = null;
        unset($this->charge);

        Flux::toast(text: __('qris.cancelled'));
    }

    /**
     * Put the code aside once it expired or failed, to make a new one.
     */
    public function dismissCharge(): void
    {
        abort_if($this->charge?->status === ChargeStatus::Pending, 409);

        $this->chargeId = null;
        unset($this->charge);
    }

    /**
     * Fake gateway only: play the guest paying, through the same signed
     * webhook path a real gateway uses.
     */
    public function simulatePay(): void
    {
        abort_unless($this->canSimulate && $this->charge instanceof PaymentCharge, 403);

        $fake = app(PaymentGateway::class);
        app(ReceiveWebhook::class)->handle($fake->name(), $fake->pay($this->charge));

        $this->checkCharge(force: true);
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

        if ($method->usesGateway()) {
            $this->showQris();

            return;
        }

        abort_if($this->charge?->status === ChargeStatus::Pending, 409, __('qris.other_methods_locked'));

        $this->validate([
            'reference' => [$method->needs_reference ? 'required' : 'nullable', 'string', 'max:60'],
            'proof' => ['nullable', 'image', 'max:5120'],
        ], attributes: [
            'reference' => __('payments.reference'),
            'proof' => __('qris.proof'),
        ]);

        $tendered = Money::parse($this->amount);

        if ($tendered <= 0) {
            $this->addError('amount', __('payments.amount_required'));

            return;
        }

        // Only a static QRIS payment keeps a photo; anything else would be
        // a guest's screen stored for no reason.
        $proofPath = $method->isStaticQris() && $this->proof !== null
            ? $this->proof->store('payment-proofs/'.now()->format('Y/m'), 'local')
            : null;

        app(TakePayment::class)->handle(
            $this->order,
            $method,
            auth()->user(),
            $tendered,
            $tendered,
            $this->reference === '' ? null : $this->reference,
            proofPath: $proofPath ?: null,
        );

        $this->reset('proof');

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

        @php($charge = $this->charge)
        @php($codeShowing = $charge?->status === \App\Domain\Sales\Enums\ChargeStatus::Pending)

        {{-- Methods as tiles: a cashier picks by shape, not by reading. --}}
        <div class="pay-methods" role="group" aria-label="{{ __('payments.method') }}">
            @foreach ($this->methods as $method)
                <button
                    type="button"
                    wire:key="pm-{{ $method->id }}"
                    wire:click="$set('methodId', '{{ $method->id }}')"
                    @class(['pay-method', 'pay-method-on' => $methodId === $method->id])
                    @disabled($codeShowing && $methodId !== $method->id)
                    @if ($method->usesGateway()) data-requires-online @endif
                    data-test="payment-method"
                >{{ $method->name }}</button>
            @endforeach
        </div>

        @if ($charge !== null)
            {{-- A dynamic QRIS code. The money is on the bill only once the
                 gateway confirms it, never because a phone was held up. --}}
            <div
                class="qris-panel"
                x-data="qrisCode({ qr: @js($codeShowing ? $charge->qr_string : null), outlet: @js($order->outlet_id), charge: @js($charge->id), live: @js($this->listensLive) })"
                @if ($codeShowing) wire:poll.4s="checkCharge" @endif
                data-test="qris-panel"
                data-status="{{ $charge->status->value }}"
            >
                @if ($codeShowing)
                    <p class="qris-title">{{ __('qris.scan') }}</p>
                    <x-money :amount="$charge->amount" class="qris-amount" data-test="qris-amount" />

                    <div class="qris-code" x-ref="code" role="img" aria-label="{{ __('qris.scan') }}" wire:ignore></div>

                    <p class="qris-waiting" role="status" aria-live="polite">
                        <span class="qris-dot" aria-hidden="true"></span>
                        {{ __('qris.waiting') }}
                    </p>
                    <p class="qris-meta">{{ __('qris.expires_in', ['time' => $charge->expires_at?->format('H:i')]) }}</p>
                    <p class="qris-meta">{{ __('qris.live_hint') }}</p>

                    <div class="pay-actions">
                        <flux:button variant="subtle" class="pos-action" wire:click="cancelCharge" data-test="qris-cancel">{{ __('qris.cancel') }}</flux:button>
                        <flux:button class="pos-action" wire:click="checkCharge(true)" data-test="qris-check">{{ __('qris.check') }}</flux:button>
                    </div>

                    @if ($this->canSimulate)
                        <div class="qris-test">
                            <flux:text class="text-sm">{{ __('qris.simulate_hint') }}</flux:text>
                            <flux:button size="sm" variant="primary" icon="beaker" wire:click="simulatePay" data-test="qris-simulate">{{ __('qris.simulate') }}</flux:button>
                        </div>
                    @endif
                @else
                    <flux:callout :variant="$charge->status === \App\Domain\Sales\Enums\ChargeStatus::Failed ? 'danger' : 'warning'" icon="exclamation-triangle">
                        <flux:callout.text>
                            {{ $charge->status === \App\Domain\Sales\Enums\ChargeStatus::Expired ? __('qris.expired') : $charge->status->label() }}
                            @if ($charge->failure_reason)
                                <span class="block">{{ $charge->failure_reason }}</span>
                            @endif
                        </flux:callout.text>
                    </flux:callout>

                    <flux:button class="pos-action" wire:click="dismissCharge" data-test="qris-dismiss">{{ __('qris.new_code') }}</flux:button>
                @endif
            </div>
        @else
            <flux:input
                wire:model.live.debounce.200ms="amount"
                inputmode="numeric"
                class="pay-amount"
                :label="$this->method?->usesGateway() ? __('qris.amount') : __('payments.amount')"
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

            @if ($this->method?->isStaticQris())
                <flux:text class="text-sm" data-test="static-qris-hint">{{ __('qris.static_hint') }}</flux:text>
            @endif

            @if ($this->method?->needs_reference)
                <flux:input
                    wire:model="reference"
                    :label="__('payments.reference')"
                    :placeholder="__('payments.reference_placeholder')"
                    data-test="pay-reference"
                />
            @endif

            @if ($this->method?->isStaticQris())
                <flux:input
                    type="file"
                    wire:model="proof"
                    accept="image/*"
                    capture="environment"
                    :label="__('qris.proof')"
                    :description="__('qris.proof_hint')"
                    data-test="pay-proof"
                />
            @endif

            @if ($this->method?->usesGateway())
                <flux:text class="text-sm qris-offline-note" data-test="qris-online-note">{{ __('qris.offline') }}</flux:text>
            @endif

            @if ($this->change > 0)
                <div class="pay-change" data-test="change">
                    <span>{{ __('payments.change') }}</span>
                    <x-money :amount="$this->change" />
                </div>
            @endif

            <div class="pay-actions">
                @if ($order->payments->isNotEmpty() && $order->payments->sortByDesc('paid_at')->first()?->payment_charge_id === null)
                    <flux:button variant="subtle" class="pos-action" wire:click="voidLast" data-test="void-last">
                        {{ __('payments.void_last') }}
                    </flux:button>
                @endif

                @if ($this->method?->usesGateway())
                    <flux:button variant="primary" class="pos-action" icon="qr-code" wire:click="showQris" wire:loading.attr="disabled" data-test="qris-show">
                        {{ __('qris.show_code', ['amount' => \App\Support\Money::format(\App\Support\Money::parse($amount))]) }}
                    </flux:button>
                @else
                    <flux:button variant="primary" class="pos-action" wire:click="take" wire:loading.attr="disabled" wire:target="take,proof" data-test="settle">
                        {{ __('payments.settle') }}
                    </flux:button>
                @endif
            </div>
        @endif

        @if ($this->listensLive)
            @vite('resources/js/echo.js')
        @endif
    </section>
</div>
