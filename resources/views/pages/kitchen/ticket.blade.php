{{--
    One card. The whole card is the button that moves it on, because a cook
    aims with a knuckle, not a fingertip. Undo is a small separate target so
    it can never be hit by the tap that was meant for the card.
--}}
<li
    class="kds-ticket"
    wire:key="t-{{ $ticket->key() }}"
    data-ticket="{{ $ticket->key() }}"
    x-data="kitchenTimer({{ $ticket->sentAt->getTimestamp() }}, {{ $ticket->targetSeconds }})"
    :data-urgency="urgency"
>
    <button
        type="button"
        class="kds-ticket-face"
        @if ($column->next())
            wire:click="advance('{{ $ticket->orderId }}', '{{ $ticket->station->value }}', '{{ $column->value }}')"
        @else
            disabled
        @endif
        data-test="ticket-{{ $ticket->key() }}"
    >
        <span class="kds-ticket-head">
            <span class="kds-number">{{ $ticket->number }}</span>
            <span class="kds-where">{{ $ticket->where }}</span>
            <time class="kds-clock" x-text="clock" :datetime="clock">{{ __('kitchen.remaining') }}</time>
        </span>

        <span class="kds-lines">
            @foreach ($ticket->lines as $line)
                <span class="kds-line">
                    <span class="kds-qty">{{ $line['quantity'] }}&times;</span>
                    <span class="kds-name">{{ $line['name'] }}</span>

                    @foreach ($line['modifiers'] as $modifier)
                        <span class="kds-mod">+ {{ $modifier }}</span>
                    @endforeach

                    @if ($line['note'])
                        <span class="kds-note">{{ $line['note'] }}</span>
                    @endif
                </span>
            @endforeach
        </span>

        @if ($column->next())
            <span class="kds-action">
                {{ $column->value === 'new' ? __('kitchen.start') : __('kitchen.finish') }}
            </span>
        @endif
    </button>

    @if ($column->previous())
        <button
            type="button"
            class="kds-undo"
            wire:click="rewind('{{ $ticket->orderId }}', '{{ $ticket->station->value }}', '{{ $column->value }}')"
            data-test="undo-{{ $ticket->key() }}"
        >{{ __('kitchen.undo') }}</button>
    @endif

    <span class="kds-station-tag">{{ $ticket->station->label() }}</span>
</li>
