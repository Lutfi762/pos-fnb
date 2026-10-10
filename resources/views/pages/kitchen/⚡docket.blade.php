<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Kitchen\Enums\Station;
use App\Domain\Sales\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The fallback for an outlet with no screen in the kitchen: the same ticket,
 * on paper. Printed from the browser like the customer receipt, so a thermal
 * printer shared with the till needs no extra setup.
 */
new #[Layout('layouts::app')] #[Title('Kitchen docket')] class extends Component {
    public Order $order;

    public function mount(Order $order): void
    {
        abort_unless($order->outlet_id === app(CurrentOutlet::class)->getOrFail()->getKey(), 403);

        $this->order = $order->load(['items.modifiers', 'diningTable']);
    }

    /**
     * Lines grouped by station: one docket per station, on one strip of paper,
     * so the bar can tear off its half.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Domain\Sales\Models\OrderItem>>
     */
    public function stations(): \Illuminate\Support\Collection
    {
        return $this->order->items
            ->whereNull('voided_at')
            ->groupBy(fn ($item): string => $item->station ?? Station::Kitchen->value);
    }
}; ?>

<div class="receipt-shell">
    @foreach ($this->stations() as $station => $items)
        <article class="receipt docket" wire:key="d-{{ $station }}" data-test="docket-{{ $station }}">
            <header class="receipt-head">
                <h1 class="receipt-title">{{ (\App\Domain\Kitchen\Enums\Station::tryFrom((string) $station) ?? \App\Domain\Kitchen\Enums\Station::Kitchen)->label() }}</h1>
                <p class="docket-number">{{ $order->number }}</p>
                <p class="docket-where">{{ $order->diningTable?->name ?? $order->type->label() }}</p>
            </header>

            <dl class="receipt-meta">
                <div>
                    <dt>{{ __('kitchen.docket.sent_at') }}</dt>
                    <dd>{{ ($items->min('sent_at') ?? now())->translatedFormat('H:i') }}</dd>
                </div>
                <div>
                    <dt>{{ __('kitchen.docket.target') }}</dt>
                    <dd>{{ __('kitchen.docket.minutes', ['count' => (int) $items->max('prep_minutes')]) }}</dd>
                </div>
            </dl>

            <ul class="receipt-lines docket-lines">
                @foreach ($items as $item)
                    <li wire:key="dl-{{ $item->id }}">
                        <p class="docket-line">
                            <span class="docket-qty">{{ (int) $item->quantity }}&times;</span>
                            {{ $item->product_name }} {{ $item->variant_name }}
                        </p>

                        @foreach ($item->modifiers as $modifier)
                            <p class="receipt-line-sub">+ {{ $modifier->modifier_name }}</p>
                        @endforeach

                        @if ($item->note)
                            <p class="docket-note">{{ $item->note }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </article>
    @endforeach

    {{-- Not part of the paper: hidden when the page prints. --}}
    <div class="receipt-actions">
        <flux:button variant="primary" class="pos-action" onclick="window.print()" data-test="print-docket">
            {{ __('kitchen.docket.print') }}
        </flux:button>

        <flux:button variant="subtle" class="pos-action" :href="route('pos.index')" wire:navigate data-test="back-to-pos">
            {{ __('kitchen.docket.back') }}
        </flux:button>
    </div>
</div>
