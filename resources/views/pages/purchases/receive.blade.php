{{-- The delivery form. Only lines with something still owed are askable. --}}
<div class="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <flux:heading size="lg">{{ __('purchasing.receive_title') }}</flux:heading>
            <flux:text class="text-content-muted">{{ __('purchasing.receive_hint') }}</flux:text>
        </div>

        <flux:button size="sm" variant="subtle" icon="bolt" wire:click="fillOutstanding" data-test="fill-outstanding">
            {{ __('purchasing.receive_all') }}
        </flux:button>
    </div>

    <div class="flex flex-col gap-3">
        @foreach ($purchaseOrder->items as $line)
            @php($outstanding = $line->outstandingQuantity())

            <div wire:key="arrival-{{ $line->id }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-40 flex-1">
                    <span class="block text-content">{{ $line->item_name }}</span>

                    <span class="block text-xs text-content-muted">
                        {{ __('purchasing.outstanding') }}:
                        {{ $this->amount($outstanding) }} {{ $line->unit_code }}
                    </span>
                </div>

                <flux:input
                    wire:model="arrivals.{{ $line->id }}"
                    inputmode="decimal"
                    class="max-w-32"
                    :disabled="$outstanding <= 0"
                    :placeholder="__('purchasing.quantity')"
                    data-test="arrival-{{ $line->id }}"
                />
            </div>
        @endforeach
    </div>

    <flux:input
        wire:model="note"
        :label="__('purchasing.note')"
        :placeholder="__('purchasing.note_placeholder')"
        data-test="receive-note"
    />

    <div class="flex justify-end">
        <flux:button variant="primary" icon="inbox-arrow-down" wire:click="receive" data-test="receive">
            {{ __('purchasing.receive') }}
        </flux:button>
    </div>
</div>
