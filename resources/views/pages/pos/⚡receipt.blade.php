<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\RefundOrder;
use App\Domain\Sales\Supervisors;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Struk')] class extends Component {
    public Order $order;

    public bool $refunding = false;

    /** @var array<string, string> order item id to quantity typed */
    public array $refundQuantities = [];

    public string $refundReason = '';

    public bool $refundRestock = false;

    public string $refundMethodId = '';

    public string $refundPin = '';

    public function mount(Order $order): void
    {
        abort_unless($order->outlet_id === app(CurrentOutlet::class)->getOrFail()->getKey(), 403);

        $this->order = $this->loaded($order);
    }

    /**
     * Livewire brings the bill back on every request without its relations,
     * so load them again before the receipt renders.
     */
    public function hydrate(): void
    {
        $this->order = $this->loaded($this->order);
    }

    private function loaded(Order $order): Order
    {
        return $order->load([
            'items.modifiers',
            'items.refundItems',
            'payments',
            'diningTable',
            'cashier',
            'outlet',
            'refunds' => fn ($query) => $query->with(['requester', 'approver'])->latest('refunded_at'),
        ]);
    }

    #[Computed]
    public function canRefund(): bool
    {
        return $this->order->status === OrderStatus::Paid && $this->order->refundable() > 0;
    }

    /**
     * Where the money goes back: cash, or the method the guest paid with.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function refundMethods(): Collection
    {
        $used = $this->order->payments->pluck('payment_method_id')->filter()->all();

        return PaymentMethod::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('type', 'cash')->orWhereIn('id', $used))
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function refundEstimate(): int
    {
        return app(RefundOrder::class)->preview($this->order, $this->refundQuantities);
    }

    public function remaining(string $itemId): float
    {
        $item = $this->order->items->firstWhere('id', $itemId);

        return $item === null ? 0 : app(RefundOrder::class)->remaining($item);
    }

    public function startRefund(): void
    {
        abort_unless($this->canRefund, 409);

        $this->refunding = true;
        $this->refundQuantities = $this->order->items
            ->mapWithKeys(fn ($item): array => [$item->id => '0'])
            ->all();
        $this->refundMethodId = (string) ($this->order->payments->first()?->payment_method_id ?? $this->refundMethods->first()?->getKey());
        $this->reset('refundReason', 'refundRestock', 'refundPin');
        $this->resetErrorBag();
    }

    public function cancelRefund(): void
    {
        $this->reset('refunding', 'refundQuantities', 'refundReason', 'refundRestock', 'refundPin');
    }

    /**
     * Give the money back, once a supervisor has typed their PIN.
     */
    public function refund(): void
    {
        $this->validate([
            'refundReason' => ['required', 'string', 'max:255'],
            'refundMethodId' => ['required', 'string'],
            'refundQuantities.*' => ['nullable', 'numeric', 'min:0'],
            'refundPin' => ['required', 'string'],
        ], attributes: [
            'refundReason' => __('refunds.reason'),
            'refundPin' => __('refunds.supervisor_pin'),
        ]);

        $supervisors = app(Supervisors::class);
        $outlet = app(CurrentOutlet::class)->getOrFail();

        if ($supervisors->lockedOut($outlet)) {
            $this->addError('refundPin', __('refunds.locked'));

            return;
        }

        $approver = $supervisors->approve($outlet, $this->refundPin);
        $this->reset('refundPin');

        if ($approver === null) {
            $this->addError('refundPin', __('refunds.pin_invalid'));

            return;
        }

        $method = $this->refundMethods->firstWhere('id', $this->refundMethodId);
        abort_unless($method instanceof PaymentMethod, 422);

        $refund = app(RefundOrder::class)->handle(
            $this->order,
            auth()->user(),
            $approver,
            array_filter($this->refundQuantities, fn ($quantity): bool => (float) $quantity > 0),
            $this->refundReason,
            $this->refundRestock,
            $method,
        );

        $this->order = $this->loaded($this->order->refresh());
        $this->cancelRefund();
        unset($this->canRefund, $this->refundEstimate);

        Flux::toast(variant: 'success', text: __('refunds.done', ['amount' => Money::format($refund->amount)]));
    }
}; ?>

<div class="receipt-shell">
    {{--
        The receipt itself, sized to 58mm thermal paper. Printing is left to
        the browser: a thermal printer installed on the machine prints this
        page as-is, and everything that is not the paper is hidden by @media print.
    --}}
    <article class="receipt" data-test="receipt">
        <header class="receipt-head">
            <h1 class="receipt-title">{{ $order->outlet->receipt_header ?? $order->outlet->name }}</h1>
            @if ($order->outlet->address)
                <p>{{ $order->outlet->address }}</p>
            @endif
        </header>

        <dl class="receipt-meta">
            <div>
                <dt>{{ __('receipts.number') }}</dt>
                <dd>{{ $order->number }}</dd>
            </div>
            <div>
                <dt>{{ __('receipts.at') }}</dt>
                <dd>{{ ($order->paid_at ?? $order->placed_at)?->translatedFormat('d M Y H:i') }}</dd>
            </div>
            <div>
                <dt>{{ __('receipts.cashier') }}</dt>
                <dd>{{ $order->cashier->name }}</dd>
            </div>
            <div>
                <dt>{{ $order->type->label() }}</dt>
                <dd>{{ $order->diningTable?->name ?? '-' }}</dd>
            </div>
        </dl>

        <ul class="receipt-lines">
            @foreach ($order->items as $item)
                <li wire:key="r-{{ $item->id }}">
                    <p class="receipt-line-name">
                        <span>{{ (int) $item->quantity }}&times; {{ $item->product_name }}</span>
                        <x-money :amount="$item->line_total" :prefix="false" />
                    </p>

                    @foreach ($item->modifiers as $modifier)
                        <p class="receipt-line-sub">+ {{ $modifier->modifier_name }}</p>
                    @endforeach

                    @if ($item->note)
                        <p class="receipt-line-sub">{{ $item->note }}</p>
                    @endif
                </li>
            @endforeach
        </ul>

        <dl class="receipt-totals">
            <div>
                <dt>{{ __('pos.subtotal') }}</dt>
                <dd><x-money :amount="$order->subtotal" :prefix="false" /></dd>
            </div>

            @if ($order->discount_total > 0)
                <div>
                    <dt>{{ __('pos.discount') }}</dt>
                    <dd><x-money :amount="-$order->discount_total" :prefix="false" /></dd>
                </div>
            @endif

            @if ($order->service_charge_total > 0)
                <div>
                    <dt>{{ __('pos.service_charge') }} {{ $order->service_charge_percent + 0 }}%</dt>
                    <dd><x-money :amount="$order->service_charge_total" :prefix="false" /></dd>
                </div>
            @endif

            @if ($order->tax_total > 0)
                <div>
                    <dt>{{ __('pos.tax') }} {{ $order->tax_percent + 0 }}%</dt>
                    <dd><x-money :amount="$order->tax_total" :prefix="false" /></dd>
                </div>
            @endif

            @if ($order->rounding !== 0)
                <div>
                    <dt>{{ __('pos.rounding') }}</dt>
                    <dd><x-money :amount="$order->rounding" :prefix="false" /></dd>
                </div>
            @endif

            <div class="receipt-grand">
                <dt>{{ __('pos.total') }}</dt>
                <dd><x-money :amount="$order->grand_total" data-test="receipt-total" /></dd>
            </div>

            @foreach ($order->payments as $payment)
                <div wire:key="p-{{ $payment->id }}">
                    <dt>{{ $payment->method_name }}</dt>
                    <dd><x-money :amount="$payment->tendered ?? $payment->amount" :prefix="false" /></dd>
                </div>
            @endforeach

            @if ($order->change_total > 0)
                <div>
                    <dt>{{ __('payments.change') }}</dt>
                    <dd><x-money :amount="$order->change_total" :prefix="false" data-test="receipt-change" /></dd>
                </div>
            @endif
        </dl>

        @if ($order->refunds->isNotEmpty())
            <section class="receipt-refunds" data-test="receipt-refunds">
                <p class="receipt-refunds-title">{{ __('refunds.history') }}</p>

                @foreach ($order->refunds as $refund)
                    <div wire:key="rf-{{ $refund->id }}">
                        <p class="receipt-line-name">
                            <span>{{ $refund->refunded_at->translatedFormat('d M H:i') }} &middot; {{ $refund->method_name }}</span>
                            <x-money :amount="-$refund->amount" :prefix="false" />
                        </p>
                        <p class="receipt-line-sub">{{ $refund->reason }}@if ($refund->restock) &middot; {{ __('refunds.restocked') }}@endif</p>
                        <p class="receipt-line-sub">{{ __('refunds.by', ['name' => $refund->requester?->name, 'approver' => $refund->approver?->name]) }}</p>
                    </div>
                @endforeach

                <p class="receipt-line-name receipt-refunds-net">
                    <span>{{ __('refunds.net_paid') }}</span>
                    <x-money :amount="$order->grand_total - $order->refunded_total" data-test="receipt-net" />
                </p>
            </section>
        @endif

        @if ($order->price_includes_tax)
            <p class="receipt-note">{{ __('receipts.tax_included') }}</p>
        @endif

        <footer class="receipt-foot">
            <p>{{ $order->outlet->receipt_footer }}</p>
        </footer>
    </article>

    {{-- Not part of the paper: these are hidden when the page prints. --}}
    <div class="receipt-actions">
        <flux:button variant="primary" class="pos-action" onclick="window.print()" data-test="print">
            {{ __('payments.print') }}
        </flux:button>

        <flux:button variant="subtle" class="pos-action" :href="route('pos.index')" wire:navigate data-test="back-to-pos">
            {{ __('payments.new_order') }}
        </flux:button>

        @if ($this->canRefund)
            <flux:button variant="subtle" class="pos-action" icon="arrow-uturn-left" wire:click="startRefund" data-test="start-refund">
                {{ __('refunds.start') }}
            </flux:button>
        @endif
    </div>

    {{-- Refund. The bill stays as it was; this writes a new record, dated
         today, and a supervisor signs it off with their PIN. --}}
    @if ($refunding)
        <div class="pos-sheet-backdrop" wire:click.self="cancelRefund" data-test="refund-sheet">
            <form wire:submit="refund" class="pos-sheet" role="dialog" aria-modal="true" aria-labelledby="refund-title">
                <span class="pos-sheet-handle" aria-hidden="true"></span>

                <flux:heading id="refund-title" size="lg">{{ __('refunds.heading', ['number' => $order->number]) }}</flux:heading>
                <flux:text class="text-content-muted">{{ __('refunds.intro') }}</flux:text>

                <ul class="refund-lines">
                    @foreach ($order->items as $item)
                        @php($left = $this->remaining($item->id))
                        <li wire:key="rl-{{ $item->id }}" @class(['refund-line', 'opacity-50' => $left <= 0])>
                            <span class="min-w-0">
                                <span class="block font-medium">{{ $item->product_name }}</span>
                                <span class="block text-sm text-content-muted">
                                    {{ __('refunds.refundable') }}: {{ \App\Support\Quantity::format($left) }} &middot; <x-money :amount="$item->line_total" />
                                </span>
                            </span>
                            <flux:input
                                type="number"
                                min="0"
                                :max="$left"
                                step="1"
                                size="sm"
                                class="refund-qty"
                                wire:model.live.debounce.250ms="refundQuantities.{{ $item->id }}"
                                :disabled="$left <= 0"
                                :label="__('refunds.quantity')"
                                label:class="sr-only"
                                data-test="refund-qty"
                            />
                        </li>
                    @endforeach
                </ul>

                <flux:textarea wire:model="refundReason" rows="2" :label="__('refunds.reason')" :placeholder="__('refunds.reason_placeholder')" data-test="refund-reason" />

                <flux:select wire:model="refundMethodId" :label="__('refunds.method')">
                    @foreach ($this->refundMethods as $method)
                        <flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:checkbox wire:model="refundRestock" :label="__('refunds.restock')" :description="__('refunds.restock_hint')" />

                <flux:input
                    wire:model="refundPin"
                    type="password"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="off"
                    :label="__('refunds.supervisor_pin')"
                    :description="__('refunds.supervisor_hint')"
                    data-test="refund-pin"
                />

                <flux:error name="refundQuantities" />

                <div class="pay-change">
                    <span>{{ __('refunds.estimate') }}</span>
                    <x-money :amount="$this->refundEstimate" data-test="refund-estimate" />
                </div>

                <div class="pos-sheet-actions">
                    <flux:button variant="subtle" class="pos-action" wire:click="cancelRefund">{{ __('refunds.cancel') }}</flux:button>
                    <flux:button type="submit" variant="danger" class="pos-action" :disabled="$this->refundEstimate <= 0" data-test="confirm-refund">
                        {{ __('refunds.confirm', ['amount' => Money::format($this->refundEstimate)]) }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif
</div>
