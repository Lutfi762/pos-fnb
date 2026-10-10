<?php

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\ReceiveGoods;
use App\Domain\Purchasing\SavePurchaseOrder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Purchase order')] class extends Component {
    public PurchaseOrder $purchaseOrder;

    /**
     * Line id to the amount that arrived now.
     *
     * @var array<string, string>
     */
    public array $arrivals = [];

    public string $note = '';

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        abort_unless(
            $purchaseOrder->outlet_id === app(\App\Domain\Identity\CurrentOutlet::class)->getOrFail()->getKey(),
            404,
        );

        $this->purchaseOrder = $purchaseOrder->load(['supplier', 'items.item.unit']);
    }

    /** Pre-fill every line with what is still owed. */
    public function fillOutstanding(): void
    {
        foreach ($this->purchaseOrder->items as $line) {
            $this->arrivals[$line->getKey()] = (string) $line->outstandingQuantity();
        }
    }

    public function place(SavePurchaseOrder $orders): void
    {
        $this->purchaseOrder = $orders->place($this->purchaseOrder)->load(['supplier', 'items.item.unit']);

        Flux::toast(variant: 'success', text: __('purchasing.placed', ['number' => $this->purchaseOrder->number]));
    }

    public function cancel(SavePurchaseOrder $orders): void
    {
        $this->purchaseOrder = $orders->cancel($this->purchaseOrder)->load(['supplier', 'items.item.unit']);

        Flux::toast(variant: 'success', text: __('purchasing.cancelled', ['number' => $this->purchaseOrder->number]));
    }

    public function receive(ReceiveGoods $goods): void
    {
        $quantities = [];

        foreach ($this->arrivals as $lineId => $quantity) {
            $quantities[$lineId] = (float) str_replace(',', '.', (string) $quantity);
        }

        $this->purchaseOrder = $goods
            ->handle($this->purchaseOrder, auth()->user(), $quantities, $this->note === '' ? null : $this->note)
            ->load(['supplier', 'items.item.unit']);

        $this->reset('arrivals', 'note');

        Flux::toast(variant: 'success', text: __('purchasing.received'));
    }

    /** Quantities read as whole numbers unless the fraction matters. */
    public static function amount(float $quantity): string
    {
        return \App\Support\Quantity::format($quantity);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:button size="sm" variant="subtle" icon="arrow-left" :href="route('purchases.index')" wire:navigate>
            {{ __('purchasing.back') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ $purchaseOrder->number }}</flux:heading>

            <flux:text class="mt-1 text-content-muted">
                {{ $purchaseOrder->supplier->name }}

                @if ($purchaseOrder->expected_at)
                    &middot; {{ __('purchasing.expected_at') }}
                    {{ $purchaseOrder->expected_at->translatedFormat('d M Y') }}
                @endif
            </flux:text>

            <flux:badge size="sm" class="mt-2" :color="$purchaseOrder->status->color()" data-test="status">
                {{ $purchaseOrder->status->label() }}
            </flux:badge>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($purchaseOrder->status->isEditable())
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="pencil-square"
                    :href="route('purchases.edit', $purchaseOrder)"
                    wire:navigate
                    data-test="edit-order"
                >
                    {{ __('purchasing.edit') }}
                </flux:button>
            @endif

            @if ($purchaseOrder->status === \App\Domain\Purchasing\Enums\PurchaseOrderStatus::Draft)
                <flux:button size="sm" variant="primary" icon="paper-airplane" wire:click="place" data-test="place">
                    {{ __('purchasing.place') }}
                </flux:button>
            @endif

            @if ($purchaseOrder->status->isEditable() && ! $purchaseOrder->hasReceipts())
                <flux:button
                    size="sm"
                    variant="danger"
                    icon="x-mark"
                    wire:click="cancel"
                    wire:confirm="{{ __('purchasing.cancel_confirm', ['number' => $purchaseOrder->number]) }}"
                    data-test="cancel"
                >
                    {{ __('purchasing.cancel') }}
                </flux:button>
            @endif
        </div>
    </div>

    @include('pages.purchases.lines')

    @if ($purchaseOrder->status->isReceivable())
        @include('pages.purchases.receive')
    @endif
</div>
