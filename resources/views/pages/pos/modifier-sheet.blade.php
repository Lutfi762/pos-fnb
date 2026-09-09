{{--
    The modifier sheet. Rises from the bottom, drags back down, and the price
    on the confirm button moves as the guest changes their mind.
--}}
@if ($this->modifierProduct)
    <div
        class="pos-sheet-backdrop"
        x-data="{ drag: 0 }"
        @click.self="$wire.closeModifiers()"
        data-test="modifier-sheet"
    >
        <div
            class="pos-sheet"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sheet-title"
            :style="drag > 0 && `transform: translateY(${drag}px)`"
            @touchstart="start = $event.touches[0].clientY"
            @touchmove="drag = Math.max(0, $event.touches[0].clientY - start)"
            @touchend="drag > 120 ? $wire.closeModifiers() : drag = 0"
            x-data="{ start: 0 }"
        >
            <span class="pos-sheet-handle" aria-hidden="true"></span>

            <flux:heading id="sheet-title" size="lg">{{ $this->modifierProduct->name }}</flux:heading>

            {{-- Variants first: size is the question people answer fastest. --}}
            @if ($this->modifierProduct->variants->count() > 1)
                <fieldset class="pos-choice-group">
                    <legend class="pos-choice-legend">{{ __('pos.variant') }}</legend>

                    <div class="pos-choices">
                        @foreach ($this->modifierProduct->variants as $variant)
                            <button
                                type="button"
                                wire:key="v-{{ $variant->id }}"
                                wire:click="$set('modifierVariantId', '{{ $variant->id }}')"
                                @class(['pos-choice', 'pos-choice-on' => $modifierVariantId === $variant->id])
                                data-test="variant-option"
                            >
                                <span>{{ $variant->name }}</span>
                                <x-money :amount="$variant->priceFor($this->outlet)" />
                            </button>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            @foreach ($this->modifierProduct->modifierGroups as $group)
                <fieldset class="pos-choice-group" wire:key="g-{{ $group->id }}">
                    <legend class="pos-choice-legend">
                        {{ $group->name }}

                        <span class="pos-choice-rule">
                            @if ($group->min_select > 0 && $group->min_select === $group->max_select)
                                {{ __('pos.choose_exactly', ['count' => $group->min_select]) }}
                            @elseif ($group->min_select > 0)
                                {{ __('pos.choose_min', ['count' => $group->min_select]) }}
                            @else
                                {{ __('pos.optional') }}
                            @endif
                        </span>
                    </legend>

                    <div class="pos-choices">
                        @foreach ($group->modifiers as $modifier)
                            <button
                                type="button"
                                wire:key="m-{{ $modifier->id }}"
                                wire:click="toggleModifier('{{ $group->id }}', '{{ $modifier->id }}')"
                                @class([
                                    'pos-choice',
                                    'pos-choice-on' => in_array($modifier->id, $chosenModifiers[$group->id] ?? [], true),
                                ])
                                data-test="modifier-option"
                            >
                                <span>{{ $modifier->name }}</span>
                                @if ($modifier->price > 0)
                                    <x-money :amount="$modifier->price" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <flux:input
                wire:model="lineNote"
                :label="__('pos.item_note')"
                :placeholder="__('pos.item_note_placeholder')"
                data-test="line-note"
            />

            <flux:error name="modifiers" />

            <div class="pos-sheet-actions">
                <flux:button variant="subtle" wire:click="closeModifiers" class="pos-action">
                    {{ __('pos.cancel') }}
                </flux:button>

                <flux:button variant="primary" wire:click="confirmModifiers" class="pos-action" data-test="confirm-modifiers">
                    {{ __('pos.add_to_cart') }}
                    <x-money :amount="$this->modifierUnitPrice" data-test="sheet-price" />
                </flux:button>
            </div>
        </div>
    </div>
@endif
