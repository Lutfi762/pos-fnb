<ul class="pos-lines" data-test="cart-lines">
    @foreach ($cart as $index => $line)
        @php($cartLine = \App\Domain\Sales\SaveOrder::cartLine($line))

        <li class="pos-line motion-line" wire:key="line-{{ $line['key'] }}">
            <div class="pos-line-head">
                <div class="pos-line-name">
                    <span>{{ $line['product_name'] }}</span>
                    @if ($line['variant_name'])
                        <span class="pos-line-variant">{{ $line['variant_name'] }}</span>
                    @endif
                </div>

                <x-money :amount="$cartLine->net()" class="pos-line-total" />
            </div>

            @foreach ($line['modifiers'] as $modifier)
                <p class="pos-line-modifier">
                    {{ $modifier['modifier_name'] }}
                    @if ($modifier['price'] > 0)
                        <x-money :amount="$modifier['price']" />
                    @endif
                </p>
            @endforeach

            @if ($line['note'])
                <p class="pos-line-note">{{ $line['note'] }}</p>
            @endif

            @if ($cartLine->discount() > 0)
                <p class="pos-line-discount">
                    {{ __('pos.discount') }}
                    <x-money :amount="-$cartLine->discount()" />
                </p>
            @endif

            <div class="pos-line-actions">
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="minus"
                    wire:click="decrease({{ $index }})"
                    :aria-label="__('pos.decrease')"
                    data-test="decrease"
                />

                <span class="pos-line-qty" data-test="line-qty">{{ $line['quantity'] }}</span>

                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="plus"
                    wire:click="increase({{ $index }})"
                    :aria-label="__('pos.increase')"
                    data-test="increase"
                />

                <flux:spacer />

                @if ($line['product_id'])
                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="pencil-square"
                        wire:click="openModifiers('{{ $line['product_id'] }}', {{ $index }})"
                        :aria-label="__('pos.modifiers')"
                    />
                @endif

                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="trash"
                    wire:click="removeLine({{ $index }})"
                    :aria-label="__('pos.remove')"
                    data-test="remove-line"
                />
            </div>
        </li>
    @endforeach
</ul>

@if ($cart === [])
    <x-empty-state
        icon="shopping-bag"
        :heading="__('pos.cart_empty')"
        :description="__('pos.cart_empty_description')"
        class="pos-cart-empty"
    />
@endif
