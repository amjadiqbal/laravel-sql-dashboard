<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution\Drivers;

use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use Illuminate\Database\Connection;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * SQLite: the query runs in bin/sqlite-reader.php, a separate PHP process
 * that opens the file read-only with query_only on, and is killed when the
 * timeout passes (SQLite has no statement timeout of its own).
 */
final class SqliteExecutor implements Executor
{
    public function run(Connection $connection, CompiledStatement $statement, Limits $limits): Result
    {
        $process = new Process([self::phpBinary(), dirname(__DIR__, 3).'/bin/sqlite-reader.php']);
        $process->setInput((string) json_encode([
            'database' => self::databasePath($connection),
            'sql' => $statement->sql,
            'bindings' => $statement->bindings,
            'max_rows' => $limits->maxRows,
            'max_bytes' => $limits->maxBytes,
        ]));
        $process->setTimeout($limits->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw QueryFailed::timeout($limits->timeout, $e);
        }

        $reply = json_decode($process->getOutput(), true);
        if (! is_array($reply)) {
            throw new QueryFailed('The SQLite reader process failed: '.trim($process->getErrorOutput() ?: $process->getOutput() ?: 'exit code '.$process->getExitCode()));
        }
        if (isset($reply['error'])) {
            throw ($reply['read_only'] ?? false) ? QueryFailed::readOnly() : new QueryFailed((string) $reply['error']);
        }

        /** @var list<string> $columns */
        $columns = $reply['columns'] ?? [];
        /** @var list<list<mixed>> $rows */
        $rows = $reply['rows'] ?? [];
        $truncated = in_array($reply['truncated'] ?? null, ['rows', 'bytes'], true) ? $reply['truncated'] : null;

        return new Result($columns, $rows, $truncated);
    }

    public static function databasePath(Connection $connection): string
    {
        $database = (string) $connection->getConfig('database');
        if ($database === '' || $database === ':memory:' || str_contains($database, 'mode=memory')) {
            throw new QueryRefused('An in-memory SQLite database cannot be read from another process. Point the dashboard connection at a database file.');
        }
        if (! is_file($database)) {
            throw new QueryRefused("The SQLite database file does not exist: {$database}");
        }

        return $database;
    }

    public static function phpBinary(): string
    {
        $configured = config('sql-dashboard.sqlite.php_binary');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true)) {
            return PHP_BINARY;
        }
        $sibling = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';

        return is_executable($sibling) ? $sibling : 'php';
    }
}
