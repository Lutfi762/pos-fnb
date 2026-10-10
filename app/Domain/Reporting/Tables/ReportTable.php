<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Tables;

use App\Support\Money;
use App\Support\Quantity;
use DateTimeInterface;

/**
 * A finished report: a title, its columns, its rows, and a totals line.
 *
 * Built once and drawn three ways: as a table on the reports page, as an
 * Excel sheet, and as a PDF. All three read the same rows, so a figure
 * never says one thing on screen and another in the file sent to the
 * accountant.
 */
final readonly class ReportTable
{
    /**
     * @param  list<Column>  $columns
     * @param  array<int, array<int, int|float|string|DateTimeInterface|null>>  $rows
     * @param  array<int, int|float|string|null>|null  $totals  same width as the columns, first cell the label
     * @param  array<string, string>  $meta  label to value, printed above the table
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public array $meta = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * A value as people read it: Rp12.500, 2,5, 12,5%, 10/03/2026.
     */
    public static function display(int|float|string|DateTimeInterface|null $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($type === Column::DATE ? 'd/m/Y' : 'd/m/Y H:i');
        }

        return match ($type) {
            Column::MONEY => Money::format((int) $value),
            Column::INTEGER => number_format((float) $value, 0, ',', '.'),
            Column::DECIMAL => Quantity::format((float) $value),
            Column::PERCENT => number_format((float) $value, 1, ',', '.').'%',
            default => (string) $value,
        };
    }
}
