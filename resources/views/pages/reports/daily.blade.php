{{--
| Daily net sales.
|
| Drawn with plain elements and CSS heights rather than a charting library:
| a dozen bars do not justify shipping a canvas renderer to a tablet. The
| figures stay in a table underneath the bars for screen readers, which also
| makes the chart readable when styles fail to load.
--}}
<section class="report-panel" aria-labelledby="report-daily-heading">
    <flux:heading id="report-daily-heading" size="lg">{{ __('reports.daily') }}</flux:heading>

    <div class="report-chart" role="presentation">
        @foreach ($this->daily as $day)
            <div
                class="report-bar"
                style="--bar: {{ (int) round($day->net * 100 / $this->peak) }}%"
                wire:key="bar-{{ $day->date }}"
            >
                <span class="report-bar-fill"></span>
            </div>
        @endforeach
    </div>

    <div class="relative overflow-x-auto">
        <table class="report-table">
            <caption class="sr-only">{{ __('reports.daily') }}</caption>

            <thead>
                <tr>
                    <th scope="col" class="text-start">{{ __('reports.date') }}</th>
                    <th scope="col" class="text-end">{{ __('reports.orders') }}</th>
                    <th scope="col" class="text-end">{{ __('reports.net_sales') }}</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($this->daily as $day)
                    <tr wire:key="day-{{ $day->date }}">
                        <th scope="row" class="text-start font-normal">
                            {{ \Carbon\CarbonImmutable::parse($day->date)->translatedFormat('D, d M') }}
                        </th>
                        <td class="numeric tabular-nums">{{ $day->orders }}</td>
                        <td><x-money :amount="$day->net" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
