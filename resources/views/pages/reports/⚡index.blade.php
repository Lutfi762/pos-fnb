<?php

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\Enums\Preset;
use App\Domain\Reporting\ExportSales;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportCatalog;
use App\Domain\Reporting\SalesReport;
use App\Domain\Reporting\SalesSummary;
use App\Domain\Reporting\Tables\ReportTable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts::app')] #[Title('Reports')] class extends Component {
    /** Tabs and the report each one shows. */
    public const TABS = ['summary' => 'daily', 'products' => 'products', 'gross_profit' => 'gross_profit', 'cash_flow' => 'cash_flow'];

    #[Url(as: 'rentang', except: 'today')]
    public string $preset = 'today';

    /** Both empty unless the manager typed their own dates. */
    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    #[Url(as: 'tab', except: 'summary')]
    public string $tab = 'summary';

    #[Url(as: 'banding', except: false)]
    public bool $compare = false;

    /**
     * A link with its own range wins. Otherwise the range picked last time
     * comes back, so a manager who reads "this month" daily is not reset to
     * today every morning.
     */
    public function mount(): void
    {
        $remembered = session('reports.range');

        if ($this->preset === 'today' && $this->from === '' && is_array($remembered)) {
            $this->preset = Preset::tryFrom((string) ($remembered['preset'] ?? ''))?->value ?? 'today';
            $this->from = (string) ($remembered['from'] ?? '');
            $this->to = (string) ($remembered['to'] ?? '');
        }

        if (! array_key_exists($this->tab, self::TABS) || ! $this->tabAllowed($this->tab)) {
            $this->tab = 'summary';
        }
    }

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * Costs, margins, and cash are management figures, not something a
     * cashier needs while ringing up the next guest.
     */
    #[Computed]
    public function canSeeMargin(): bool
    {
        return app(CurrentOutlet::class)->roleFor(auth()->user())?->managesOutlet() ?? false;
    }

    public function tabAllowed(string $tab): bool
    {
        return $this->canSeeMargin || ! in_array(self::TABS[$tab] ?? '', ReportCatalog::MANAGEMENT, true);
    }

    /**
     * Typed dates win over the preset buttons; an unparseable date falls back
     * to the preset rather than throwing at someone reading a report.
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
     * @return Collection<int, \stdClass&object{date: string, orders: int, net: int, refunded: int, waste: int}>
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

    /**
     * The open tab's report, the same rows its Excel and PDF files hold.
     */
    #[Computed]
    public function table(): ?ReportTable
    {
        if ($this->tab === 'summary') {
            return null;
        }

        return app(ReportCatalog::class)->build(self::TABS[$this->tab], $this->outlet, $this->range, withCost: $this->canSeeMargin);
    }

    /**
     * This person's recent background exports.
     *
     * @return Collection<int, ReportExport>
     */
    #[Computed]
    public function exports(): Collection
    {
        if (! $this->canSeeMargin) {
            return collect();
        }

        return ReportExport::query()
            ->where('user_id', auth()->id())
            ->where('outlet_id', $this->outlet->getKey())
            ->latest()
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function exportsPending(): bool
    {
        return $this->exports->contains(fn (ReportExport $export): bool => in_array($export->status, [ReportExport::QUEUED, ReportExport::RUNNING], true));
    }

    /**
     * Where a download for the open tab comes from.
     */
    public function exportUrl(string $format): string
    {
        return route('reports.export', [
            'type' => self::TABS[$this->tab],
            'format' => $format,
            'from' => $this->range->fromDate(),
            'to' => $this->range->toDate(),
        ]);
    }

    public function usePreset(string $preset): void
    {
        $this->preset = (Preset::tryFrom($preset) ?? Preset::Today)->value;
        $this->reset('from', 'to');
        $this->remember();
    }

    public function useTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS) && $this->tabAllowed($tab), 403);

        $this->tab = $tab;
        unset($this->table);
    }

    public function updatedFrom(): void
    {
        $this->remember();
    }

    public function updatedTo(): void
    {
        $this->remember();
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

    private function remember(): void
    {
        session()->put('reports.range', ['preset' => $this->preset, 'from' => $this->from, 'to' => $this->to]);
    }
}; ?>

<div class="report" @if ($this->exportsPending) wire:poll.5s @endif>
    <div class="report-head">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('reports.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('reports.subtitle', ['outlet' => $this->outlet->name]) }} &middot; {{ $this->range->label() }}
            </flux:text>
        </div>

        @if ($this->canSeeMargin)
            {{-- Files carry cost and cash: managers and owners only. PDFs open
                 in a new tab, so a printable fallback never replaces this page. --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down" data-test="export-menu">{{ __('reports.download') }}</flux:button>

                <flux:menu>
                    <flux:menu.item icon="table-cells" :href="$this->exportUrl('xlsx')" data-test="export-xlsx">{{ __('reports.export_excel') }}</flux:menu.item>
                    <flux:menu.item icon="document-text" :href="$this->exportUrl('pdf')" target="_blank" data-test="export-pdf">{{ __('reports.export_pdf') }}</flux:menu.item>
                    @if ($tab === 'summary')
                        <flux:menu.item icon="document-arrow-down" wire:click="export" data-test="export">{{ __('reports.export') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>

    @if (session('export_queued'))
        <flux:callout icon="clock" data-test="export-queued">
            <flux:callout.text>{{ __('reports.export_queued') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Range picker. The buttons answer most questions; the two date fields
         are there for the month-end ones. --}}
    <div class="report-range">
        <flux:button.group class="flex-wrap">
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

    <div class="report-tabs" role="tablist" aria-label="{{ __('reports.title') }}">
        @foreach (array_keys($this::TABS) as $key)
            @if ($this->tabAllowed($key))
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['report-tab', 'report-tab-on' => $tab === $key])
                    wire:click="useTab('{{ $key }}')"
                    data-test="tab-{{ $key }}"
                >{{ __('reports.tabs.'.$key) }}</button>
            @endif
        @endforeach
    </div>

    @if ($tab === 'summary')
        <flux:checkbox wire:model.live="compare" :label="__('reports.compare')" data-test="compare" />

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
                @if ($compare)
                    <p class="report-tile-note" data-test="previous-net">{{ __('reports.previous_value', ['value' => \App\Support\Money::format($this->previous->netSales)]) }}</p>
                @endif
            </div>

            <div class="report-tile">
                <dt>{{ __('reports.orders') }}</dt>
                <dd class="numeric tabular-nums" data-test="orders">{{ $this->summary->orders }}</dd>
                <p class="report-tile-note">{{ __('reports.guests') }}: {{ $this->summary->guests }}</p>
                @if ($compare)
                    <p class="report-tile-note">{{ __('reports.previous_value', ['value' => $this->previous->orders]) }}</p>
                @endif
            </div>

            <div class="report-tile">
                <dt>{{ __('reports.average_order') }}</dt>
                <dd><x-money :amount="$this->summary->averageOrder()" /></dd>
                <p class="report-tile-note">
                    {{ __('reports.average_guest') }}: {{ \App\Support\Money::format($this->summary->averageGuest()) }}
                </p>
                @if ($compare)
                    <p class="report-tile-note">{{ __('reports.previous_value', ['value' => \App\Support\Money::format($this->previous->averageOrder())]) }}</p>
                @endif
            </div>

            @if ($this->canSeeMargin)
                <div class="report-tile">
                    <dt>{{ __('reports.gross_profit') }}</dt>
                    <dd><x-money :amount="$this->summary->grossProfit()" data-test="gross-profit" /></dd>
                    <p class="report-tile-note">
                        {{ __('reports.margin') }} {{ $this->percent($this->summary->marginBps()) }}
                        &middot; {{ __('reports.cost') }} {{ \App\Support\Money::format($this->summary->cost) }}
                    </p>
                    @if ($compare)
                        <p class="report-tile-note">{{ __('reports.previous_value', ['value' => \App\Support\Money::format($this->previous->grossProfit())]) }}</p>
                    @endif
                </div>

                <div class="report-tile">
                    <dt>{{ __('reports.net_after_refunds') }}</dt>
                    <dd><x-money :amount="$this->summary->netAfterRefunds()" data-test="net-after-refunds" /></dd>
                    <p class="report-tile-note">
                        {{ __('reports.refunds') }} {{ \App\Support\Money::format($this->summary->refunded) }}
                        &middot; {{ __('reports.waste') }} {{ \App\Support\Money::format($this->summary->waste) }}
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
    @elseif ($this->table?->isEmpty())
        <x-empty-state
            icon="chart-bar"
            :heading="__('reports.empty_heading')"
            :description="__('reports.empty_description')"
        />
    @elseif ($this->table)
        <section class="report-panel" aria-labelledby="report-table-heading">
            <flux:heading id="report-table-heading" size="lg">{{ $this->table->title }}</flux:heading>
            @if (isset($this->table->meta[__('reports.note')]))
                <flux:text class="text-content-muted">{{ $this->table->meta[__('reports.note')] }}</flux:text>
            @endif

            <x-report-table :table="$this->table" data-test="report-table" />
        </section>
    @endif

    @if ($this->exports->isNotEmpty())
        <section class="report-panel" aria-labelledby="report-exports-heading" data-test="exports">
            <flux:heading id="report-exports-heading" size="lg">{{ __('reports.exports_heading') }}</flux:heading>

            <ul class="flex flex-col gap-2">
                @foreach ($this->exports as $export)
                    <li wire:key="ex-{{ $export->id }}" class="flex flex-wrap items-center justify-between gap-3">
                        <span class="min-w-0">
                            <span class="block font-medium">{{ __('reports.types.'.$export->type) }} &middot; {{ strtoupper($export->format) }}</span>
                            <span class="block text-sm text-content-muted">
                                {{ $export->range()->label() }} &middot; {{ number_format($export->rows, 0, ',', '.') }} {{ __('reports.rows') }}
                                @if ($export->downloadable())
                                    &middot; {{ __('reports.expires', ['time' => $export->expires_at?->translatedFormat('d M H:i')]) }}
                                @endif
                            </span>
                        </span>

                        @if ($export->downloadable())
                            <flux:button size="sm" icon="arrow-down-tray" class="min-h-11" :href="route('reports.exports.download', $export)" data-test="export-download">{{ __('reports.download') }}</flux:button>
                        @else
                            <span class="text-sm text-content-muted">{{ __('reports.export_status.'.$export->status) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
