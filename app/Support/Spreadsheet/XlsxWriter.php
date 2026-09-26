<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Writes .xlsx files for the app's exports, with no library behind it.
 *
 * An export here is data, not a printout: one header row, one record per
 * row, numbers stored as numbers and dates as dates, no merged cells. That is
 * what lets an accountant pivot it straight away, and it is also what keeps
 * this writer small. Formatting is done with number formats only (thousands
 * separators, dd/mm/yyyy), which Excel, LibreOffice and Google Sheets all
 * read the same way.
 */
final class XlsxWriter
{
    public const TEXT = 'text';

    public const INTEGER = 'integer';

    public const MONEY = 'money';

    public const DECIMAL = 'decimal';

    public const PERCENT = 'percent';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    /** Style index in styles.xml for each column type. */
    private const STYLES = [
        self::TEXT => 0,
        self::INTEGER => 2,
        self::MONEY => 2,
        self::DECIMAL => 3,
        self::PERCENT => 4,
        self::DATE => 5,
        self::DATETIME => 6,
    ];

    /** @var list<array{name: string, headers: list<string>, types: list<string>, rows: iterable<array<int, mixed>>}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $types  One of the class constants per column.
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function sheet(string $name, array $headers, array $types, iterable $rows): self
    {
        $this->sheets[] = [
            // Excel refuses sheet names over 31 characters or with []:*?/\
            'name' => mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name), 0, 31),
            'headers' => $headers,
            'types' => $types,
            'rows' => $rows,
        ];

        return $this;
    }

    public function save(string $path): void
    {
        if ($this->sheets === []) {
            throw new RuntimeException('An xlsx file needs at least one sheet.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet));
        }

        $zip->close();
    }

    public function toString(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            $this->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array{name: string, headers: list<string>, types: list<string>, rows: iterable<array<int, mixed>>}  $sheet
     */
    private function worksheet(array $sheet): string
    {
        $columns = count($sheet['headers']);
        $widths = array_map(fn (string $header): int => max(10, mb_strlen($header) + 2), $sheet['headers']);
        $rows = '';
        $rowNumber = 1;

        $cells = '';
        foreach ($sheet['headers'] as $column => $header) {
            $cells .= $this->stringCell($this->reference($column, 1), $header, 1);
        }
        $rows .= '<row r="1">'.$cells.'</row>';

        foreach ($sheet['rows'] as $values) {
            $rowNumber++;
            $cells = '';

            foreach (array_values($values) as $column => $value) {
                if ($column >= $columns) {
                    break;
                }

                $type = $sheet['types'][$column] ?? self::TEXT;
                $reference = $this->reference($column, $rowNumber);
                $cells .= $this->cell($reference, $value, $type);
                $widths[$column] = min(60, max($widths[$column], $this->displayWidth($value, $type)));
            }

            $rows .= '<row r="'.$rowNumber.'">'.$cells.'</row>';
        }

        $cols = '';
        foreach ($widths as $column => $width) {
            $cols .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        $last = $this->reference(max(0, $columns - 1), max(1, $rowNumber));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.$last.'"/>'
            // The header row stays on screen while scrolling.
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($cols === '' ? '' : '<cols>'.$cols.'</cols>')
            .'<sheetData>'.$rows.'</sheetData>'
            .($columns > 0 ? '<autoFilter ref="A1:'.$last.'"/>' : '')
            .'</worksheet>';
    }

    private function cell(string $reference, mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return '<c r="'.$reference.'" s="'.self::STYLES[$type === self::DATE ? self::DATE : self::DATETIME].'"><v>'.$this->serial($value).'</v></c>';
        }

        if ($type !== self::TEXT && is_numeric($value)) {
            $number = $type === self::PERCENT ? ((float) $value) / 100 : $value;

            return '<c r="'.$reference.'" s="'.self::STYLES[$type].'"><v>'.$this->number($number).'</v></c>';
        }

        if (is_bool($value)) {
            return '<c r="'.$reference.'" t="b"><v>'.($value ? 1 : 0).'</v></c>';
        }

        return $this->stringCell($reference, (string) $value, 0);
    }

    private function stringCell(string $reference, string $value, int $style): string
    {
        return '<c r="'.$reference.'" t="inlineStr"'.($style > 0 ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
    }

    private function number(int|float|string $value): string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
    }

    /**
     * Days since 1899-12-30, the epoch Excel counts dates from, using the
     * wall clock time of the date as given.
     */
    private function serial(DateTimeInterface $date): string
    {
        $utcWallTime = gmmktime(
            (int) $date->format('H'),
            (int) $date->format('i'),
            (int) $date->format('s'),
            (int) $date->format('n'),
            (int) $date->format('j'),
            (int) $date->format('Y'),
        );

        return $this->number(round($utcWallTime / 86400 + 25569, 6));
    }

    private function displayWidth(mixed $value, string $type): int
    {
        if ($value instanceof DateTimeInterface) {
            return $type === self::DATE ? 12 : 18;
        }

        if ($type !== self::TEXT && is_numeric($value)) {
            return strlen(number_format((float) $value, $type === self::DECIMAL ? 3 : 0)) + 2;
        }

        return mb_strlen((string) $value) + 2;
    }

    private function reference(int $column, int $row): string
    {
        $letters = '';
        $column++;

        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters.$row;
    }

    private function escape(string $value): string
    {
        // Control characters are not allowed in XML; they never belong in a
        // cell a person reads, so they are dropped rather than escaped.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->sheets) as $index) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        $names = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.$this->escape($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';

            $columns = count($sheet['headers']);
            if ($columns > 0) {
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$index.'" hidden="1">\''
                    .$this->escape(str_replace("'", "''", $sheet['name'])).'\'!$A$1:$'
                    .preg_replace('/\d+$/', '', $this->reference($columns - 1, 1)).'$1</definedName>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets>'
            .($names === '' ? '' : '<definedNames>'.$names.'</definedNames>')
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        $relations = '';
        foreach (array_keys($this->sheets) as $index) {
            $relations .= '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        $relations .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>';
    }

    /**
     * Seven cell styles: plain, header, whole numbers with thousands
     * separators, three decimals, percent, date, and date with time.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="#,##0.###"/>'
            .'<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
            .'<numFmt numFmtId="166" formatCode="dd/mm/yyyy hh:mm"/>'
            .'</numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE6F2EE"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="thin"><color rgb="FFB9C4BC"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
