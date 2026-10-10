<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $table->title }}</title>
    @include('exports._styles')
    @if ($landscape)
        <style>@page { size: A4 landscape; }</style>
    @endif
</head>
<body>
    @include('exports._printbar')

    <div class="page">
        <div class="brand">{{ config('app.name') }}</div>
        <h1>{{ $table->title }}</h1>

        <table class="meta">
            @foreach ($table->meta as $label => $value)
                <tr>
                    <td class="label">{{ $label }}</td>
                    <td>{{ $value }}</td>
                </tr>
            @endforeach
        </table>

        @if ($table->isEmpty())
            <p class="muted">{{ __('reports.empty_description') }}</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($table->columns as $column)
                            <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach ($table->columns as $index => $column)
                                <td @class(['num' => $column->isNumeric()])>{{ \App\Domain\Reporting\Tables\ReportTable::display($row[$index] ?? null, $column->type) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($table->totals !== null)
                    <tfoot>
                        <tr>
                            @foreach ($table->columns as $index => $column)
                                <td @class(['num' => $column->isNumeric()])>{{ \App\Domain\Reporting\Tables\ReportTable::display($table->totals[$index] ?? null, $index === 0 ? \App\Domain\Reporting\Tables\Column::TEXT : $column->type) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        @endif
    </div>
</body>
</html>
