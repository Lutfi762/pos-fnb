@foreach ($this->products as $product)
    <tr wire:key="product-{{ $product->id }}" class="border-b border-line align-top last:border-0">
        <td class="px-4 py-3">
            <span class="block font-medium text-content">{{ $product->name }}</span>
            <span class="block text-xs text-content-muted">{{ $product->category->name }}</span>

            @if ($product->trashed())
                <flux:badge size="sm" color="zinc" class="mt-1">{{ __('catalog.archived') }}</flux:badge>
            @elseif (! $product->is_active)
                <flux:badge size="sm" color="amber" class="mt-1">{{ __('catalog.inactive') }}</flux:badge>
            @endif
        </td>

        <td class="px-4 py-3 text-sm text-content-muted">
            @foreach ($product->variants as $variant)
                <span class="block">{{ $variant->name }}</span>
            @endforeach
        </td>

        <td class="px-4 py-3 text-end">
            @foreach ($product->variants as $variant)
                <x-money :amount="$variant->priceFor($this->outlet)" class="block" />
            @endforeach
        </td>

        <td class="px-4 py-3">
            <div class="flex items-center justify-end gap-1">
                @if ($product->trashed())
                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="arrow-uturn-left"
                        wire:click="restore('{{ $product->id }}')"
                        data-test="restore-{{ $product->id }}"
                    >
                        {{ __('catalog.restore') }}
                    </flux:button>
                @else
                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="pencil-square"
                        :href="route('catalog.edit', $product)"
                        wire:navigate
                        data-test="edit-{{ $product->id }}"
                    >
                        {{ __('catalog.edit_product') }}
                    </flux:button>

                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="archive-box"
                        wire:click="archive('{{ $product->id }}')"
                        wire:confirm="{{ __('catalog.archive_confirm', ['name' => $product->name]) }}"
                        :tooltip="__('catalog.archive')"
                        data-test="archive-{{ $product->id }}"
                    />
                @endif
            </div>
        </td>
    </tr>
@endforeach
