<?php

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\LogWaste;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Waste')] class extends Component {
    #[Url(as: 'tanggal', except: '')]
    public string $date = '';

    #[Url(as: 'alasan', except: '')]
    public string $reasonFilter = '';

    #[Url(as: 'bahan', except: '')]
    public string $itemFilter = '';

    public string $itemId = '';

    public string $quantity = '';

    public string $reason = 'spilled';

    public string $note = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return InventoryItem::query()->with('unit')->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function day(): CarbonImmutable
    {
        // The outlet's trading day: waste logged at 01:00 in a late bar
        // belongs to the night before.
        $today = app(BusinessCalendar::class)->today($this->outlet);

        try {
            return $this->date === '' ? $today : CarbonImmutable::parse($this->date)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }
    }

    /**
     * @return Collection<int, StockMovement>
     */
    #[Computed]
    public function entries(): Collection
    {
        return app(LogWaste::class)->entries($this->outlet, $this->day, $this->day->endOfDay(), $this->reasonFilter, $this->itemFilter);
    }

    #[Computed]
    public function total(): int
    {
        $waste = app(LogWaste::class);

        return $this->entries->sum(fn (StockMovement $movement): int => $waste->value($movement));
    }

    public function save(): void
    {
        // Indonesian keyboards type a decimal comma.
        $this->quantity = str_replace(',', '.', trim($this->quantity));

        $this->validate([
            'itemId' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'reason' => ['required', 'in:'.implode(',', LogWaste::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'itemId' => __('waste.item'),
            'quantity' => __('waste.quantity'),
            'reason' => __('waste.reason'),
        ]);

        $item = $this->items->firstWhere('id', $this->itemId);
        abort_unless($item instanceof InventoryItem, 422);

        $movement = app(LogWaste::class)->handle($this->outlet, $item, (float) $this->quantity, $this->reason, $this->note, auth()->user());

        $this->reset('quantity', 'note');
        unset($this->entries, $this->total);

        Flux::toast(variant: 'success', text: __('waste.saved', [
            'item' => $item->name,
            'quantity' => Quantity::format(abs((float) $movement->quantity)).' '.$item->unit?->code,
        ]));
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('waste.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">{{ __('waste.subtitle', ['outlet' => $this->outlet->name]) }}</flux:text>
        </div>

        @if (app(\App\Domain\Identity\CurrentOutlet::class)->roleFor(auth()->user())?->managesStock())
            <flux:button icon="cube" :href="route('stock.index')" wire:navigate>{{ __('inventory.title') }}</flux:button>
        @endif
    </div>

    <form wire:submit="save" class="grid gap-3 rounded-xl border border-line bg-surface p-4 md:grid-cols-[2fr_1fr_1.2fr] md:items-end">
        <flux:select wire:model="itemId" :label="__('waste.item')" data-test="waste-item">
            <flux:select.option value="">{{ __('waste.choose_item') }}</flux:select.option>
            @foreach ($this->items as $item)
                <flux:select.option :value="$item->id">{{ $item->name }} ({{ $item->unit?->code }})</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="quantity" inputmode="decimal" :label="__('waste.quantity')" data-test="waste-quantity" />

        <flux:select wire:model="reason" :label="__('waste.reason')" data-test="waste-reason">
            @foreach (\App\Domain\Inventory\LogWaste::REASONS as $option)
                <flux:select.option :value="$option">{{ __('waste.reasons.'.$option) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="note" :label="__('waste.note')" :placeholder="__('waste.note_placeholder')" class="md:col-span-2" />

        <flux:button type="submit" variant="primary" icon="trash" class="min-h-11" data-test="waste-save">{{ __('waste.save') }}</flux:button>
    </form>

    <div class="flex flex-wrap items-end gap-3">
        <flux:input type="date" wire:model.live="date" :label="__('waste.date')" class="max-w-44" />

        <flux:select wire:model.live="reasonFilter" :label="__('waste.reason')" class="max-w-52">
            <flux:select.option value="">{{ __('waste.all_reasons') }}</flux:select.option>
            @foreach (\App\Domain\Inventory\LogWaste::REASONS as $option)
                <flux:select.option :value="$option">{{ __('waste.reasons.'.$option) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="itemFilter" :label="__('waste.item')" class="max-w-64">
            <flux:select.option value="">{{ __('waste.all_items') }}</flux:select.option>
            @foreach ($this->items as $item)
                <flux:select.option :value="$item->id">{{ $item->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->entries->isEmpty())
        <x-empty-state icon="trash" :heading="__('waste.empty_heading')" :description="__('waste.empty_description')" />
    @else
        <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="report-table" data-test="waste-table">
                <thead>
                    <tr>
                        <th scope="col" class="text-start">{{ __('waste.item') }}</th>
                        <th scope="col">{{ __('waste.quantity') }}</th>
                        <th scope="col" class="text-start">{{ __('waste.reason') }}</th>
                        <th scope="col" class="text-start">{{ __('waste.by') }}</th>
                        <th scope="col">{{ __('waste.time') }}</th>
                        <th scope="col">{{ __('waste.value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->entries as $movement)
                        <tr wire:key="w-{{ $movement->id }}">
                            <th scope="row" class="text-start font-normal">
                                {{ $movement->item?->name }}
                                @if ($movement->note)
                                    <span class="block text-sm text-content-muted">{{ $movement->note }}</span>
                                @endif
                            </th>
                            <td class="numeric">{{ \App\Support\Quantity::format(abs((float) $movement->quantity)) }} {{ $movement->item?->unit?->code }}</td>
                            <td class="text-start">{{ $movement->reason ? __('waste.reasons.'.$movement->reason) : '-' }}</td>
                            <td class="text-start">{{ $movement->user?->name ?? '-' }}</td>
                            <td>{{ $movement->moved_at->format('H:i') }}</td>
                            <td><x-money :amount="app(\App\Domain\Inventory\LogWaste::class)->value($movement)" /></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-line-strong font-semibold">
                        <th scope="row" colspan="5" class="text-start">{{ __('waste.total') }}</th>
                        <td><x-money :amount="$this->total" data-test="waste-total" /></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
