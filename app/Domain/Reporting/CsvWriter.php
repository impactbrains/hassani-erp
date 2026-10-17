<?php

namespace App\Domain\Reporting;

/**
 * Spreadsheet-safe CSV: UTF-8 with BOM (Urdu opens correctly in Excel),
 * RFC 4180 quoting, and formula-injection protection on text cells only.
 * Numbers stay numbers so totals can be summed after opening.
 */
final class CsvWriter
{
    private const NUMERIC = ['money', 'qty', 'int', 'decimal', 'percent'];

    /** @param resource $handle */
    public function __construct(private $handle)
    {
        fwrite($this->handle, "\xEF\xBB\xBF");
    }

    /** @param list<string> $values */
    public function header(array $values): void
    {
        $this->line(array_map(fn ($v) => self::safeText((string) $v), $values));
    }

    /** @param array<string, array{type: string}> $columns  @param array<string, mixed>|object $row */
    public function row(array $columns, array|object $row): void
    {
        $row = (array) $row;
        $out = [];
        foreach ($columns as $key => $column) {
            $value = $row[$key] ?? null;
            if ($value === null) {
                $out[] = '';
            } elseif (in_array($column['type'], self::NUMERIC, true) && is_numeric($value)) {
                $out[] = (string) $value;
            } elseif (is_bool($value)) {
                $out[] = $value ? 'Yes' : 'No';
            } else {
                $out[] = self::safeText((string) $value);
            }
        }
        $this->line($out);
    }

    /** Prefix cells a spreadsheet would run as a formula (=, +, -, @, tab, CR). */
    public static function safeText(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** @param list<string> $cells */
    private function line(array $cells): void
    {
        fwrite($this->handle, implode(',', array_map(fn ($c) => preg_match('/[",\r\n]/', $c) ? '"'.str_replace('"', '""', $c).'"' : $c, $cells))."\r\n");
    }
}
