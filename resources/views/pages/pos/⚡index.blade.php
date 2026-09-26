<?php

use App\Domain\Catalog\MenuCatalog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\AdvanceTicket;
use App\Domain\Kitchen\StationRouter;
use App\Domain\Sales\CalculateTotals;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Enums\OrderType;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\OrderTotals;
use App\Domain\Sales\SaveOrder;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\Supervisors;
use App\Domain\Sales\TableMap;
use App\Domain\Sales\TaxProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Kasir')] class extends Component {
    /**
     * The cart, kept as plain arrays rather than models.
     *
     * The screen has to redraw on every tap, and hydrating models for that
     * would put a database round trip between the finger and the number.
     *
     * @var list<array<string, mixed>>
     */
    public array $cart = [];

    public string $search = '';

    public string $categoryId = '';

    /** The order type is remembered between bills: a kiosk is take away all day. */
    #[Session(key: 'pos.order_type')]
    public string $orderType = 'dine_in';

    public string $tableId = '';

    public int $guestCount = 1;

    public string $customerName = '';

    /** Set while adding to an existing bill. */
    public ?string $orderId = null;

    /** Line index the modifier sheet is working on, or null when closed. */
    public ?int $editingLine = null;

    public string $modifierProductId = '';

    public string $modifierVariantId = '';

    /** @var array<string, list<string>> */
    public array $chosenModifiers = [];

    public string $lineNote = '';

    public string $billDiscountType = '';

    public int $billDiscountValue = 0;

    public string $supervisorPin = '';

    /** Discounts above this need a supervisor PIN. */
    public const APPROVAL_LIMIT = 20000;

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function menu(): Collection
    {
        return app(MenuCatalog::class)->products($this->outlet);
    }

    /**
     * @return Collection<int, \App\Domain\Catalog\Models\Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return app(MenuCatalog::class)->categories($this->outlet);
    }

    /**
     * The menu after the search box and category filter, drawn as cards.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function visibleMenu(): Collection
    {
        $term = trim(mb_strtolower($this->search));

        return $this->menu
            ->when($this->categoryId !== '', fn ($items) => $items->where('category_id', $this->categoryId))
            ->when($term !== '', fn ($items) => $items->filter(
                fn (Product $product): bool => str_contains(mb_strtolower($product->name), $term)
                    || str_contains(mb_strtolower((string) $product->sku), $term)
                    || $product->variants->contains(
                        fn ($variant): bool => str_contains(mb_strtolower((string) $variant->sku), $term),
                    ),
            ))
            ->values();
    }

    /**
     * @return Collection<int, \App\Domain\Sales\Models\DiningTable>
     */
    #[Computed]
    public function tables(): Collection
    {
        return app(TableMap::class)->forOutlet($this->outlet);
    }

    #[Computed]
    public function totals(): OrderTotals
    {
        return app(CalculateTotals::class)->handle(
            array_map(SaveOrder::cartLine(...), $this->cart),
            TaxProfile::fromOutlet($this->outlet),
            DiscountType::tryFrom($this->billDiscountType),
            $this->billDiscountValue,
        );
    }

    #[Computed]
    public function itemCount(): int
    {
        return array_sum(array_column($this->cart, 'quantity'));
    }

    /**
     * Tapping a menu card. Straight into the cart unless the product asks a
     * question first, in which case the modifier sheet opens.
     */
    public function tapProduct(string $productId): void
    {
        $product = $this->menu->firstWhere('id', $productId);

        if (! $product instanceof Product) {
            return;
        }

        $needsChoice = $product->variants->count() > 1
            || $product->modifierGroups->contains(fn ($group): bool => $group->isRequired())
            || $product->modifierGroups->isNotEmpty();

        if ($needsChoice) {
            $this->openModifiers($productId);

            return;
        }

        $this->pushLine($product, $product->variants->first(), []);
    }

    public function openModifiers(string $productId, ?int $line = null): void
    {
        $product = $this->menu->firstWhere('id', $productId);

        if (! $product instanceof Product) {
            return;
        }

        $this->editingLine = $line;
        $this->modifierProductId = $productId;
        $this->chosenModifiers = [];
        $this->lineNote = '';

        $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
        $this->modifierVariantId = (string) $variant?->getKey();

        if ($line !== null && isset($this->cart[$line])) {
            $row = $this->cart[$line];
            $this->modifierVariantId = (string) $row['product_variant_id'];
            $this->lineNote = (string) ($row['note'] ?? '');
            $this->chosenModifiers = $row['chosen'] ?? [];
        }
    }

    public function closeModifiers(): void
    {
        $this->reset('editingLine', 'modifierProductId', 'modifierVariantId', 'chosenModifiers', 'lineNote');
    }

    /**
     * Toggle one modifier, respecting the group's min and max.
     */
    public function toggleModifier(string $groupId, string $modifierId): void
    {
        $group = $this->modifierProduct?->modifierGroups->firstWhere('id', $groupId);

        if ($group === null) {
            return;
        }

        $chosen = $this->chosenModifiers[$groupId] ?? [];

        if (in_array($modifierId, $chosen, true)) {
            // Never drop below the minimum the group insists on.
            if (count($chosen) <= $group->min_select) {
                return;
            }

            $chosen = array_values(array_diff($chosen, [$modifierId]));
        } else {
            // A single-choice group swaps rather than stacks.
            $chosen = $group->max_select <= 1
                ? [$modifierId]
                : array_slice([...$chosen, $modifierId], -$group->max_select);
        }

        $this->chosenModifiers[$groupId] = $chosen;
    }

    #[Computed]
    public function modifierProduct(): ?Product
    {
        return $this->modifierProductId === ''
            ? null
            : $this->menu->firstWhere('id', $this->modifierProductId);
    }

    /**
     * Price of one unit as currently configured in the sheet, so the number
     * on the confirm button moves as the guest chooses.
     */
    #[Computed]
    public function modifierUnitPrice(): int
    {
        $product = $this->modifierProduct;

        if (! $product instanceof Product) {
            return 0;
        }

        $variant = $product->variants->firstWhere('id', $this->modifierVariantId);
        $price = $variant?->priceFor($this->outlet) ?? 0;

        foreach ($this->selectedModifiers($product) as $modifier) {
            $price += $modifier['price'];
        }

        return $price;
    }

    /**
     * Flatten the chosen modifiers into snapshot rows.
     *
     * @return list<array<string, mixed>>
     */
    private function selectedModifiers(Product $product): array
    {
        $rows = [];

        foreach ($product->modifierGroups as $group) {
            foreach ($this->chosenModifiers[$group->getKey()] ?? [] as $modifierId) {
                $modifier = $group->modifiers->firstWhere('id', $modifierId);

                if ($modifier !== null) {
                    $rows[] = [
                        'modifier_id' => $modifier->getKey(),
                        'modifier_name' => $modifier->name,
                        'group_name' => $group->name,
                        'price' => (int) $modifier->price,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Confirm the modifier sheet: add a new line, or rewrite the one edited.
     */
    public function confirmModifiers(): void
    {
        $product = $this->modifierProduct;

        if (! $product instanceof Product) {
            return;
        }

        // A group that demands a choice does not let the sheet close without one.
        foreach ($product->modifierGroups as $group) {
            if (count($this->chosenModifiers[$group->getKey()] ?? []) < $group->min_select) {
                $this->addError('modifiers', __('pos.choose_min', ['count' => $group->min_select]));

                return;
            }
        }

        $variant = $product->variants->firstWhere('id', $this->modifierVariantId);

        $this->pushLine($product, $variant, $this->selectedModifiers($product), $this->editingLine);
        $this->closeModifiers();
    }

    /**
     * Add one unit to the cart, or merge into the identical line above it.
     *
     * @param  list<array<string, mixed>>  $modifiers
     */
    private function pushLine(Product $product, mixed $variant, array $modifiers, ?int $replacing = null): void
    {
        if ($variant === null) {
            return;
        }

        $row = [
            'key' => (string) Str::ulid(),
            'id' => $replacing !== null ? ($this->cart[$replacing]['id'] ?? null) : null,
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'station' => app(StationRouter::class)->stationFor($product)->value,
            'prep_minutes' => app(StationRouter::class)->prepMinutesFor($product),
            'quantity' => $replacing !== null ? $this->cart[$replacing]['quantity'] : 1,
            'unit_price' => $variant->priceFor($this->outlet),
            'modifier_total' => array_sum(array_column($modifiers, 'price')),
            'discount_type' => $replacing !== null ? $this->cart[$replacing]['discount_type'] : null,
            'discount_value' => $replacing !== null ? $this->cart[$replacing]['discount_value'] : 0,
            'note' => $this->lineNote === '' ? null : $this->lineNote,
            'modifiers' => $modifiers,
            'chosen' => $this->chosenModifiers,
        ];

        if ($replacing !== null) {
            $this->cart[$replacing] = $row;

            return;
        }

        // Two taps on the same plain item are one line of two, not two lines.
        foreach ($this->cart as $index => $line) {
            $sameItem = $line['product_variant_id'] === $row['product_variant_id']
                && $line['modifiers'] == $row['modifiers']
                && $line['note'] === $row['note']
                && $line['id'] === null;

            if ($sameItem) {
                $this->cart[$index]['quantity']++;

                return;
            }
        }

        $this->cart[] = $row;
    }

    public function increase(int $line): void
    {
        if (isset($this->cart[$line])) {
            $this->cart[$line]['quantity']++;
        }
    }

    /**
     * One less, and the line disappears when it reaches zero.
     */
    public function decrease(int $line): void
    {
        if (! isset($this->cart[$line])) {
            return;
        }

        if ($this->cart[$line]['quantity'] <= 1) {
            $this->removeLine($line);

            return;
        }

        $this->cart[$line]['quantity']--;
    }

    public function removeLine(int $line): void
    {
        unset($this->cart[$line]);

        $this->cart = array_values($this->cart);
    }

    public function setLineNote(int $line, string $note): void
    {
        if (isset($this->cart[$line])) {
            $this->cart[$line]['note'] = trim($note) === '' ? null : trim($note);
        }
    }

    /**
     * Discount one line. Anything generous needs a supervisor first.
     */
    public function discountLine(int $line, string $type, int $value): void
    {
        if (! isset($this->cart[$line])) {
            return;
        }

        $discountType = DiscountType::tryFrom($type);
        $row = $this->cart[$line];

        $amount = $discountType?->deductionFor(
            ($row['unit_price'] + $row['modifier_total']) * $row['quantity'],
            $value,
        ) ?? 0;

        if (! $this->approved($amount)) {
            return;
        }

        $this->cart[$line]['discount_type'] = $discountType?->value;
        $this->cart[$line]['discount_value'] = $value;
    }

    public function applyBillDiscount(): void
    {
        $this->validate([
            'billDiscountType' => ['required', 'in:percent,amount'],
            'billDiscountValue' => ['required', 'integer', 'min:1'],
        ]);

        $amount = DiscountType::from($this->billDiscountType)
            ->deductionFor($this->rawSubtotal(), $this->billDiscountValue);

        if (! $this->approved($amount)) {
            // Roll it back on screen: an unapproved discount never sticks.
            $this->reset('billDiscountType', 'billDiscountValue');
        }
    }

    public function clearBillDiscount(): void
    {
        $this->reset('billDiscountType', 'billDiscountValue');
    }

    /**
     * A discount over the limit only passes with a supervisor PIN.
     * The PIN is checked against managers and owners of this outlet.
     */
    private function approved(int $amount): bool
    {
        if ($amount <= self::APPROVAL_LIMIT) {
            return true;
        }

        if ($this->supervisorPin === '') {
            $this->dispatch('needs-approval');

            return false;
        }

        $supervisor = app(Supervisors::class)->approve($this->outlet, $this->supervisorPin);

        $this->reset('supervisorPin');

        if ($supervisor === null) {
            $this->addError('supervisorPin', __('pos.pin_invalid'));

            return false;
        }

        return true;
    }

    /**
     * The cart before any bill discount, used to size an approval request.
     */
    private function rawSubtotal(): int
    {
        return array_sum(array_map(
            fn (array $line): int => SaveOrder::cartLine($line)->net(),
            $this->cart,
        ));
    }

    /**
     * Take away and delivery have no table, so any held one is let go.
     */
    public function updatedOrderType(string $value): void
    {
        if (OrderType::tryFrom($value)?->needsTable() === false) {
            $this->tableId = '';
        }
    }

    public function chooseTable(string $tableId): void
    {
        $this->tableId = $this->tableId === $tableId ? '' : $tableId;
    }

    /**
     * Open a running bill so the cashier can add a round to it.
     */
    public function resumeOrder(string $orderId): void
    {
        $order = Order::query()
            ->where('outlet_id', $this->outlet->getKey())
            ->with('items.modifiers')
            ->findOrFail($orderId);

        abort_unless($order->status->isEditable(), 409, __('pos.order_closed'));

        $this->orderId = $order->getKey();
        $this->orderType = $order->type->value;
        $this->tableId = (string) $order->dining_table_id;
        $this->guestCount = $order->guest_count;
        $this->customerName = (string) $order->customer_name;

        $this->cart = $order->items->map(fn ($item): array => [
            'key' => $item->getKey(),
            'id' => $item->getKey(),
            'product_id' => null,
            'product_variant_id' => $item->product_variant_id,
            'product_name' => $item->product_name,
            'variant_name' => $item->variant_name,
            'station' => $item->station,
            'prep_minutes' => $item->prep_minutes,
            'quantity' => (int) $item->quantity,
            'unit_price' => $item->unit_price,
            'modifier_total' => (int) $item->quantity === 0
                ? 0
                : intdiv($item->modifier_total, (int) $item->quantity),
            'discount_type' => $item->discount_total > 0 ? DiscountType::Amount->value : null,
            'discount_value' => $item->discount_total,
            'note' => $item->note,
            'modifiers' => $item->modifiers->map(fn ($modifier): array => [
                'modifier_id' => $modifier->modifier_id,
                'modifier_name' => $modifier->modifier_name,
                'group_name' => $modifier->group_name,
                'price' => $modifier->price,
            ])->all(),
            'chosen' => [],
        ])->values()->all();
    }

    /**
     * Send the bill to the kitchen and keep it open for payment later.
     */
    public function saveBill(bool $thenPay = false): void
    {
        if ($this->cart === []) {
            return;
        }

        $type = OrderType::from($this->orderType);

        $this->validate([
            'tableId' => [$type->needsTable() ? 'required' : 'nullable', 'string'],
            'guestCount' => ['integer', 'min:1', 'max:99'],
        ], attributes: ['tableId' => __('pos.table')]);

        $order = app(SaveOrder::class)->handle(
            $this->orderId === null ? null : Order::findOrFail($this->orderId),
            $this->outlet,
            auth()->user(),
            $this->cart,
            [
                'type' => $type,
                'dining_table_id' => $this->tableId === '' ? null : $this->tableId,
                'guest_count' => $this->guestCount,
                'customer_name' => $this->customerName === '' ? null : $this->customerName,
                'bill_discount_type' => DiscountType::tryFrom($this->billDiscountType),
                'bill_discount_value' => $this->billDiscountValue,

                // Ties the bill to the drawer it was rung up on, so the count
                // at the end of the shift has something to be counted against.
                'cashier_shift_id' => $this->shift?->getKey(),
            ],
        );

        // Fire the new lines at their stations. Lines already on the board
        // keep the clock they were sent with.
        $sent = app(AdvanceTicket::class)->send($order);

        $this->newOrder();

        if ($thenPay) {
            $this->redirectRoute('pos.payment', ['order' => $order], navigate: true);

            return;
        }

        // No screen in the kitchen: the docket is the ticket, so it prints.
        if ($sent > 0 && ! $this->outlet->has_kitchen_display) {
            $this->redirectRoute('kitchen.docket', ['order' => $order], navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: __('pos.saved', ['number' => $order->number]));
    }

    /**
     * The drawer this cashier has open, or null when they never opened one.
     */
    #[Computed]
    public function shift(): ?CashierShift
    {
        return app(ShiftRegister::class)->current($this->outlet, auth()->user());
    }

    public function newOrder(): void
    {
        $this->reset('cart', 'orderId', 'tableId', 'guestCount', 'customerName', 'search', 'billDiscountType', 'billDiscountValue');
        $this->closeModifiers();
    }

    /**
     * @return Collection<int, Order>
     */
    #[Computed]
    public function openBills(): Collection
    {
        return Order::query()
            ->where('outlet_id', $this->outlet->getKey())
            ->where('status', OrderStatus::Open)
            ->whereNull('merged_into_order_id')
            ->with('diningTable')
            ->latest('placed_at')
            ->get();
    }

    /**
     * Walk a running bill over to a different table.
     */
    public function moveOrder(string $orderId, string $tableId): void
    {
        $order = Order::query()->where('outlet_id', $this->outlet->getKey())->findOrFail($orderId);
        $table = $this->tables->firstWhere('id', $tableId);

        if ($table === null) {
            return;
        }

        app(TableMap::class)->move($order, $table);

        unset($this->tables, $this->openBills);

        Flux::toast(variant: 'success', text: __('pos.moved', ['name' => $table->name]));
    }

    /**
     * Fold one table's bill into another when guests join up.
     */
    public function mergeOrders(string $sourceId, string $targetId): void
    {
        $scope = Order::query()->where('outlet_id', $this->outlet->getKey());

        $target = app(TableMap::class)->merge(
            (clone $scope)->findOrFail($sourceId),
            (clone $scope)->findOrFail($targetId),
        );

        unset($this->tables, $this->openBills);

        Flux::toast(variant: 'success', text: __('pos.merged', ['number' => $target->number]));
    }
}; ?>

<div
    class="pos-shell"
    x-data="posShortcuts"
    @keydown.window="handle($event)"
>
    {{-- Categories. A rail on a laptop, a scrolling strip on anything smaller. --}}
    <section class="pos-categories" aria-label="{{ __('pos.categories') }}">
        <div class="pos-category-list">
            <button
                type="button"
                wire:click="$set('categoryId', '')"
                @class(['pos-chip', 'pos-chip-on' => $categoryId === ''])
            >{{ __('pos.all_categories') }}</button>

            @foreach ($this->categories as $index => $category)
                <button
                    type="button"
                    wire:click="$set('categoryId', '{{ $category->id }}')"
                    @class(['pos-chip', 'pos-chip-on' => $categoryId === $category->id])
                    data-category-index="{{ $index + 1 }}"
                >
                    <span>{{ $category->name }}</span>
                    @if ($index < 9)
                        <kbd class="pos-kbd">{{ $index + 1 }}</kbd>
                    @endif
                </button>
            @endforeach
        </div>
    </section>

    {{-- Menu grid, its own island so a cart tap never redraws 40 cards. --}}
    <section class="pos-menu" aria-label="{{ __('pos.menu') }}">
        <flux:input
            wire:model.live.debounce.150ms="search"
            type="search"
            x-ref="search"
            :placeholder="__('pos.search_placeholder')"
            :label="__('pos.search')"
            label:class="sr-only"
            icon="magnifying-glass"
            data-test="pos-search"
        />

        @island(name: 'menu')
            <div class="pos-grid" data-test="menu-grid">
                @forelse ($this->visibleMenu as $product)
                    <button
                        type="button"
                        wire:key="p-{{ $product->id }}"
                        wire:click="tapProduct('{{ $product->id }}')"
                        class="pos-card"
                        data-test="menu-card"
                    >
                        <span class="pos-card-name">{{ $product->name }}</span>
                        <x-money :amount="$product->variants->first()?->priceFor($this->outlet) ?? 0" class="pos-card-price" />
                    </button>
                @empty
                    <x-empty-state
                        class="pos-grid-empty"
                        icon="magnifying-glass"
                        :heading="__('pos.no_results_heading')"
                        :description="__('pos.no_results_description')"
                    />
                @endforelse
            </div>
        @endisland
    </section>

    @include('pages.pos.cart')
    @include('pages.pos.modifier-sheet')
</div>

@script
<script>
    Alpine.data('posShortcuts', () => ({
        // A barcode scanner is a keyboard that types fast and ends with Enter.
        buffer: '',
        lastKeyAt: 0,

        handle(event) {
            const typing = ['INPUT', 'TEXTAREA'].includes(event.target.tagName);

            if (event.key === 'F2') {
                event.preventDefault();
                this.$wire.saveBill(true);
                return;
            }

            if (event.key === 'F4') {
                event.preventDefault();
                this.$refs.search?.focus();
                return;
            }

            if (event.key === 'Escape') {
                this.$wire.closeModifiers();
                return;
            }

            if (! typing && /^[1-9]$/.test(event.key)) {
                event.preventDefault();
                document.querySelector(`[data-category-index="${event.key}"]`)?.click();
                return;
            }

            this.scan(event, typing);
        },

        scan(event, typing) {
            const now = Date.now();

            // Keys arriving under 50ms apart are a scanner, not a person.
            if (now - this.lastKeyAt > 50) {
                this.buffer = '';
            }

            this.lastKeyAt = now;

            if (event.key === 'Enter' && this.buffer.length > 2) {
                this.$wire.set('search', this.buffer);
                this.buffer = '';
                return;
            }

            if (! typing && event.key.length === 1) {
                this.buffer += event.key;
            }
        },
    }));
</script>
@endscript
