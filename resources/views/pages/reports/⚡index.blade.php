<?php

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\Enums\Preset;
use App\Domain\Reporting\ExportSales;
use App\Domain\Reporting\SalesReport;
use App\Domain\Reporting\SalesSummary;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts::app')] #[Title('Laporan')] class extends Component {
    #[Url(as: 'rentang', except: 'today')]
    public string $preset = 'today';

    /** Both empty unless the manager typed their own dates. */
    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * Costs and margins are management figures, not something a cashier needs
     * while ringing up the next guest.
     */
    #[Computed]
    public function canSeeMargin(): bool
    {
        return app(CurrentOutlet::class)->roleFor(auth()->user())?->managesOutlet() ?? false;
    }

    /**
     * Typed dates win over the preset buttons; an unparseable date falls back
     * to today rather than throwing at someone reading a report.
     */
    #[Computed]
    public function range(): DateRange
    {
        if ($this->from !== '' && $this->to !== '') {
            try {
                return DateRange::of($this->from, $this->to);
            } catch (\Throwable) {
                // Fall through to the preset.
            }
        }

        return (Preset::tryFrom($this->preset) ?? Preset::Today)->range(app(BusinessCalendar::class)->today($this->outlet));
    }

    #[Computed]
    public function summary(): SalesSummary
    {
        return app(SalesReport::class)->summary($this->outlet, $this->range);
    }

    #[Computed]
    public function previous(): SalesSummary
    {
        return app(SalesReport::class)->summary($this->outlet, $this->range->previous());
    }

    /**
     * @return Collection<int, \stdClass&object{date: string, orders: int, net: int}>
     */
    #[Computed]
    public function daily(): Collection
    {
        return app(SalesReport::class)->daily($this->outlet, $this->range);
    }

    /**
     * The tallest bar in the chart, used to scale the rest. Never zero, so the
     * division below is always safe.
     */
    #[Computed]
    public function peak(): int
    {
        return max(1, (int) $this->daily->max('net'));
    }

    /**
     * @return Collection<int, \stdClass&object{name: string, quantity: float, net: int, cost: int}>
     */
    #[Computed]
    public function topProducts(): Collection
    {
        return app(SalesReport::class)->topProducts($this->outlet, $this->range);
    }

    /**
     * @return Collection<int, \stdClass&object{name: string, count: int, total: int, fee: int}>
     */
    #[Computed]
    public function methods(): Collection
    {
        return app(SalesReport::class)->paymentMethods($this->outlet, $this->range);
    }

    /**
     * @return Collection<int, \App\Domain\Sales\Models\CashierShift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        return app(SalesReport::class)->shifts($this->outlet, $this->range);
    }

    public function usePreset(string $preset): void
    {
        $this->preset = (Preset::tryFrom($preset) ?? Preset::Today)->value;
        $this->reset('from', 'to');
    }

    public function export(): StreamedResponse
    {
        abort_unless($this->canSeeMargin, 403);

        return app(ExportSales::class)->handle($this->outlet, $this->range);
    }

    /**
     * Basis points as a percentage string, e.g. 6350 => "63,5%".
     */
    public function percent(int $bps): string
    {
        return number_format($bps / 100, 1, ',', '.').'%';
    }
}; ?>

<div class="report">
    <div class="report-head">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('reports.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('reports.subtitle', ['outlet' => $this->outlet->name]) }} &middot; {{ $this->range->label() }}
            </flux:text>
        </div>

        @if ($this->canSeeMargin)
            <flux:button icon="arrow-down-tray" wire:click="export" data-test="export">
                {{ __('reports.export') }}
            </flux:button>
        @endif
    </div>

    {{-- Range picker. The four buttons answer most questions; the two date
         fields are there for the month-end ones. --}}
    <div class="report-range">
        <flux:button.group>
            @foreach (\App\Domain\Reporting\Enums\Preset::cases() as $case)
                <flux:button
                    size="sm"
                    :variant="$this->from === '' && $preset === $case->value ? 'primary' : 'outline'"
                    wire:click="usePreset('{{ $case->value }}')"
                    data-test="preset-{{ $case->value }}"
                >
                    {{ $case->label() }}
                </flux:button>
            @endforeach
        </flux:button.group>

        <div class="report-dates">
            <flux:input type="date" wire:model.live="from" :label="__('reports.from')" size="sm" data-test="from" />
            <flux:input type="date" wire:model.live="to" :label="__('reports.to')" size="sm" data-test="to" />
        </div>
    </div>

    {{-- Headline figures. Net sales leads because it is the one that is
         actually the outlet's own money. --}}
    <dl class="report-tiles">
        <div class="report-tile report-tile-lead">
            <dt>{{ __('reports.net_sales') }}</dt>
            <dd><x-money :amount="$this->summary->netSales" data-test="net-sales" /></dd>
            <p class="report-tile-note">
                @php($change = $this->summary->changeBps($this->previous))
                @if ($change > 0)
                    {{ __('reports.up', ['percent' => $this->percent($change)]) }}
                @elseif ($change < 0)
                    {{ __('reports.down', ['percent' => $this->percent(abs($change))]) }}
                @else
                    {{ __('reports.flat') }}
                @endif
                &middot; {{ __('reports.vs_previous', ['days' => $this->range->days()]) }}
            </p>
        </div>

        <div class="report-tile">
            <dt>{{ __('reports.orders') }}</dt>
            <dd class="numeric tabular-nums" data-test="orders">{{ $this->summary->orders }}</dd>
            <p class="report-tile-note">{{ __('reports.guests') }}: {{ $this->summary->guests }}</p>
        </div>

        <div class="report-tile">
            <dt>{{ __('reports.average_order') }}</dt>
            <dd><x-money :amount="$this->summary->averageOrder()" /></dd>
            <p class="report-tile-note">
                {{ __('reports.average_guest') }}: {{ \App\Support\Money::format($this->summary->averageGuest()) }}
            </p>
        </div>

        @if ($this->canSeeMargin)
            <div class="report-tile">
                <dt>{{ __('reports.gross_profit') }}</dt>
                <dd><x-money :amount="$this->summary->grossProfit()" data-test="gross-profit" /></dd>
                <p class="report-tile-note">
                    {{ __('reports.margin') }} {{ $this->percent($this->summary->marginBps()) }}
                    &middot; {{ __('reports.cost') }} {{ \App\Support\Money::format($this->summary->cost) }}
                </p>
            </div>
        @endif
    </dl>

    @if ($this->summary->orders === 0)
        <x-empty-state
            icon="chart-bar"
            :heading="__('reports.empty_heading')"
            :description="__('reports.empty_description')"
        />
    @else
        @include('pages.reports.daily')
        @include('pages.reports.tables')
    @endif
</div>
