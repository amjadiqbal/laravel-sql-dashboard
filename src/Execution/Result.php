<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution;

final class Result
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     * @param  'rows'|'bytes'|null  $truncated  why the result was cut off, if it was
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?string $truncated = null,
        public float $durationMs = 0.0,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** @return list<mixed> */
    public function column(int $index): array
    {
        return array_map(fn (array $row) => $row[$index] ?? null, $this->rows);
    }

    /** @return list<array<string, mixed>> rows keyed by column name (later duplicates win) */
    public function assoc(): array
    {
        return array_map(fn (array $row) => array_combine($this->columns, array_pad($row, count($this->columns), null)), $this->rows);
    }
}
