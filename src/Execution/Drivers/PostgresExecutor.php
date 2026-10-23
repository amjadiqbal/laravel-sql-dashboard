<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution\Drivers;

use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Execution\RowCollector;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use Illuminate\Database\Connection;
use PDO;
use PDOException;
use Throwable;

/**
 * PostgreSQL: BEGIN READ ONLY (the server refuses any write, including from
 * functions), SET LOCAL statement_timeout and lock_timeout, and a server-side
 * cursor fetched in batches so an oversized result never reaches PHP memory.
 * DECLARE ... CURSOR FOR also only accepts a query, a second parser check.
 */
final class PostgresExecutor implements Executor
{
    use Binds;

    private const BATCH = 500;

    public function run(Connection $connection, CompiledStatement $statement, Limits $limits): Result
    {
        $pdo = $connection->getPdo();
        $ms = max(1, (int) round($limits->timeout * 1000));
        $deadline = microtime(true) + $limits->timeout;
        $collector = new RowCollector($limits);
        $columns = [];

        try {
            $pdo->exec('BEGIN TRANSACTION READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout = {$ms}");
            $pdo->exec("SET LOCAL lock_timeout = {$ms}");
            $pdo->exec('SET LOCAL standard_conforming_strings = on');

            $declare = $pdo->prepare('DECLARE sql_dashboard_cursor NO SCROLL CURSOR FOR '.$statement->sql);
            $this->bind($declare, $statement->bindings);
            $declare->execute();

            do {
                $fetch = $pdo->query('FETCH FORWARD '.self::BATCH.' FROM sql_dashboard_cursor');
                if ($fetch === false) {
                    break;
                }
                $columns = $columns ?: $this->columns($fetch);
                $got = 0;
                while (($row = $fetch->fetch(PDO::FETCH_NUM)) !== false) {
                    $got++;
                    if (! $collector->add($row)) {
                        break 2;
                    }
                }
                if (microtime(true) > $deadline && $got === self::BATCH) {
                    throw QueryFailed::timeout($limits->timeout);
                }
            } while ($got === self::BATCH);
        } catch (PDOException $e) {
            throw match ((string) ($e->errorInfo[0] ?? $e->getCode())) {
                '57014', '55P03' => QueryFailed::timeout($limits->timeout, $e),
                '25006' => QueryFailed::readOnly($e),
                default => new QueryFailed((string) preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $e->getMessage()), QueryFailed::ERROR, $e),
            };
        } finally {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // the transaction is gone either way
            }
        }

        return new Result($columns, $collector->rows, $collector->truncated);
    }
}
