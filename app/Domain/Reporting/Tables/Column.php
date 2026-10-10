<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Tables;

use App\Support\Spreadsheet\XlsxWriter;

/**
 * One column of a report: its heading and what kind of value it holds. The
 * kind decides how the value is printed on screen, in a PDF, and which
 * number format it gets in Excel.
 */
final readonly class Column
{
    public const TEXT = XlsxWriter::TEXT;

    public const INTEGER = XlsxWriter::INTEGER;

    public const MONEY = XlsxWriter::MONEY;

    public const DECIMAL = XlsxWriter::DECIMAL;

    public const PERCENT = XlsxWriter::PERCENT;

    public const DATE = XlsxWriter::DATE;

    public const DATETIME = XlsxWriter::DATETIME;

    public function __construct(
        public string $label,
        public string $type = self::TEXT,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, [self::INTEGER, self::MONEY, self::DECIMAL, self::PERCENT], true);
    }
}
