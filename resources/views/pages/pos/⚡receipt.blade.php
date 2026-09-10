<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sales\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Struk')] class extends Component {
    public Order $order;

    public function mount(Order $order): void
    {
        abort_unless($order->outlet_id === app(CurrentOutlet::class)->getOrFail()->getKey(), 403);

        $this->order = $order->load(['items.modifiers', 'payments', 'diningTable', 'cashier', 'outlet']);
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
    </div>
</div>
