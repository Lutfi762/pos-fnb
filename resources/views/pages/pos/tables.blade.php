{{--
    The floor plan. Squares sit where the outlet says they sit, coloured by
    what is happening at them: free, or carrying an open bill.
--}}
<flux:modal name="tables" class="pos-tables-modal">
    <flux:heading size="lg">{{ __('pos.tables') }}</flux:heading>

    <div class="pos-plan" role="group" aria-label="{{ __('pos.choose_table') }}">
        @foreach ($this->tables as $table)
            @php($openOrder = $table->open_order)

            <button
                type="button"
                wire:key="t-{{ $table->id }}"
                wire:click="chooseTable('{{ $table->id }}')"
                style="grid-column: {{ $table->pos_x + 1 }}; grid-row: {{ $table->pos_y + 1 }};"
                @class([
                    'pos-table',
                    'pos-table-open' => $openOrder !== null,
                    'pos-table-picked' => $tableId === $table->id,
                ])
                data-test="table"
            >
                <span class="pos-table-name">{{ $table->name }}</span>

                @if ($openOrder)
                    <x-money :amount="$openOrder->grand_total" class="pos-table-total" />
                @else
                    <span class="pos-table-seats">{{ $table->seats }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="pos-plan-legend">
        <span><i class="pos-dot"></i>{{ __('pos.table_free') }}</span>
        <span><i class="pos-dot pos-dot-open"></i>{{ __('pos.table_open_bill') }}</span>
    </div>

    {{-- Running bills: resume one, move it, or fold two together. --}}
    @if ($this->openBills->isNotEmpty())
        <flux:separator />

        <flux:heading size="sm">{{ __('pos.open_bills') }}</flux:heading>

        <ul class="pos-bills">
            @foreach ($this->openBills as $bill)
                <li class="pos-bill" wire:key="bill-{{ $bill->id }}">
                    <div>
                        <span class="pos-bill-number">{{ $bill->number }}</span>
                        <span class="pos-bill-table">{{ $bill->diningTable?->name ?? __('pos.no_table') }}</span>
                    </div>

                    <x-money :amount="$bill->grand_total" />

                    <flux:button size="sm" wire:click="resumeOrder('{{ $bill->id }}')" data-test="resume-bill">
                        {{ __('pos.resume') }}
                    </flux:button>

                    <flux:dropdown>
                        <flux:button size="sm" variant="subtle" icon="ellipsis-horizontal" :aria-label="__('pos.move_table')" />

                        <flux:menu>
                            <flux:menu.submenu :heading="__('pos.move_table')">
                                @foreach ($this->tables as $table)
                                    <flux:menu.item wire:click="moveOrder('{{ $bill->id }}', '{{ $table->id }}')">
                                        {{ $table->name }}
                                    </flux:menu.item>
                                @endforeach
                            </flux:menu.submenu>

                            <flux:menu.submenu :heading="__('pos.merge_table')">
                                @foreach ($this->openBills as $other)
                                    @if ($other->id !== $bill->id)
                                        <flux:menu.item wire:click="mergeOrders('{{ $bill->id }}', '{{ $other->id }}')">
                                            {{ $other->number }}
                                        </flux:menu.item>
                                    @endif
                                @endforeach
                            </flux:menu.submenu>
                        </flux:menu>
                    </flux:dropdown>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="flex justify-end gap-2">
        <flux:modal.close>
            <flux:button variant="primary">{{ __('pos.close') }}</flux:button>
        </flux:modal.close>
    </div>
</flux:modal>
