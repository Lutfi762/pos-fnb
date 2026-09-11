@foreach ($this->items as $item)
    @php($level = $this->levelOf($item))

    <tr wire:key="stock-{{ $item->id }}" class="border-b border-line align-top last:border-0">
        <td class="px-4 py-3">
            <span class="block font-medium text-content">{{ $item->name }}</span>

            <span class="block text-xs text-content-muted">
                {{ $item->unit?->name }}

                @if ($level->counted_at)
                    &middot; {{ __('inventory.counted_at') }} {{ $level->counted_at->translatedFormat('d M Y') }}
                @endif
            </span>

            @if ($level->needsRestock())
                <flux:badge size="sm" color="amber" class="mt-1" data-test="low-{{ $item->id }}">
                    {{ __('inventory.low') }}
                </flux:badge>
            @endif
        </td>

        <td class="px-4 py-3 text-end">
            <span class="numeric tabular-nums" data-test="on-hand-{{ $item->id }}">
                {{ $this->amount((float) $level->quantity) }}
            </span>

            <span class="ms-1 text-xs text-content-muted">{{ $item->unit?->code }}</span>
        </td>

        <td class="px-4 py-3 text-end numeric tabular-nums text-content-muted">
            {{ $this->amount((float) $level->reorder_point) }}
        </td>

        <td class="px-4 py-3 text-end">
            <x-money :amount="$item->average_cost" />
        </td>

        <td class="px-4 py-3">
            <div class="flex items-center justify-end gap-1">
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="adjustments-horizontal"
                    wire:click="edit('{{ $item->id }}', 'adjust')"
                    data-test="adjust-{{ $item->id }}"
                >
                    {{ __('inventory.adjust') }}
                </flux:button>

                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="clipboard-document-check"
                    wire:click="edit('{{ $item->id }}', 'count')"
                    data-test="count-{{ $item->id }}"
                >
                    {{ __('inventory.count') }}
                </flux:button>
            </div>
        </td>
    </tr>
@endforeach
