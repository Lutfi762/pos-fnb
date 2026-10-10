<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\AdvanceTicket;
use App\Domain\Kitchen\Enums\Station;
use App\Domain\Kitchen\Enums\TicketColumn;
use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Sales\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::kitchen')] #[Title('Kitchen')] class extends Component {
    /**
     * Which station this screen is showing. Remembered per device, because a
     * screen bolted above the bar is the bar screen every shift, forever.
     */
    #[Session(key: 'kitchen.station')]
    public string $station = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * The board, grouped into its three columns.
     *
     * @return array<string, list<\App\Domain\Kitchen\Ticket>>
     */
    #[Computed]
    public function columns(): array
    {
        return app(KitchenBoard::class)->columns($this->outlet, Station::tryFrom($this->station));
    }

    /**
     * Move a ticket one column along. One tap, no confirmation: a cook with
     * a pan in the other hand gets one chance to hit the card.
     */
    public function advance(string $orderId, string $station, string $column): void
    {
        $target = TicketColumn::from($column)->next();

        if (! $target instanceof TicketColumn) {
            return;
        }

        app(AdvanceTicket::class)->advance($this->orderFor($orderId), Station::from($station), $target);

        unset($this->columns);
    }

    /**
     * Put a ticket back, for the tap that was not meant.
     */
    public function rewind(string $orderId, string $station, string $column): void
    {
        app(AdvanceTicket::class)->rewind(
            $this->orderFor($orderId),
            Station::from($station),
            TicketColumn::from($column),
        );

        unset($this->columns);
    }

    /**
     * Redraw after another screen moved something.
     */
    public function refreshBoard(): void
    {
        unset($this->columns);
    }

    /**
     * An order at *this* outlet. A ticket id arriving from the browser is
     * still input, so the outlet is part of the lookup, not an assumption.
     */
    private function orderFor(string $orderId): Order
    {
        return Order::query()
            ->where('outlet_id', $this->outlet->getKey())
            ->findOrFail($orderId);
    }
}; ?>

<div
    class="kds"
    x-data="kitchenBoard"
    data-outlet="{{ $this->outlet->getKey() }}"
    wire:poll.10s="refreshBoard"
    data-test="kitchen-board"
>
    <header class="kds-bar">
        <h1 class="kds-title">{{ __('kitchen.title') }}</h1>

        <span class="kds-outlet">{{ $this->outlet->name }}</span>

        <span class="kds-spacer"></span>

        {{-- Station filter. Big plain buttons: nothing to aim a dropdown at. --}}
        <nav class="kds-stations" aria-label="{{ __('kitchen.station_filter') }}">
            <button
                type="button"
                wire:click="$set('station', '')"
                class="kds-station"
                aria-pressed="{{ $station === '' ? 'true' : 'false' }}"
                data-test="station-all"
            >{{ __('kitchen.all_stations') }}</button>

            @foreach (\App\Domain\Kitchen\Enums\Station::cases() as $each)
                <button
                    type="button"
                    wire:key="st-{{ $each->value }}"
                    wire:click="$set('station', '{{ $each->value }}')"
                    class="kds-station"
                    aria-pressed="{{ $station === $each->value ? 'true' : 'false' }}"
                    data-test="station-{{ $each->value }}"
                >{{ $each->label() }}</button>
            @endforeach
        </nav>

        <p class="kds-offline" x-show="disconnected" x-cloak role="status">
            {{ __('kitchen.offline') }}
        </p>
    </header>

    <div class="kds-columns">
        @foreach (\App\Domain\Kitchen\Enums\TicketColumn::cases() as $column)
            @php($tickets = $this->columns[$column->value])

            <section class="kds-column" data-column="{{ $column->value }}" aria-label="{{ $column->label() }}">
                <h2 class="kds-column-head">
                    {{ $column->label() }}
                    <span class="kds-count" data-test="count-{{ $column->value }}">{{ count($tickets) }}</span>
                </h2>

                <ul class="kds-stack">
                    @forelse ($tickets as $ticket)
                        @include('pages.kitchen.ticket', ['ticket' => $ticket, 'column' => $column])
                    @empty
                        <li class="kds-empty">{{ __('kitchen.column_empty') }}</li>
                    @endforelse
                </ul>
            </section>
        @endforeach
    </div>
</div>

@script
<script>
    Alpine.data('kitchenBoard', () => ({
        disconnected: false,
        positions: new Map(),

        init() {
            this.listen();

            // Every redraw: put each card back where it was, then let it slide
            // to where it now belongs. The only motion on this screen, and the
            // only one a cook should ever follow with their eyes.
            Livewire.hook('morph.updated', () => this.flip());

            this.$nextTick(() => this.measure());
        },

        listen() {
            if (! window.Echo) {
                return;
            }

            window.Echo.private(`outlet.${this.$el.dataset.outlet}.kitchen`)
                .listen('.board.changed', () => {
                    this.measure();
                    this.$wire.refreshBoard();
                });

            // A dropped websocket is silent otherwise, and a silent board in a
            // kitchen is worse than a noisy one. The poll keeps it honest.
            window.Echo.connector.pusher?.connection.bind('state_change', (states) => {
                this.disconnected = states.current !== 'connected';
            });
        },

        measure() {
            this.positions.clear();

            this.$el.querySelectorAll('[data-ticket]').forEach((card) => {
                this.positions.set(card.dataset.ticket, card.getBoundingClientRect());
            });
        },

        flip() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                this.positions.clear();

                return;
            }

            this.$el.querySelectorAll('[data-ticket]').forEach((card) => {
                const was = this.positions.get(card.dataset.ticket);

                if (! was) {
                    return;
                }

                const now = card.getBoundingClientRect();
                const dx = was.left - now.left;
                const dy = was.top - now.top;

                if (Math.abs(dx) < 1 && Math.abs(dy) < 1) {
                    return;
                }

                card.animate(
                    [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }],
                    { duration: 240, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' },
                );
            });

            this.positions.clear();
        },
    }));

    // The countdown runs in the browser: a ticking clock is not worth a round
    // trip per second per screen, and the server already set the deadline.
    Alpine.data('kitchenTimer', (sentAt, targetSeconds) => ({
        remaining: targetSeconds,
        urgency: 'calm',
        timer: null,

        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            const elapsed = Math.max(0, Math.floor(Date.now() / 1000) - sentAt);

            this.remaining = targetSeconds - elapsed;
            this.urgency = this.remaining <= 0
                ? 'late'
                : (elapsed >= targetSeconds * 0.75 ? 'close' : 'calm');
        },

        get clock() {
            const total = Math.abs(this.remaining);
            const minutes = Math.floor(total / 60);
            const seconds = (total % 60).toString().padStart(2, '0');

            return `${this.remaining < 0 ? '-' : ''}${minutes}:${seconds}`;
        },
    }));
</script>
@endscript
