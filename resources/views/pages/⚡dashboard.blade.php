<?php

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\SalesReport;
use App\Domain\Reporting\SalesSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    #[Computed]
    public function role(): ?Role
    {
        return app(CurrentOutlet::class)->roleFor(auth()->user());
    }

    /**
     * Cost, margin, staff, and system figures are for the people who run the
     * outlet, not for the cashier at the next till.
     */
    #[Computed]
    public function manages(): bool
    {
        return $this->role?->managesOutlet() ?? false;
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return app(DashboardMetrics::class)->today($this->outlet);
    }

    /**
     * @return array{current: SalesSummary, previous: SalesSummary}
     */
    #[Computed]
    public function pace(): array
    {
        return app(DashboardMetrics::class)->pace($this->outlet);
    }

    /**
     * @return list<array{date: string, label: string, net: int, orders: int, previous: int, current: bool}>
     */
    #[Computed]
    public function week(): array
    {
        return app(DashboardMetrics::class)->week($this->outlet);
    }

    /**
     * @return array{hours: list<int>, rows: list<array{weekday: int, label: string, cells: list<array{hour: int, orders: int, level: int}>}>, peak: array{weekday: string, hour: int, orders: int}|null}
     */
    #[Computed]
    public function heatmap(): array
    {
        return app(DashboardMetrics::class)->heatmap($this->outlet);
    }

    #[Computed]
    public function todayRange(): DateRange
    {
        return DateRange::of($this->today, $this->today);
    }

    /**
     * @return Collection<int, \stdClass&object{name: string, quantity: float, net: int, cost: int}>
     */
    #[Computed]
    public function products(): Collection
    {
        return app(SalesReport::class)->topProducts($this->outlet, $this->todayRange, 5);
    }

    /**
     * @return Collection<int, \stdClass&object{name: string, count: int, total: int, fee: int}>
     */
    #[Computed]
    public function methods(): Collection
    {
        return app(SalesReport::class)->paymentMethods($this->outlet, $this->todayRange);
    }

    /**
     * @return Collection<int, \App\Domain\Inventory\Models\StockLevel>
     */
    #[Computed]
    public function stock(): Collection
    {
        return app(DashboardMetrics::class)->criticalStock($this->outlet);
    }

    /**
     * @return Collection<string, int>
     */
    #[Computed]
    public function waste(): Collection
    {
        return app(DashboardMetrics::class)->wasteByReason($this->outlet);
    }

    /**
     * @return Collection<int, \stdClass&object{name: string, orders: int, net: int}>
     */
    #[Computed]
    public function cashiers(): Collection
    {
        return app(DashboardMetrics::class)->cashiers($this->outlet);
    }

    /**
     * @return array{sync_failed: int, devices_stale: int, static_qris: int, qris_unapplied: int, webhooks_failed: int, last_settlement: string|null, negative_stock: int}
     */
    #[Computed]
    public function status(): array
    {
        return app(DashboardMetrics::class)->status($this->outlet);
    }

    /**
     * Only the problems that exist, each linked to where it is fixed.
     *
     * @return Collection<string, string> status key to page
     */
    #[Computed]
    public function issues(): Collection
    {
        return collect([
            'sync_failed' => route('sync.index'),
            'devices_stale' => route('sync.index'),
            'static_qris' => route('payments.review'),
            'qris_unapplied' => route('payments.review'),
            'webhooks_failed' => route('payments.review'),
            'negative_stock' => route('stock.index'),
        ])->filter(fn (string $url, string $key): bool => $this->status[$key] > 0);
    }

    /**
     * @return list<array{outlet: Outlet, net: int, orders: int, previous: int, changeBps: int}>
     */
    #[Computed]
    public function outlets(): array
    {
        $user = auth()->user();

        return $user->isOwner() && $user->accessibleOutlets()->count() > 1
            ? app(DashboardMetrics::class)->outlets($user)
            : [];
    }

    #[Computed]
    public function greeting(): string
    {
        $hour = CarbonImmutable::now()->setTimezone(app(BusinessCalendar::class)->clockOf($this->outlet)['zone'])->hour;

        $part = match (true) {
            $hour >= 4 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 15 => 'midday',
            $hour >= 15 && $hour < 18 => 'afternoon',
            default => 'evening',
        };

        return __('dashboard.greeting.'.$part, ['name' => str(auth()->user()->name)->before(' ')->toString()]);
    }

    /**
     * Polled every minute while the page is visible. Everything is computed
     * fresh; the chart is handed its new numbers separately because it lives
     * outside Livewire's reach.
     */
    public function refresh(): void
    {
        unset($this->pace, $this->week, $this->heatmap, $this->products, $this->methods, $this->stock, $this->waste, $this->cashiers, $this->status, $this->issues, $this->outlets, $this->today, $this->todayRange);

        $this->dispatch('dashboard-week', week: $this->week);
    }

    /**
     * Change against last week in basis points, as a word and a percentage.
     *
     * @return array{tone: string, text: string}
     */
    public function delta(int $current, int $previous): array
    {
        // Nothing to compare with is not "flat": it is said plainly, not
        // dressed up as a change.
        if ($previous === 0) {
            return ['tone' => 'none', 'text' => __('dashboard.kpi.new')];
        }

        $bps = (int) round(($current - $previous) * 10000 / $previous);
        $percent = number_format(abs($bps) / 100, 1, ',', '.').'%';

        return match (true) {
            $bps > 0 => ['tone' => 'up', 'text' => __('dashboard.kpi.up', ['percent' => $percent])],
            $bps < 0 => ['tone' => 'down', 'text' => __('dashboard.kpi.down', ['percent' => $percent])],
            default => ['tone' => 'flat', 'text' => __('dashboard.kpi.flat')],
        };
    }
}; ?>

<div class="dash" wire:poll.visible.60s="refresh">
    @php
        $current = $this->pace['current'];
        $previous = $this->pace['previous'];
        $weekday = $this->today->subWeek()->translatedFormat('l');
        $clock = app(\App\Domain\Identity\BusinessCalendar::class)->clockOf($this->outlet);

        $kpis = [
            ['key' => 'net', 'label' => __('dashboard.kpi.net_sales'), 'value' => $current->netSales, 'money' => true, 'delta' => $this->delta($current->netSales, $previous->netSales), 'note' => $previous->netSales > 0 ? __('dashboard.kpi.vs', ['day' => $weekday]) : null, 'lead' => true],
            ['key' => 'orders', 'label' => __('dashboard.kpi.orders'), 'value' => $current->orders, 'money' => false, 'delta' => $this->delta($current->orders, $previous->orders), 'note' => __('dashboard.kpi.guests', ['count' => $current->guests])],
            ['key' => 'average', 'label' => __('dashboard.kpi.average'), 'value' => $current->averageOrder(), 'money' => true, 'delta' => $this->delta($current->averageOrder(), $previous->averageOrder()), 'note' => $previous->orders > 0 ? __('dashboard.kpi.vs', ['day' => $weekday]) : null],
        ];

        if ($this->manages) {
            $kpis[] = ['key' => 'profit', 'label' => __('dashboard.kpi.gross_profit'), 'value' => $current->grossProfit(), 'money' => true, 'delta' => null, 'note' => __('dashboard.kpi.margin', ['percent' => number_format($current->marginBps() / 100, 1, ',', '.').'%'])];
            $kpis[] = ['key' => 'refunds', 'label' => __('dashboard.kpi.refunds'), 'value' => $current->refunded, 'money' => true, 'delta' => null, 'note' => __('dashboard.kpi.refunds_note', ['count' => $current->refunds])];
            $kpis[] = ['key' => 'waste', 'label' => __('dashboard.kpi.waste'), 'value' => $current->waste, 'money' => true, 'delta' => null, 'note' => __('dashboard.kpi.waste_note')];
        }
    @endphp

    <header class="dash-head motion-rise">
        <div class="min-w-0">
            <flux:heading size="xl" class="font-display" data-test="dash-greeting">{{ $this->greeting }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('dashboard.business_day', [
                    'outlet' => $this->outlet->name,
                    'date' => $this->today->translatedFormat('l, d M Y'),
                    'hour' => str_pad((string) $clock['hour'], 2, '0', STR_PAD_LEFT).'.00',
                ]) }}
                &middot; {{ __('dashboard.updated', ['time' => now()->setTimezone($clock['zone'])->format('H:i')]) }}
            </flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="chart-bar" :href="route('reports.index')" wire:navigate>{{ __('dashboard.open_reports') }}</flux:button>
            <flux:button variant="primary" icon="calculator" :href="route('pos.index')" wire:navigate>{{ __('dashboard.open_pos') }}</flux:button>
        </div>
    </header>

    {{-- Today so far. Each figure is compared with the same weekday last
         week at the same minute, so a quiet morning is not read as a bad day. --}}
    <dl class="dash-kpis">
        @foreach ($kpis as $index => $kpi)
            <div @class(['dash-kpi motion-rise', 'dash-kpi-lead' => $kpi['lead'] ?? false]) style="animation-delay: {{ $index * 50 }}ms" wire:key="kpi-{{ $kpi['key'] }}">
                <dt>{{ $kpi['label'] }}</dt>
                <dd
                    class="dash-kpi-value"
                    wire:key="kpi-value-{{ $kpi['key'] }}-{{ $kpi['value'] }}"
                    x-data="countUp({{ $kpi['value'] }}, {{ $kpi['money'] ? 'true' : 'false' }}, '{{ $kpi['key'] }}')"
                    x-text="display"
                    data-test="kpi-{{ $kpi['key'] }}"
                >{{ $kpi['money'] ? \App\Support\Money::format($kpi['value']) : number_format($kpi['value'], 0, ',', '.') }}</dd>
                <p class="dash-kpi-note">
                    @if ($kpi['delta'])
                        <span class="dash-delta dash-delta-{{ $kpi['delta']['tone'] }}" data-test="delta-{{ $kpi['key'] }}">
                            @if ($kpi['delta']['tone'] === 'up')
                                <flux:icon.arrow-trending-up variant="micro" />
                            @elseif ($kpi['delta']['tone'] === 'down')
                                <flux:icon.arrow-trending-down variant="micro" />
                            @endif
                            {{ $kpi['delta']['text'] }}
                        </span>
                    @endif
                    @if ($kpi['note'])
                        <span>{{ $kpi['note'] }}</span>
                    @endif
                </p>
            </div>
        @endforeach
    </dl>

    <div class="dash-grid">
        {{-- Seven trading days, this week against last. --}}
        @php($week = $this->week)
        @php($best = collect($week)->sortByDesc('net')->first())
        <article class="dash-card dash-card-wide motion-rise" aria-labelledby="dash-week-title">
            <div class="dash-card-head">
                <div>
                    <flux:heading id="dash-week-title" size="lg">{{ __('dashboard.week.title') }}</flux:heading>
                    <flux:text class="text-content-muted">{{ __('dashboard.week.subtitle') }}</flux:text>
                </div>
                <ul class="dash-legend" aria-hidden="true">
                    <li><span class="dash-swatch dash-swatch-brand"></span>{{ __('dashboard.week.this_week') }}</li>
                    <li><span class="dash-swatch dash-swatch-muted"></span>{{ __('dashboard.week.last_week') }}</li>
                </ul>
            </div>

            <div
                class="dash-chart"
                wire:ignore
                x-data="weekChart(@js($week), @js(['thisWeek' => __('dashboard.week.this_week'), 'lastWeek' => __('dashboard.week.last_week'), 'today' => __('dashboard.week.today')]))"
                @dashboard-week.window="update($event.detail.week)"
            >
                <canvas
                    x-ref="canvas"
                    role="img"
                    aria-label="{{ __('dashboard.week.chart_label', ['total' => \App\Support\Money::format((int) collect($week)->sum('net')), 'best' => $best ? \Carbon\CarbonImmutable::parse($best['date'])->translatedFormat('l').' '.\App\Support\Money::format($best['net']) : '-']) }}"
                    data-test="week-chart"
                ></canvas>
            </div>

            <details class="dash-details">
                <summary>{{ __('dashboard.week.show_table') }}</summary>
                <table class="report-table" data-test="week-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('dashboard.week.day') }}</th>
                            <th scope="col">{{ __('dashboard.kpi.orders') }}</th>
                            <th scope="col">{{ __('dashboard.week.this_week') }}</th>
                            <th scope="col">{{ __('dashboard.week.last_week') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($week as $day)
                            <tr wire:key="wk-{{ $day['date'] }}">
                                <th scope="row" class="text-start font-normal">{{ \Carbon\CarbonImmutable::parse($day['date'])->translatedFormat('l, d M') }}@if ($day['current']) <span class="text-content-muted">({{ __('dashboard.week.today') }})</span>@endif</th>
                                <td class="numeric">{{ $day['orders'] }}</td>
                                <td><x-money :amount="$day['net']" /></td>
                                <td><x-money :amount="$day['previous']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </article>

        {{-- Best sellers today. --}}
        @php($topNet = max(1, (int) $this->products->max('net')))
        <article class="dash-card motion-rise" aria-labelledby="dash-products-title">
            <flux:heading id="dash-products-title" size="lg">{{ __('dashboard.products.title') }}</flux:heading>

            @if ($this->products->isEmpty())
                <flux:text class="text-content-muted">{{ __('dashboard.products.empty') }}</flux:text>
            @else
                <ol class="dash-bars" data-test="top-products">
                    @foreach ($this->products as $product)
                        <li wire:key="tp-{{ $loop->index }}" style="--share: {{ (int) round($product->net * 100 / $topNet) }}%">
                            <div class="dash-bar-row">
                                <span class="min-w-0 truncate">{{ $product->name }}</span>
                                <x-money :amount="$product->net" />
                            </div>
                            <span class="dash-bar" aria-hidden="true"><span></span></span>
                            <span class="dash-bar-note">{{ __('dashboard.products.sold', ['quantity' => \App\Support\Quantity::format($product->quantity)]) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </article>

        {{-- When the rush comes. --}}
        @php($heat = $this->heatmap)
        <article class="dash-card dash-card-wide motion-rise" aria-labelledby="dash-heat-title">
            <div class="dash-card-head">
                <div>
                    <flux:heading id="dash-heat-title" size="lg">{{ __('dashboard.heatmap.title') }}</flux:heading>
                    <flux:text class="text-content-muted">{{ __('dashboard.heatmap.subtitle') }}</flux:text>
                </div>
                <div class="dash-heat-scale" aria-hidden="true">
                    <span>{{ __('dashboard.heatmap.less') }}</span>
                    @foreach (range(0, 4) as $level)
                        <span class="dash-heat-cell dash-heat-{{ $level }}"></span>
                    @endforeach
                    <span>{{ __('dashboard.heatmap.more') }}</span>
                </div>
            </div>

            @if ($heat['peak'] === null)
                <flux:text class="text-content-muted" data-test="heatmap-empty">{{ __('dashboard.heatmap.empty') }}</flux:text>
            @else
                <div class="dash-heat-wrap">
                    <table class="dash-heat" data-test="heatmap">
                        <thead>
                            <tr>
                                <td></td>
                                @foreach ($heat['hours'] as $hour)
                                    <th scope="col">{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($heat['rows'] as $row)
                                <tr wire:key="hr-{{ $row['weekday'] }}">
                                    <th scope="row">{{ $row['label'] }}</th>
                                    @foreach ($row['cells'] as $cell)
                                        @php($label = __('dashboard.heatmap.cell', ['day' => $row['label'], 'hour' => str_pad((string) $cell['hour'], 2, '0', STR_PAD_LEFT).'.00', 'orders' => $cell['orders']]))
                                        <td class="dash-heat-cell dash-heat-{{ $cell['level'] }}" title="{{ $label }}">
                                            <span class="sr-only">{{ $label }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <flux:text class="text-sm" data-test="heatmap-peak">
                    {{ __('dashboard.heatmap.peak', ['day' => $heat['peak']['weekday'], 'hour' => str_pad((string) $heat['peak']['hour'], 2, '0', STR_PAD_LEFT).'.00', 'orders' => $heat['peak']['orders']]) }}
                </flux:text>
            @endif
        </article>

        {{-- How the money came in today. --}}
        @php($methodTotal = max(1, (int) $this->methods->sum('total')))
        <article class="dash-card motion-rise" aria-labelledby="dash-methods-title">
            <flux:heading id="dash-methods-title" size="lg">{{ __('dashboard.methods.title') }}</flux:heading>

            @if ($this->methods->isEmpty())
                <flux:text class="text-content-muted">{{ __('dashboard.methods.empty') }}</flux:text>
            @else
                <ul class="dash-bars" data-test="methods">
                    @foreach ($this->methods as $method)
                        <li wire:key="pm-{{ $loop->index }}" style="--share: {{ (int) round($method->total * 100 / $methodTotal) }}%">
                            <div class="dash-bar-row">
                                <span>{{ $method->name }} <span class="text-content-muted">&times;{{ $method->count }}</span></span>
                                <x-money :amount="$method->total" />
                            </div>
                            <span class="dash-bar dash-bar-accent" aria-hidden="true"><span></span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>

        @if ($this->role?->managesStock())
            <article class="dash-card motion-rise" aria-labelledby="dash-stock-title">
                <div class="dash-card-head">
                    <flux:heading id="dash-stock-title" size="lg">{{ __('dashboard.stock.title') }}</flux:heading>
                    <flux:link :href="route('stock.index')" wire:navigate class="inline-flex min-h-11 items-center text-sm">{{ __('dashboard.stock.open') }}</flux:link>
                </div>

                @if ($this->stock->isEmpty())
                    <flux:text class="text-content-muted">{{ __('dashboard.stock.empty') }}</flux:text>
                @else
                    <ul class="dash-list" data-test="critical-stock">
                        @foreach ($this->stock as $level)
                            @php($unit = $level->item?->unit?->code)
                            <li wire:key="cs-{{ $level->id }}">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ $level->item?->name }}</span>
                                    <span class="block text-sm text-content-muted">
                                        @if ((float) $level->quantity < 0)
                                            {{ __('dashboard.stock.negative') }}
                                        @else
                                            {{ __('dashboard.stock.reorder', ['quantity' => \App\Support\Quantity::format((float) $level->reorder_point)]) }}
                                        @endif
                                    </span>
                                </span>
                                <span @class(['dash-pill', 'dash-pill-danger' => (float) $level->quantity <= 0, 'dash-pill-pending' => (float) $level->quantity > 0])>
                                    {{ __('dashboard.stock.left', ['quantity' => \App\Support\Quantity::format((float) $level->quantity), 'unit' => $unit]) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @endif

        @if ($this->role?->logsWaste())
            <article class="dash-card motion-rise" aria-labelledby="dash-waste-title">
                <div class="dash-card-head">
                    <flux:heading id="dash-waste-title" size="lg">{{ __('dashboard.waste.title') }}</flux:heading>
                    <flux:link :href="route('stock.waste')" wire:navigate class="inline-flex min-h-11 items-center text-sm">{{ __('dashboard.waste.open') }}</flux:link>
                </div>

                @if ($this->waste->isEmpty())
                    <flux:text class="text-content-muted">{{ __('dashboard.waste.empty') }}</flux:text>
                @else
                    <ul class="dash-list" data-test="waste-reasons">
                        @foreach ($this->waste as $reason => $value)
                            <li wire:key="wr-{{ $reason }}">
                                <span>{{ __('waste.reasons.'.$reason) }}</span>
                                <x-money :amount="$value" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @endif

        @if ($this->manages)
            <article class="dash-card motion-rise" aria-labelledby="dash-cashiers-title">
                <flux:heading id="dash-cashiers-title" size="lg">{{ __('dashboard.cashiers.title') }}</flux:heading>

                @if ($this->cashiers->isEmpty())
                    <flux:text class="text-content-muted">{{ __('dashboard.cashiers.empty') }}</flux:text>
                @else
                    <ul class="dash-list" data-test="cashiers">
                        @foreach ($this->cashiers as $cashier)
                            <li wire:key="ca-{{ $loop->index }}">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ $cashier->name }}</span>
                                    <span class="block text-sm text-content-muted">{{ __('dashboard.cashiers.orders', ['count' => $cashier->orders]) }}</span>
                                </span>
                                <x-money :amount="$cashier->net" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>

            {{-- Things that go wrong quietly. --}}
            @php($status = $this->status)
            @php($issues = $this->issues)
            <article class="dash-card motion-rise" aria-labelledby="dash-status-title">
                <flux:heading id="dash-status-title" size="lg">{{ __('dashboard.status.title') }}</flux:heading>

                <ul class="dash-list" data-test="system-status">
                    @forelse ($issues as $key => $url)
                        <li wire:key="st-{{ $key }}">
                            <a href="{{ $url }}" wire:navigate class="dash-issue">
                                <flux:icon.exclamation-triangle variant="mini" class="text-pending-text" />
                                <span>{{ __('dashboard.status.'.$key, ['count' => $status[$key]]) }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="dash-ok">
                            <flux:icon.check-circle variant="mini" class="text-done" />
                            <span>{{ __('dashboard.status.all_good') }}</span>
                        </li>
                    @endforelse
                    <li class="text-sm text-content-muted">
                        {{ $status['last_settlement'] ? __('dashboard.status.last_settlement', ['date' => \Carbon\CarbonImmutable::parse($status['last_settlement'])->translatedFormat('d M Y')]) : __('dashboard.status.no_settlement') }}
                    </li>
                </ul>
            </article>
        @endif

        @if ($this->outlets !== [])
            <article class="dash-card dash-card-wide motion-rise" aria-labelledby="dash-outlets-title">
                <flux:heading id="dash-outlets-title" size="lg">{{ __('dashboard.outlets.title') }}</flux:heading>

                <div class="relative overflow-x-auto">
                    <table class="report-table" data-test="outlets">
                        <thead>
                            <tr>
                                <th scope="col" class="text-start">{{ __('dashboard.outlets.outlet') }}</th>
                                <th scope="col">{{ __('dashboard.outlets.orders') }}</th>
                                <th scope="col">{{ __('dashboard.outlets.net') }}</th>
                                <th scope="col">{{ __('dashboard.outlets.change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->outlets as $row)
                                @php($delta = $this->delta($row['net'], $row['previous']))
                                <tr wire:key="ol-{{ $row['outlet']->id }}">
                                    <th scope="row" class="text-start font-normal">{{ $row['outlet']->name }}</th>
                                    <td class="numeric">{{ $row['orders'] }}</td>
                                    <td><x-money :amount="$row['net']" /></td>
                                    <td><span class="dash-delta dash-delta-{{ $delta['tone'] }}">{{ $delta['text'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        @endif
    </div>
</div>
