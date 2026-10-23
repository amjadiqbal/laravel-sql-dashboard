<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution\Drivers;

use PDO;
use PDOStatement;

/** @internal */
trait Binds
{
    /** @param list<mixed> $bindings */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $i => $value) {
            $statement->bindValue($i + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
    }

    /** @return list<string> */
    private function columns(PDOStatement $statement): array
    {
        $columns = [];
        for ($i = 0, $n = $statement->columnCount(); $i < $n; $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = is_array($meta) && isset($meta['name']) && $meta['name'] !== '' ? (string) $meta['name'] : 'column_'.($i + 1);
        }

        return $columns;
    }
}
