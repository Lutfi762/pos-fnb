{{--
| The three lists a manager checks after service: what sold, how it was paid
| for, and whether the drawer matched.
--}}
<div class="report-columns">
    <section class="report-panel" aria-labelledby="report-top-heading">
        <flux:heading id="report-top-heading" size="lg">{{ __('reports.top_products') }}</flux:heading>

        <div class="relative overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th scope="col" class="text-start">{{ __('reports.product') }}</th>
                        <th scope="col" class="text-end">{{ __('reports.sold') }}</th>
                        <th scope="col" class="text-end">{{ __('reports.net_sales') }}</th>
                        @if ($this->canSeeMargin)
                            <th scope="col" class="text-end">{{ __('reports.gross_profit') }}</th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @foreach ($this->topProducts as $row)
                        <tr wire:key="top-{{ $loop->index }}">
                            <th scope="row" class="text-start font-normal">{{ $row->name }}</th>
                            <td class="numeric tabular-nums">{{ rtrim(rtrim(number_format($row->quantity, 2, ',', '.'), '0'), ',') }}</td>
                            <td><x-money :amount="$row->net" /></td>
                            @if ($this->canSeeMargin)
                                <td><x-money :amount="$row->net - $row->cost" /></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="report-panel" aria-labelledby="report-methods-heading">
        <flux:heading id="report-methods-heading" size="lg">{{ __('reports.payment_methods') }}</flux:heading>

        <div class="relative overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th scope="col" class="text-start">{{ __('reports.method') }}</th>
                        <th scope="col" class="text-end">{{ __('reports.tenders') }}</th>
                        <th scope="col" class="text-end">{{ __('reports.total') }}</th>
                        <th scope="col" class="text-end">{{ __('reports.fee') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($this->methods as $row)
                        <tr wire:key="method-{{ $loop->index }}">
                            <th scope="row" class="text-start font-normal">{{ $row->name }}</th>
                            <td class="numeric tabular-nums">{{ $row->count }}</td>
                            <td><x-money :amount="$row->total" /></td>
                            <td><x-money :amount="$row->fee" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>

@if ($this->canSeeMargin && $this->shifts->isNotEmpty())
    <section class="report-panel" aria-labelledby="report-shifts-heading">
        <flux:heading id="report-shifts-heading" size="lg">{{ __('reports.shifts') }}</flux:heading>

        <div class="relative overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th scope="col" class="text-start">{{ __('shifts.cashier') }}</th>
                        <th scope="col" class="text-start">{{ __('shifts.closed_at') }}</th>
                        <th scope="col" class="text-end">{{ __('shifts.expected_cash') }}</th>
                        <th scope="col" class="text-end">{{ __('shifts.counted_cash') }}</th>
                        <th scope="col" class="text-end">{{ __('shifts.difference') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($this->shifts as $shift)
                        <tr wire:key="shift-{{ $shift->id }}">
                            <th scope="row" class="text-start font-normal">{{ $shift->cashier->name }}</th>
                            <td>{{ $shift->closed_at?->translatedFormat('d M, H:i') }}</td>
                            <td><x-money :amount="$shift->expected_cash" /></td>
                            <td><x-money :amount="$shift->closing_cash" /></td>

                            {{-- x-money already marks a negative figure, which is
                                 exactly the short drawer worth noticing. --}}
                            <td><x-money :amount="$shift->cash_difference" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
