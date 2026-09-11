{{-- One sheet, two questions: correct the books, or count the shelf. --}}
<flux:modal name="stock-sheet" class="w-full max-w-sm">
    @if ($this->editing)
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">
                {{ $mode === 'count'
                    ? __('inventory.count_title', ['item' => $this->editing->name])
                    : __('inventory.adjust_title', ['item' => $this->editing->name]) }}
            </flux:heading>

            <flux:text class="text-content-muted">
                {{ $mode === 'count' ? __('inventory.count_hint') : __('inventory.quantity_hint') }}
            </flux:text>

            <flux:input
                wire:model="quantity"
                inputmode="decimal"
                :label="$mode === 'count' ? __('inventory.counted_quantity') : __('inventory.quantity')"
                :description="$this->editing->unit?->name"
                data-test="sheet-quantity"
            />

            <flux:input
                wire:model="note"
                :label="__('inventory.note')"
                :placeholder="__('inventory.note_placeholder')"
                data-test="sheet-note"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="subtle">{{ __('common.cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" wire:click="save" data-test="sheet-save">
                    {{ __('inventory.save') }}
                </flux:button>
            </div>
        </div>
    @endif
</flux:modal>
