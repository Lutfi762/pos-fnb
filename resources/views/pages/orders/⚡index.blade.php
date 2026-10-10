<?php

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\OrderSearch;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Transaksi')] class extends Component {
    use WithPagination;

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    #[Url(as: 'status', except: 'all')]
    public string $status = 'all';

    #[Url(as: 'kasir', except: '')]
    public string $cashier = '';

    #[Url(as: 'metode', except: '')]
    public string $method = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * Managers look back as far as they like and download lists. A cashier
     * sees today's bills, to find one to reprint.
     */
    #[Computed]
    public function manages(): bool
    {
        return app(CurrentOutlet::class)->roleFor(auth()->user())?->managesOutlet() ?? false;
    }

    #[Computed]
    public function range(): DateRange
    {
        $today = app(BusinessCalendar::class)->today($this->outlet);

        if (! $this->manages || $this->from === '' || $this->to === '') {
            return DateRange::of($today, $today);
        }

        try {
            return DateRange::of($this->from, $this->to);
        } catch (\Throwable) {
            return DateRange::of($today, $today);
        }
    }

    /**
     * @return array{status: string, cashier: string, method: string, search: string}
     */
    #[Computed]
    public function filters(): array
    {
        return ['status' => $this->status, 'cashier' => $this->cashier, 'method' => $this->method, 'search' => $this->search];
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return app(OrderSearch::class)->query($this->outlet, $this->range, $this->filters)->paginate(25);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function cashiers(): Collection
    {
        return $this->outlet->users()->orderBy('name')->get(['users.id', 'users.name']);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function methods(): Collection
    {
        return PaymentMethod::query()->orderBy('sort_order')->pluck('name');
    }

    public function exportUrl(string $format): string
    {
        return route('reports.export', array_filter([
            'type' => 'orders',
            'format' => $format,
            'from' => $this->range->fromDate(),
            'to' => $this->range->toDate(),
            'status' => $this->status === 'all' ? null : $this->status,
            'cashier' => $this->cashier,
            'method' => $this->method,
            'q' => $this->search,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'status', 'cashier', 'method', 'search'], true)) {
            $this->resetPage();
        }
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('orders.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('orders.subtitle', ['outlet' => $this->outlet->name]) }} &middot; {{ $this->range->label() }}
            </flux:text>
        </div>

        @if ($this->manages)
            <flux:dropdown position="bottom" align="end">
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down" data-test="orders-export">{{ __('reports.download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="table-cells" :href="$this->exportUrl('xlsx')" data-test="orders-export-xlsx">{{ __('reports.export_excel') }}</flux:menu.item>
                    <flux:menu.item icon="document-text" :href="$this->exportUrl('pdf')" target="_blank" data-test="orders-export-pdf">{{ __('reports.export_pdf') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('orders.search')" icon="magnifying-glass" class="max-w-56" data-test="orders-search" />

        @if ($this->manages)
            <flux:input type="date" wire:model.live="from" :label="__('reports.from')" class="max-w-44" data-test="orders-from" />
            <flux:input type="date" wire:model.live="to" :label="__('reports.to')" class="max-w-44" data-test="orders-to" />
        @endif

        <flux:select wire:model.live="status" :label="__('orders.status_label')" class="max-w-44" data-test="orders-status">
            <flux:select.option value="all">{{ __('orders.all_statuses') }}</flux:select.option>
            @foreach (['paid', 'open', 'voided'] as $value)
                <flux:select.option :value="$value">{{ __('orders.status.'.$value) }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->manages)
            <flux:select wire:model.live="cashier" :label="__('orders.cashier')" class="max-w-52">
                <flux:select.option value="">{{ __('orders.all_cashiers') }}</flux:select.option>
                @foreach ($this->cashiers as $person)
                    <flux:select.option :value="(string) $person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="method" :label="__('orders.methods')" class="max-w-52">
                <flux:select.option value="">{{ __('orders.all_methods') }}</flux:select.option>
                @foreach ($this->methods as $name)
                    <flux:select.option :value="$name">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @unless ($this->manages)
        <flux:text class="text-content-muted">{{ __('orders.cashier_hint') }}</flux:text>
    @endunless

    @if ($this->orders->isEmpty())
        <x-empty-state icon="receipt-percent" :heading="__('orders.empty_heading')" :description="__('orders.empty_description')" />
    @else
        <div class="overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="report-table" data-test="orders-table">
                <caption class="sr-only">{{ __('orders.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="text-start">{{ __('orders.number') }}</th>
                        <th scope="col">{{ __('orders.placed_at') }}</th>
                        <th scope="col" class="text-start">{{ __('orders.type_label') }}</th>
                        <th scope="col" class="text-start">{{ __('orders.cashier') }}</th>
                        <th scope="col" class="text-start">{{ __('orders.status_label') }}</th>
                        <th scope="col" class="text-start">{{ __('orders.methods') }}</th>
                        <th scope="col">{{ __('reports.total') }}</th>
                        <th scope="col"><span class="sr-only">{{ __('orders.view') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->orders as $order)
                        <tr wire:key="o-{{ $order->id }}">
                            <th scope="row" class="text-start font-normal">
                                <a href="{{ route('pos.receipt', $order) }}" wire:navigate class="underline decoration-line-strong underline-offset-4 hover:decoration-brand">{{ $order->number }}</a>
                            </th>
                            <td>{{ $order->placed_at?->format('d/m H:i') }}</td>
                            <td class="text-start">{{ $order->type->label() }}@if ($order->diningTable) &middot; {{ $order->diningTable->name }}@endif</td>
                            <td class="text-start">{{ $order->cashier?->name }}</td>
                            <td class="text-start">
                                <span @class([
                                    'dash-pill',
                                    'dash-delta-up' => $order->status === \App\Domain\Sales\Enums\OrderStatus::Paid,
                                    'dash-pill-pending' => $order->status->isEditable(),
                                    'dash-pill-danger' => $order->status === \App\Domain\Sales\Enums\OrderStatus::Voided,
                                ])>{{ $order->status->label() }}</span>
                                @if ((int) $order->refunded_total > 0)
                                    <span class="block text-sm text-content-muted">{{ __('reports.refunded') }} {{ \App\Support\Money::format((int) $order->refunded_total) }}</span>
                                @endif
                            </td>
                            <td class="text-start">{{ $order->payments->pluck('method_name')->unique()->implode(', ') ?: '-' }}</td>
                            <td><x-money :amount="(int) $order->grand_total" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="document-text" :href="route('orders.export', ['order' => $order, 'format' => 'pdf'])" target="_blank" :aria-label="__('orders.export_pdf', ['number' => $order->number])" :tooltip="__('reports.export_pdf')" class="min-h-11 min-w-11" data-test="order-pdf" />
                                    <flux:button size="sm" variant="ghost" icon="table-cells" :href="route('orders.export', ['order' => $order, 'format' => 'xlsx'])" :aria-label="__('orders.export_excel', ['number' => $order->number])" :tooltip="__('reports.export_excel')" class="min-h-11 min-w-11" data-test="order-xlsx" />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $this->orders->links() }}
    @endif
</div>
