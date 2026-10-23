<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Charts;

use AmjadIqbal\SqlDashboard\Execution\Result;

/**
 * A chart picked from the shape of a result, the way Blazer does it:
 *
 *   date, number[, number...]      line, one series per number column
 *   date, text, number             line, one series per text value
 *   text, number[, number...]      bar (pie when the last column is named "pie")
 *   text, text, number             bar, one series per second-column value
 *
 * A query can also ask for line, bar or pie; if the result has the wrong
 * shape for it, there is no chart and the page says why.
 */
final readonly class Chart
{
    public const MAX_POINTS = 500;

    public const MAX_SERIES = 8;

    /**
     * @param  'line'|'bar'|'pie'  $type
     * @param  list<string>  $labels
     * @param  list<array{name: string, values: list<float|null>}>  $series
     */
    public function __construct(
        public string $type,
        public array $labels,
        public array $series,
        public string $xTitle = '',
        public ?string $note = null,
    ) {}

    /** Null when the result has no chartable shape (or the requested type does not fit); $why says so. */
    public static function pick(Result $result, string $preferred = 'auto', ?string &$why = null): ?self
    {
        $why = null;
        if ($preferred === 'table') {
            return null;
        }
        $n = count($result->columns);
        if ($result->rowCount() === 0 || $n < 2) {
            $why = $preferred === 'auto' ? null : 'A chart needs at least two columns and one row.';

            return null;
        }

        $kinds = array_map(fn (int $i) => self::kind($result->column($i)), range(0, $n - 1));
        $first = $kinds[0];
        $restNumeric = array_slice($kinds, 1) === array_fill(0, $n - 1, 'number');
        $pivot = $n === 3 && $kinds[1] === 'text' && $kinds[2] === 'number';

        $chart = null;
        if ($first === 'date' && ($restNumeric || $pivot)) {
            $chart = $restNumeric ? self::wide($result, 'line') : self::pivoted($result, 'line');
        } elseif ($first !== 'date' && ($restNumeric || $pivot)) {
            $type = $preferred === 'pie' || ($preferred === 'auto' && $n === 2 && strtolower($result->columns[$n - 1]) === 'pie') ? 'pie' : 'bar';
            $chart = $restNumeric ? self::wide($result, $type) : self::pivoted($result, 'bar');
        }

        if ($chart === null) {
            $why = $preferred === 'auto' ? null : 'No chart: put a date or a label first, then number columns.';

            return null;
        }
        if ($preferred !== 'auto' && $preferred !== $chart->type && in_array($preferred, ['line', 'bar', 'pie'], true)) {
            $chart = new self($preferred, $chart->labels, $chart->series, $chart->xTitle, $chart->note);
        }
        if ($chart->type === 'pie') {
            if (count($chart->series) !== 1 || array_filter($chart->series[0]['values'], fn ($v) => $v !== null && $v < 0) !== []) {
                $why = 'A pie chart needs one column of numbers, none of them negative.';

                return null;
            }
        }

        return $chart;
    }

    /** @param list<mixed> $values */
    public static function kind(array $values): string
    {
        $seen = false;
        $kind = null;
        foreach ($values as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $seen = true;
            $k = match (true) {
                is_int($v), is_float($v) => 'number',
                is_bool($v) => 'text',
                is_string($v) && is_numeric($v) && ! preg_match('/^0\d/', $v) => 'number',
                is_string($v) && preg_match('/^\d{4}-\d{2}(-\d{2})?([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?([+-]\d{2}(:?\d{2})?|Z)?)?$/', $v) === 1 => 'date',
                default => 'text',
            };
            if ($kind === null) {
                $kind = $k;
            } elseif ($kind !== $k) {
                return 'text';
            }
        }

        return $seen ? (string) $kind : 'text';
    }

    private static function wide(Result $result, string $type): self
    {
        $rows = array_slice($result->rows, 0, self::MAX_POINTS);
        if ($type === 'line') {
            usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        }
        $labels = array_map(fn ($r) => self::label($r[0]), $rows);
        $series = [];
        foreach (array_slice($result->columns, 1, self::MAX_SERIES, true) as $i => $name) {
            $series[] = ['name' => $name, 'values' => array_map(fn ($r) => self::number($r[$i] ?? null), $rows)];
        }

        return new self($type, $labels, $series, $result->columns[0], self::note($result, count($result->columns) - 1));
    }

    private static function pivoted(Result $result, string $type): self
    {
        $rows = array_slice($result->rows, 0, self::MAX_POINTS * self::MAX_SERIES);
        $labels = [];
        $names = [];
        $cells = [];
        foreach ($rows as $r) {
            $x = self::label($r[0]);
            $s = self::label($r[1]);
            $labels[$x] = true;
            $names[$s] = true;
            $cells[$s][$x] = self::number($r[2]);
        }
        $labels = array_keys($labels);
        if ($type === 'line') {
            sort($labels);
        }
        $labels = array_slice(array_map('strval', $labels), 0, self::MAX_POINTS);
        $series = [];
        foreach (array_slice(array_keys($names), 0, self::MAX_SERIES) as $s) {
            $series[] = ['name' => (string) $s, 'values' => array_map(fn ($x) => $cells[$s][$x] ?? null, $labels)];
        }

        return new self($type, $labels, $series, $result->columns[0], self::note($result, count($names)));
    }

    private static function note(Result $result, int $series): ?string
    {
        $notes = [];
        if ($result->rowCount() > self::MAX_POINTS) {
            $notes[] = 'the first '.self::MAX_POINTS.' points';
        }
        if ($series > self::MAX_SERIES) {
            $notes[] = 'the first '.self::MAX_SERIES.' series';
        }

        return $notes === [] ? null : 'Chart shows '.implode(' and ', $notes).'.';
    }

    private static function label(mixed $v): string
    {
        return $v === null ? '(empty)' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }

    private static function number(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
