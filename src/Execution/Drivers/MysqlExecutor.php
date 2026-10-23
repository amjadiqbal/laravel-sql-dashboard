<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution\Drivers;

use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Execution\RowCollector;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use AmjadIqbal\SqlDashboard\Support\PdoConstants;
use Illuminate\Database\Connection;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * MySQL and MariaDB: START TRANSACTION READ ONLY (the server refuses any
 * write to a table), a per-session statement timeout (max_execution_time on
 * MySQL, max_statement_time on MariaDB), sql_select_limit plus an unbuffered
 * fetch so an oversized result never reaches PHP memory.
 */
final class MysqlExecutor implements Executor
{
    use Binds;

    public function run(Connection $connection, CompiledStatement $statement, Limits $limits): Result
    {
        $pdo = $connection->getPdo();
        $mariaDb = self::isMariaDb($pdo);
        $buffered = PdoConstants::mysqlBufferedQuery();
        $wasBuffered = $pdo->getAttribute($buffered);

        // The lexer reads backslashes as escapes in strings; make the server agree.
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
        $cleanMode = implode(',', array_filter(explode(',', $mode), fn ($m) => $m !== '' && strtoupper($m) !== 'NO_BACKSLASH_ESCAPES'));
        if ($cleanMode !== $mode) {
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$cleanMode]);
        }

        $mariaDb
            ? $pdo->exec('SET SESSION max_statement_time = '.sprintf('%.3F', $limits->timeout))
            : $pdo->exec('SET SESSION max_execution_time = '.max(1, (int) round($limits->timeout * 1000)));
        $pdo->exec('SET SESSION sql_select_limit = '.($limits->maxRows + 1));
        $pdo->setAttribute($buffered, false);

        $stmt = null;
        $columns = [];
        $collector = new RowCollector($limits);
        try {
            $pdo->exec('START TRANSACTION READ ONLY');
            $stmt = $pdo->prepare($statement->sql);
            $this->bind($stmt, $statement->bindings);
            $stmt->execute();
            $columns = $this->columns($stmt);
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                if (! $collector->add($row)) {
                    break;
                }
            }
        } catch (PDOException $e) {
            throw self::translate($e, $limits);
        } finally {
            $this->cleanUp($pdo, $stmt, $buffered, $wasBuffered, $mariaDb, $cleanMode !== $mode ? $mode : null);
        }

        return new Result($columns, $collector->rows, $collector->truncated);
    }

    public static function isMariaDb(PDO $pdo): bool
    {
        return stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    }

    private function cleanUp(PDO $pdo, ?PDOStatement $stmt, int $buffered, mixed $wasBuffered, bool $mariaDb, ?string $mode): void
    {
        foreach ([
            fn () => $stmt?->closeCursor(),
            fn () => $pdo->exec('ROLLBACK'),
            // GET_LOCK() locks survive ROLLBACK; drop any this session holds (MySQL 5.7+, MariaDB 10.5+).
            fn () => $pdo->query('SELECT RELEASE_ALL_LOCKS()')?->closeCursor(),
            fn () => $pdo->setAttribute($buffered, $wasBuffered),
            fn () => $pdo->exec('SET SESSION sql_select_limit = DEFAULT'),
            fn () => $pdo->exec($mariaDb ? 'SET SESSION max_statement_time = DEFAULT' : 'SET SESSION max_execution_time = DEFAULT'),
            fn () => $mode === null ?: $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]),
        ] as $step) {
            try {
                $step();
            } catch (Throwable) {
                // keep going: each step is independent
            }
        }
    }

    private static function translate(PDOException $e, Limits $limits): QueryFailed
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return match ($code) {
            3024, 1969 => QueryFailed::timeout($limits->timeout, $e),
            1792 => QueryFailed::readOnly($e),
            default => new QueryFailed(self::message($e), QueryFailed::ERROR, $e),
        };
    }

    private static function message(PDOException $e): string
    {
        return (string) ($e->errorInfo[2] ?? $e->getMessage());
    }
}
