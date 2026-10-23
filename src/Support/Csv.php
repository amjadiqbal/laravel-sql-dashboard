<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Support;

use AmjadIqbal\SqlDashboard\Execution\Result;

final class Csv
{
    /**
     * Writes the result as CSV. A cell that a spreadsheet would read as a
     * formula (=, +, -, @, tab, carriage return) gets a leading apostrophe,
     * so opening an export can never run one (OWASP "CSV injection").
     *
     * @param  resource  $out
     */
    public static function write($out, Result $result): void
    {
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
        fputcsv($out, array_map(self::cell(...), $result->columns), ',', '"', '');
        foreach ($result->rows as $row) {
            fputcsv($out, array_map(self::cell(...), $row), ',', '"', '');
        }
    }

    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };

        if (is_string($value) && $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text)) {
            return "'".$text;
        }

        return $text;
    }
}
