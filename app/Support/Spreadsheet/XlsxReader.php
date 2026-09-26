<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the cell values of an .xlsx sheet, for imports.
 *
 * Only values, never formulas or formatting: an import wants what the cell
 * shows. Numbers come back as int or float, text as string, empty cells as
 * null, so a price typed as 25000 and one typed as "25.000" both arrive and
 * the importer decides what they mean.
 */
final class XlsxReader
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Rows of the first sheet (or the named one), each a zero-indexed list.
     *
     * @return list<list<int|float|string|bool|null>>
     */
    public function rows(string $path, ?string $sheet = null): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('catalog.import.not_xlsx'));
        }

        try {
            $strings = $this->sharedStrings($zip);
            $xml = $this->load($zip, $this->sheetPath($zip, $sheet));

            return $this->read($xml, $strings);
        } finally {
            $zip->close();
        }
    }

    private function load(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $contents = $zip->getFromName($name);

        if ($contents === false) {
            throw new RuntimeException(__('catalog.import.not_xlsx'));
        }

        // No network, no entity expansion: the file came from a user.
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            throw new RuntimeException(__('catalog.import.not_xlsx'));
        }

        return $xml;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $xml = $this->load($zip, 'xl/sharedStrings.xml');
        $strings = [];

        foreach ($xml->children(self::MAIN)->si as $item) {
            $strings[] = $this->text($item);
        }

        return $strings;
    }

    private function sheetPath(ZipArchive $zip, ?string $wanted): string
    {
        $workbook = $this->load($zip, 'xl/workbook.xml');
        $relations = $this->load($zip, 'xl/_rels/workbook.xml.rels');

        $targets = [];
        foreach ($relations->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relation) {
            $targets[$this->attribute($relation, 'Id')] = $this->attribute($relation, 'Target');
        }

        foreach ($workbook->children(self::MAIN)->sheets->sheet as $sheet) {
            $name = $this->attribute($sheet, 'name');

            if ($wanted !== null && mb_strtolower($name) !== mb_strtolower($wanted)) {
                continue;
            }

            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $targets[$id] ?? null;

            if ($target !== null) {
                return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
        }

        throw new RuntimeException(__('catalog.import.not_xlsx'));
    }

    /**
     * @param  list<string>  $strings
     * @return list<list<int|float|string|bool|null>>
     */
    private function read(SimpleXMLElement $xml, array $strings): array
    {
        $rows = [];

        foreach ($xml->children(self::MAIN)->sheetData->row as $row) {
            $number = (int) $this->attribute($row, 'r');
            $values = [];

            foreach ($row->children(self::MAIN)->c as $cell) {
                $values[$this->column($this->attribute($cell, 'r'))] = $this->value($cell, $strings);
            }

            if ($values === []) {
                continue;
            }

            $width = max(array_keys($values)) + 1;
            $line = array_fill(0, $width, null);

            foreach ($values as $column => $value) {
                $line[$column] = $value;
            }

            $rows[$number > 0 ? $number : count($rows) + 1] = $line;
        }

        ksort($rows);

        // Keep the spreadsheet's own row numbers, so an error can say "row 7".
        return array_values(array_map(fn (array $row): array => $row, $this->fillGaps($rows)));
    }

    /**
     * @param  array<int, list<mixed>>  $rows
     * @return list<list<mixed>>
     */
    private function fillGaps(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $filled = [];
        $last = max(array_keys($rows));

        for ($number = 1; $number <= $last; $number++) {
            $filled[] = $rows[$number] ?? [];
        }

        return $filled;
    }

    /**
     * @param  list<string>  $strings
     */
    private function value(SimpleXMLElement $cell, array $strings): int|float|string|bool|null
    {
        $type = $this->attribute($cell, 't');
        $children = $cell->children(self::MAIN);

        if ($type === 'inlineStr') {
            return $this->clean($this->text($children->is));
        }

        $raw = isset($children->v) ? (string) $children->v : null;

        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($type) {
            's' => $this->clean($strings[(int) $raw] ?? ''),
            'b' => $raw === '1',
            'str', 'e' => $this->clean($raw),
            default => is_numeric($raw)
                ? (preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : (float) $raw)
                : $this->clean($raw),
        };
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Text of a string item, including rich text split into runs.
     */
    private function text(?SimpleXMLElement $item): string
    {
        if ($item === null) {
            return '';
        }

        $children = $item->children(self::MAIN);

        if (isset($children->t)) {
            return (string) $children->t;
        }

        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN)->t;
        }

        return $text;
    }

    /**
     * An attribute without a namespace prefix. Reading it as $element['name']
     * finds nothing once the element was reached through children($namespace).
     */
    private function attribute(SimpleXMLElement $element, string $name): string
    {
        return (string) ($element->attributes()[$name] ?? '');
    }

    private function column(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($reference)) ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
