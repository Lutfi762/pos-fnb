<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\SavePurchaseOrder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Purchase order')] class extends Component {
    public ?PurchaseOrder $purchaseOrder = null;

    public string $supplierId = '';

    public string $expectedAt = '';

    public string $note = '';

    /**
     * @var list<array{inventory_item_id: string, quantity: string, unit_cost: string}>
     */
    public array $lines = [];

    public function mount(?PurchaseOrder $purchaseOrder = null): void
    {
        if (! $purchaseOrder?->exists) {
            $this->addLine();

            return;
        }

        abort_unless($purchaseOrder->outlet_id === $this->outlet->getKey(), 404);
        abort_unless($purchaseOrder->status->isEditable(), 409, __('purchasing.locked'));

        $this->purchaseOrder = $purchaseOrder->load('items');
        $this->supplierId = (string) $purchaseOrder->supplier_id;
        $this->expectedAt = $purchaseOrder->expected_at?->format('Y-m-d') ?? '';
        $this->note = (string) $purchaseOrder->note;

        $this->lines = $purchaseOrder->items
            ->map(fn ($line): array => [
                'inventory_item_id' => (string) $line->inventory_item_id,
                'quantity' => (string) (float) $line->quantity_ordered,
                'unit_cost' => (string) $line->unit_cost,
            ])
            ->all();

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /** @return Collection<int, Supplier> */
    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** @return Collection<int, InventoryItem> */
    #[Computed]
    public function items(): Collection
    {
        return InventoryItem::query()->with('unit')->where('is_active', true)->orderBy('name')->get();
    }

    public function addLine(): void
    {
        $this->lines[] = ['inventory_item_id' => '', 'quantity' => '', 'unit_cost' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);

        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    public function save(SavePurchaseOrder $orders): void
    {
        $this->validate([
            'supplierId' => ['required', 'string', 'exists:suppliers,id'],
            'expectedAt' => ['nullable', 'date'],
            'lines' => ['array'],
        ], attributes: ['supplierId' => __('purchasing.supplier')]);

        $lines = [];

        foreach ($this->lines as $line) {
            if ($line['inventory_item_id'] === '') {
                continue;
            }

            $lines[] = [
                'inventory_item_id' => $line['inventory_item_id'],
                'quantity' => (float) str_replace(',', '.', $line['quantity']),
                'unit_cost' => (int) preg_replace('/\D/', '', $line['unit_cost']),
            ];
        }

        abort_if($lines === [], 422, __('purchasing.no_items'));

        $order = $orders->handle($this->purchaseOrder, $this->outlet, auth()->user(), $lines, [
            'supplier_id' => $this->supplierId,
            'expected_at' => $this->expectedAt === '' ? null : $this->expectedAt,
            'note' => $this->note === '' ? null : $this->note,
        ]);

        Flux::toast(variant: 'success', text: __('purchasing.saved', ['number' => $order->number]));

        $this->redirectRoute('purchases.show', $order, navigate: true);
    }
}; ?>

<div class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:button size="sm" variant="subtle" icon="arrow-left" :href="route('purchases.index')" wire:navigate>
            {{ __('purchasing.back') }}
        </flux:button>
    </div>

    <flux:heading size="xl" class="font-display">
        {{ $purchaseOrder?->exists ? $purchaseOrder->number : __('purchasing.create') }}
    </flux:heading>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select wire:model="supplierId" :label="__('purchasing.supplier')" data-test="supplier">
            <flux:select.option value="">{{ __('purchasing.select_supplier') }}</flux:select.option>

            @foreach ($this->suppliers as $supplier)
                <flux:select.option value="{{ $supplier->id }}">{{ $supplier->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input type="date" wire:model="expectedAt" :label="__('purchasing.expected_at')" data-test="expected-at" />
    </div>

    <div class="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
        <flux:heading size="lg">{{ __('purchasing.items') }}</flux:heading>

        @foreach ($lines as $index => $line)
            <div wire:key="line-{{ $index }}" class="flex flex-wrap items-end gap-2">
                <flux:select
                    wire:model="lines.{{ $index }}.inventory_item_id"
                    class="min-w-48 flex-1"
                    :label="$loop->first ? __('purchasing.item') : ''"
                    data-test="line-item-{{ $index }}"
                >
                    <flux:select.option value="">{{ __('purchasing.select_item') }}</flux:select.option>

                    @foreach ($this->items as $item)
                        <flux:select.option value="{{ $item->id }}">
                            {{ $item->name }} ({{ $item->unit?->code }})
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="lines.{{ $index }}.quantity"
                    inputmode="decimal"
                    class="max-w-28"
                    :label="$loop->first ? __('purchasing.quantity') : ''"
                    data-test="line-quantity-{{ $index }}"
                />

                <flux:input
                    wire:model="lines.{{ $index }}.unit_cost"
                    inputmode="numeric"
                    class="max-w-36"
                    :label="$loop->first ? __('purchasing.unit_cost') : ''"
                    data-test="line-cost-{{ $index }}"
                />

                <flux:button
                    variant="subtle"
                    icon="trash"
                    wire:click="removeLine({{ $index }})"
                    :tooltip="__('purchasing.remove_item')"
                    data-test="remove-line-{{ $index }}"
                />
            </div>
        @endforeach

        <div>
            <flux:button size="sm" variant="subtle" icon="plus" wire:click="addLine" data-test="add-line">
                {{ __('purchasing.add_item') }}
            </flux:button>
        </div>
    </div>

    <flux:input
        wire:model="note"
        :label="__('purchasing.note')"
        :placeholder="__('purchasing.note_placeholder')"
        data-test="note"
    />

    <div class="flex justify-end">
        <flux:button variant="primary" icon="check" wire:click="save" data-test="save">
            {{ __('purchasing.save') }}
        </flux:button>
    </div>
</div>
