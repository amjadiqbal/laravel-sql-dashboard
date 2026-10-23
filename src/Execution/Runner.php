<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\Drivers\Executor;
use AmjadIqbal\SqlDashboard\Execution\Drivers\MysqlExecutor;
use AmjadIqbal\SqlDashboard\Execution\Drivers\PostgresExecutor;
use AmjadIqbal\SqlDashboard\Execution\Drivers\SqliteExecutor;
use AmjadIqbal\SqlDashboard\Models\Audit;
use AmjadIqbal\SqlDashboard\Sql\Dialect;
use AmjadIqbal\SqlDashboard\Sql\StatementGuard;
use AmjadIqbal\SqlDashboard\Sql\Variables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The only way SQL reaches the database. Every call, allowed or refused, is
 * written to the audit log.
 *
 *   1. the statement allow-list (StatementGuard), with variables bound as parameters;
 *   2. the connection check (ConnectionVerifier): a connection that can write is refused;
 *   3. the driver's executor: read-only transaction, timeout, row limit.
 */
final class Runner
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ConnectionVerifier $verifier,
    ) {}

    /**
     * @param  array<string, array{type: string, label: string, default: ?string, options: list<string>}>  $definitions
     * @param  array<string, mixed>  $input  raw variable values (strings from a form)
     */
    public function run(string $sql, array $definitions, array $input, Actor $actor, string $source, ?int $queryId = null, ?Limits $limits = null): Result
    {
        $limits ??= Limits::fromConfig();
        $started = microtime(true);
        $values = [];
        $connectionName = $this->connectionName();

        try {
            $connection = $this->connection();
            $dialect = Dialect::fromDriver($connection->getDriverName());
            $missing = [];
            $values = Variables::resolve($definitions, $input, $missing);
            if ($missing !== []) {
                throw new QueryRefused('Give a value for '.implode(', ', array_map(fn ($n) => '{'.$n.'}', $missing)).'.');
            }
            $statement = StatementGuard::compile($sql, $dialect, $values);
            $this->verifier->assertReadOnly($connection);

            $result = $this->executor($dialect)->run($connection, $statement, $limits);
            $result->durationMs = (microtime(true) - $started) * 1000;
            $this->audit($actor, $source, $queryId, $connectionName, $sql, $values, 'ok', $result->rowCount(), $started,
                $result->truncated ? 'Cut off at the '.($result->truncated === 'rows' ? 'row' : 'size').' limit.' : null);

            return $result;
        } catch (QueryRefused $e) {
            $this->audit($actor, $source, $queryId, $connectionName, $sql, $values, 'refused', null, $started, $e->getMessage());
            throw $e;
        } catch (QueryFailed $e) {
            $this->audit($actor, $source, $queryId, $connectionName, $sql, $values, $e->kind === QueryFailed::TIMEOUT ? 'timeout' : 'failed', null, $started, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->audit($actor, $source, $queryId, $connectionName, $sql, $values, 'failed', null, $started, $e->getMessage());
            throw new QueryFailed($e->getMessage(), QueryFailed::ERROR, $e);
        }
    }

    public function connectionName(): ?string
    {
        $name = config('sql-dashboard.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function connection(): Connection
    {
        $name = $this->connectionName();
        if ($name === null) {
            throw new QueryRefused('No read-only connection is set up. Add a database connection whose user can only read, then set SQL_DASHBOARD_CONNECTION to its name.');
        }
        if ($name === (config('sql-dashboard.storage_connection') ?: config('database.default'))) {
            throw new QueryRefused("SQL_DASHBOARD_CONNECTION points at '{$name}', the connection the dashboard stores its own data on. Use a separate read-only connection.");
        }

        try {
            $connection = $this->db->connection($name);
        } catch (InvalidArgumentException $e) {
            throw new QueryRefused("There is no database connection named '{$name}' in config/database.php.");
        }
        if (! $connection instanceof Connection) {
            throw new QueryRefused("The '{$name}' connection is not a SQL connection.");
        }

        return $connection;
    }

    public function dialect(): ?Dialect
    {
        try {
            return Dialect::fromDriver($this->connection()->getDriverName());
        } catch (Throwable) {
            return null;
        }
    }

    private function executor(Dialect $dialect): Executor
    {
        return match ($dialect) {
            Dialect::MySql => new MysqlExecutor,
            Dialect::Postgres => new PostgresExecutor,
            Dialect::Sqlite => new SqliteExecutor,
        };
    }

    /** @param array<string, mixed> $values */
    private function audit(Actor $actor, string $source, ?int $queryId, ?string $connection, string $sql, array $values, string $status, ?int $rows, float $started, ?string $message): void
    {
        try {
            Audit::query()->create([
                'user_id' => $actor->id,
                'user_label' => mb_substr($actor->label, 0, 250),
                'query_id' => $queryId,
                'source' => $source,
                'connection' => $connection,
                'statement' => $sql,
                'variables' => $values === [] ? null : $values,
                'status' => $status,
                'row_count' => $rows,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'message' => $message === null ? null : mb_substr($message, 0, 2000),
                'ip' => $actor->ip,
            ]);
        } catch (Throwable $e) {
            // Never lose the run because the log failed, but say so where someone will look.
            Log::warning('laravel-sql-dashboard could not write to its audit log: '.$e->getMessage());
        }
    }
}
