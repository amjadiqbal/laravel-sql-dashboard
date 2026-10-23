<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution;

final readonly class Limits
{
    public function __construct(
        public float $timeout,
        public int $maxRows,
        public int $maxBytes,
    ) {}

    public static function fromConfig(bool $export = false): self
    {
        $rows = $export ? config('sql-dashboard.limits.export_max_rows', 10000) : config('sql-dashboard.limits.max_rows', 1000);

        return new self(
            timeout: max(0.1, (float) config('sql-dashboard.limits.timeout', 15)),
            maxRows: max(1, (int) $rows),
            maxBytes: max(1024, (int) config('sql-dashboard.limits.max_bytes', 16 * 1024 * 1024)),
        );
    }
}
