{{--
    The cart. The quantity buttons move the number on screen first and tell
    the server afterwards.
--}}
<section
    class="pos-cart"
    aria-label="{{ __('pos.cart') }}"
    x-data="{ open: false }"
    :class="open && 'pos-cart-open'"
>
    {{-- The always-visible bar. On a phone this is the handle for the sheet. --}}
    <button
        type="button"
        class="pos-cart-bar"
        @click="open = ! open"
        data-test="cart-bar"
        :aria-expanded="open"
    >
        <span class="pos-cart-handle" aria-hidden="true"></span>

        <span class="pos-cart-bar-count" data-test="cart-count">
            {{ trans_choice('pos.items_count', $this->itemCount, ['count' => $this->itemCount]) }}
        </span>

        <x-money :amount="$this->totals->grandTotal" class="pos-cart-bar-total" data-test="cart-total" />
    </button>

    <div class="pos-cart-body">
        {{-- Order type. The choice is remembered for the next bill. --}}
        <flux:radio.group wire:model.live="orderType" variant="segmented" size="sm" class="pos-types">
            @foreach (\App\Domain\Sales\Enums\OrderType::cases() as $type)
                <flux:radio :value="$type->value" :label="__('pos.type_'.$type->value)" />
            @endforeach
        </flux:radio.group>

        @if (\App\Domain\Sales\Enums\OrderType::tryFrom($orderType)?->needsTable())
            <flux:modal.trigger name="tables">
                <flux:button size="sm" variant="subtle" icon="squares-2x2" class="w-full" data-test="choose-table">
                    {{ $tableId === ''
                        ? __('pos.no_table')
                        : __('pos.table').' '.$this->tables->firstWhere('id', $tableId)?->name }}
                </flux:button>
            </flux:modal.trigger>

            <flux:error name="tableId" />
        @endif

        @include('pages.pos.lines')

        @include('pages.pos.summary')
    </div>

    @include('pages.pos.tables')
</section>
