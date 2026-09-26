<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\MoveStock;
use App\Domain\Inventory\StockReview;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Stok')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'menipis', except: false)]
    public bool $lowOnly = false;

    /** The item the sheet is open for. */
    public string $editingId = '';

    public string $quantity = '';

    public string $note = '';

    /** Adjustment or count: the same sheet, two different questions. */
    public string $mode = 'adjust';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * Every item with its balance at this outlet.
     *
     * ponytail: one query, no paging. A cafe stocks dozens of ingredients.
     * Add pagination when a tenant passes a few hundred.
     *
     * @return Collection<int, InventoryItem>
     */
    #[Computed]
    public function items(): Collection
    {
        $outletId = $this->outlet->getKey();

        return InventoryItem::query()
            ->with(['unit', 'stockLevels' => fn ($query) => $query->where('outlet_id', $outletId)])
            ->where('is_active', true)
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';

                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->orderBy('name')
            ->get()
            ->when($this->lowOnly, fn (Collection $items): Collection => $items->filter(
                fn (InventoryItem $item): bool => $this->levelOf($item)->needsRestock(),
            ))
            ->values();
    }

    /**
     * Ingredients the books say are below zero, waiting to be counted.
     *
     * @return Collection<int, StockLevel>
     */
    #[Computed]
    public function negative(): Collection
    {
        return app(StockReview::class)->negative($this->outlet);
    }

    /**
     * The balance row for an item, unsaved when it has never moved here.
     */
    public function levelOf(InventoryItem $item): StockLevel
    {
        return $item->stockLevels->first() ?? new StockLevel([
            'outlet_id' => $this->outlet->getKey(),
            'inventory_item_id' => $item->getKey(),
            'quantity' => 0,
            'reorder_point' => 0,
        ]);
    }

    #[Computed]
    public function editing(): ?InventoryItem
    {
        return $this->editingId === ''
            ? null
            : InventoryItem::query()->with('unit')->find($this->editingId);
    }

    public function edit(string $itemId, string $mode = 'adjust'): void
    {
        $this->reset('quantity', 'note');

        $this->editingId = $itemId;
        $this->mode = $mode === 'count' ? 'count' : 'adjust';

        unset($this->editing);

        Flux::modal('stock-sheet')->show();
    }

    public function save(MoveStock $stock): void
    {
        $item = $this->editing;

        if (! $item instanceof InventoryItem) {
            return;
        }

        // A comma is what an Indonesian keyboard gives for a decimal.
        $quantity = (float) str_replace(',', '.', $this->quantity);
        $note = $this->note === '' ? null : $this->note;

        $this->mode === 'count'
            ? $this->applyCount($stock, $item, $quantity, $note)
            : $this->applyAdjustment($stock, $item, $quantity, $note);

        unset($this->items);
        $this->reset('editingId', 'quantity', 'note');

        Flux::modal('stock-sheet')->close();
    }

    private function applyCount(MoveStock $stock, InventoryItem $item, float $quantity, ?string $note): void
    {
        $movement = $stock->count($this->outlet, $item, $quantity, auth()->user(), $note);

        Flux::toast(
            variant: $movement === null ? 'warning' : 'success',
            text: $movement === null
                ? __('inventory.count_matched')
                : __('inventory.counted', ['item' => $item->name]),
        );
    }

    private function applyAdjustment(MoveStock $stock, InventoryItem $item, float $quantity, ?string $note): void
    {
        $movement = $stock->record(
            $this->outlet,
            $item,
            StockMovementType::Adjustment,
            $quantity,
            auth()->user(),
            note: $note,
        );

        Flux::toast(variant: 'success', text: __('inventory.adjusted', [
            'item' => $item->name,
            'quantity' => self::amount((float) $movement->balance_after),
        ]));
    }

    /**
     * Quantities are decimal(12,3) but read as whole numbers most of the time,
     * so trailing zeroes are dropped: 4,000 shows as 4 and 4,250 stays 4,25.
     */
    public static function amount(float $quantity): string
    {
        $formatted = number_format($quantity, 3, ',', '.');

        return str_contains($formatted, ',')
            ? rtrim(rtrim($formatted, '0'), ',')
            : $formatted;
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('inventory.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('inventory.subtitle', ['outlet' => $this->outlet->name]) }}
            </flux:text>
        </div>

        <flux:button icon="truck" :href="route('purchases.index')" wire:navigate data-test="to-purchases">
            {{ __('purchasing.title') }}
        </flux:button>
    </div>

    @if ($this->negative->isNotEmpty())
        {{-- Sold below zero, most often while a till was offline. Nothing is
             wrong with the sales; the shelf just needs counting. --}}
        <section class="rounded-xl border border-pending bg-pending-surface p-4 text-pending-text" aria-labelledby="stock-review" data-test="stock-review">
            <flux:heading id="stock-review" size="lg" class="!text-pending-text">{{ __('inventory.review_heading') }}</flux:heading>
            <p class="mt-1 text-sm">{{ __('inventory.review_description') }}</p>

            <ul class="mt-3 flex flex-col gap-2">
                @foreach ($this->negative as $level)
                    <li wire:key="neg-{{ $level->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-surface px-3 py-2 text-content">
                        <span>
                            <span class="font-medium">{{ $level->item?->name }}</span>
                            <span class="block text-xs text-content-muted">
                                @if ($level->last_sold_at)
                                    {{ __('inventory.review_last_sold', ['time' => \Carbon\CarbonImmutable::parse($level->last_sold_at)->translatedFormat('d M H:i')]) }}
                                @endif
                            </span>
                        </span>

                        <span class="flex items-center gap-3">
                            <span class="numeric tabular-nums font-semibold text-danger">{{ $this->amount((float) $level->quantity) }} {{ $level->item?->unit?->code }}</span>
                            <flux:button size="sm" wire:click="edit('{{ $level->inventory_item_id }}', 'count')" data-test="review-count-{{ $level->inventory_item_id }}">
                                {{ __('inventory.count_now') }}
                            </flux:button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('inventory.search')"
            :placeholder="__('inventory.search_placeholder')"
            icon="magnifying-glass"
            class="max-w-xs"
            data-test="search"
        />

        <flux:checkbox wire:model.live="lowOnly" :label="__('inventory.low_only')" class="mb-2" data-test="low-only" />
    </div>

    @if ($this->items->isEmpty())
        <x-empty-state
            icon="cube"
            :heading="$search === '' && ! $lowOnly ? __('inventory.empty_heading') : __('inventory.no_results_heading')"
            :description="$search === '' && ! $lowOnly ? __('inventory.empty_description') : __('inventory.no_results_description')"
        />
    @else
        <div class="overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-line text-sm text-content-muted">
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('inventory.item') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('inventory.on_hand') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('inventory.reorder_point') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('inventory.average_cost') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">
                            <span class="sr-only">{{ __('inventory.adjust') }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @include('pages.stock.rows')
                </tbody>
            </table>
        </div>
    @endif

    @include('pages.stock.sheet')
</div>
