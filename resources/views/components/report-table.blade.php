@props(['table'])

{{--
| Any ReportTable as an accessible table: the same rows the Excel and PDF
| files are made from. Numbers right-aligned in tabular figures, a real
| caption for screen readers, totals in the footer.
--}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-line bg-surface']) }}>
    <table class="report-table">
        <caption class="sr-only">{{ $table->title }}</caption>

        <thead>
            <tr>
                @foreach ($table->columns as $column)
                    <th scope="col" @class(['text-start' => ! $column->isNumeric()])>{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($table->rows as $row)
                <tr wire:key="rt-{{ $loop->index }}">
                    @foreach ($table->columns as $index => $column)
                        @php($text = \App\Domain\Reporting\Tables\ReportTable::display($row[$index] ?? null, $column->type))
                        @if ($index === 0)
                            <th scope="row" @class(['font-normal', 'text-start' => ! $column->isNumeric()])>{{ $text }}</th>
                        @else
                            <td @class(['numeric' => $column->isNumeric(), 'text-start' => ! $column->isNumeric(), 'text-danger-text' => $column->type === \App\Domain\Reporting\Tables\Column::MONEY && is_numeric($row[$index] ?? null) && $row[$index] < 0])>{{ $text }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>

        @if ($table->totals !== null)
            <tfoot>
                <tr class="border-t border-line-strong font-semibold">
                    @foreach ($table->columns as $index => $column)
                        @php($text = \App\Domain\Reporting\Tables\ReportTable::display($table->totals[$index] ?? null, $index === 0 ? \App\Domain\Reporting\Tables\Column::TEXT : $column->type))
                        @if ($index === 0)
                            <th scope="row" class="text-start">{{ $text }}</th>
                        @else
                            <td @class(['numeric' => $column->isNumeric(), 'text-start' => ! $column->isNumeric()])>{{ $text }}</td>
                        @endif
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</div>
