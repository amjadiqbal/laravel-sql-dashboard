<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution;

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Sql\Dialect;
use AmjadIqbal\SqlDashboard\Support\PdoConstants;
use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * Refuses a connection that is able to write.
 *
 * MySQL / MariaDB: every privilege in SHOW GRANTS must be a read privilege
 * (SELECT, SHOW VIEW, USAGE...), or the server must be super_read_only.
 * PostgreSQL: the user must not be a superuser, must not be able to read or
 * write server files, run programs, signal other sessions, use dblink or a
 * foreign server, or execute another role's SECURITY DEFINER function, and (unless the server is a standby) must not own, or hold
 * INSERT / UPDATE / DELETE / TRUNCATE on, any table, view or sequence. SQLite: the connection must open the file read-only.
 *
 * Results are kept for a minute per connection, so a dashboard of ten
 * queries checks once.
 */
final class ConnectionVerifier
{
    private const READ_PRIVILEGES = [
        // Not PROCESS (other sessions' SQL), FILE (server files), SUPER or any write privilege.
        'USAGE', 'SELECT', 'SHOW VIEW', 'SHOW DATABASES', 'REPLICATION CLIENT',
        'BINLOG MONITOR', 'SLAVE MONITOR', 'REPLICA MONITOR',
    ];

    /** @var array<string, array{at: float, error: ?string}> */
    private array $verified = [];

    public function assertReadOnly(Connection $connection): void
    {
        $name = (string) $connection->getName();
        $cached = $this->verified[$name] ?? null;
        if ($cached === null || microtime(true) - $cached['at'] > 60) {
            $error = null;
            try {
                $this->verify($connection);
            } catch (QueryRefused $e) {
                $error = $e->getMessage();
            }
            $this->verified[$name] = $cached = ['at' => microtime(true), 'error' => $error];
        }
        if ($cached['error'] !== null) {
            throw new QueryRefused($cached['error']);
        }
    }

    public function forget(): void
    {
        $this->verified = [];
    }

    private function verify(Connection $connection): void
    {
        match (Dialect::fromDriver($connection->getDriverName())) {
            Dialect::MySql => $this->mysql($connection),
            Dialect::Postgres => $this->postgres($connection),
            Dialect::Sqlite => $this->sqlite($connection),
        };
    }

    private function mysql(Connection $connection): void
    {
        $pdo = $connection->getPdo();

        try {
            if ((int) $pdo->query('SELECT @@GLOBAL.super_read_only')?->fetchColumn() === 1) {
                return; // nobody can write to this server
            }
        } catch (Throwable) {
            // MariaDB has no super_read_only
        }

        $grants = $pdo->query('SHOW GRANTS')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $writes = [];
        foreach ($grants as $grant) {
            $grant = trim((string) $grant);
            if (stripos($grant, 'SET DEFAULT ROLE') === 0 || stripos($grant, 'REVOKE') === 0) {
                continue;
            }
            if (! preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is', $grant, $m)) {
                throw new QueryRefused("The '{$connection->getName()}' connection's database user has a role ({$this->short($grant)}). Grant SELECT directly to a user with no roles, so the dashboard can check that it cannot write.");
            }
            $privileges = preg_split('/\s*,\s*/', (string) preg_replace('/\([^)]*\)/', '', $m[1])) ?: [];
            foreach ($privileges as $privilege) {
                $privilege = strtoupper(trim((string) preg_replace('/\s+/', ' ', $privilege)));
                if ($privilege !== '' && ! in_array($privilege, self::READ_PRIVILEGES, true)) {
                    $writes[$privilege] = $privilege.' ON '.trim($m[2]);
                }
            }
        }

        if ($writes !== []) {
            throw new QueryRefused("The '{$connection->getName()}' connection can write or reach outside the database (".$this->short(implode('; ', $writes))."). Use a database user that only has SELECT: CREATE USER 'dashboard'@'%' IDENTIFIED BY '...'; GRANT SELECT, SHOW VIEW ON your_db.* TO 'dashboard'@'%';");
        }
    }

    private function postgres(Connection $connection): void
    {
        $pdo = $connection->getPdo();
        $role = $pdo->query('SELECT r.rolsuper, pg_is_in_recovery() AS standby FROM pg_roles r WHERE r.rolname = current_user')?->fetch(PDO::FETCH_ASSOC) ?: [];

        if (filter_var($role['rolsuper'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new QueryRefused("The '{$connection->getName()}' connection logs in as a superuser, which can write anything and read server files. Use a role that only has SELECT.");
        }

        // Powers that reach outside the database even in a read-only transaction (also on a standby).
        $power = $pdo->query(<<<'SQL'
            SELECT 'is a member of ' || r.rolname AS what FROM pg_roles r
            WHERE r.rolname IN ('pg_read_server_files', 'pg_write_server_files', 'pg_execute_server_program', 'pg_signal_backend')
              AND pg_has_role(current_user, r.oid, 'MEMBER')
            UNION ALL
            SELECT 'can execute ' || p.oid::regprocedure::text FROM pg_proc p
            WHERE (p.proname LIKE 'dblink%' OR p.proname IN ('lo_import', 'lo_export', 'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir'))
              AND has_function_privilege(p.oid, 'EXECUTE')
            UNION ALL
            -- A SECURITY DEFINER function runs with its owner's powers, which a read-only transaction does
            -- not limit outside this session (dblink, files): only functions the role itself owns are fine.
            SELECT 'can execute the SECURITY DEFINER function ' || p.oid::regprocedure::text FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.prosecdef AND n.nspname NOT IN ('pg_catalog', 'information_schema')
              AND NOT pg_has_role(current_user, p.proowner, 'USAGE')
              AND has_function_privilege(p.oid, 'EXECUTE')
            UNION ALL
            SELECT 'can use the foreign server ' || s.srvname FROM pg_foreign_server s
            WHERE has_server_privilege(s.oid, 'USAGE')
            LIMIT 1
            SQL)?->fetch(PDO::FETCH_ASSOC);
        if (is_array($power)) {
            throw new QueryRefused("The '{$connection->getName()}' connection's role {$power['what']}, which reaches outside the database (server files, programs, other sessions or another connection). Use a role with only SELECT: revoke that membership, or REVOKE EXECUTE on those functions FROM PUBLIC.");
        }

        if (filter_var($role['standby'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return; // a hot standby cannot be written to
        }

        $offender = $pdo->query(<<<'SQL'
            SELECT n.nspname || '.' || c.relname AS name,
                   CASE WHEN pg_has_role(current_user, c.relowner, 'USAGE') THEN 'owns' ELSE 'can write to' END AS why
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
              AND n.nspname NOT IN ('pg_catalog', 'information_schema')
              AND n.nspname NOT LIKE 'pg\_toast%' AND n.nspname NOT LIKE 'pg\_temp%'
              AND (
                pg_has_role(current_user, c.relowner, 'USAGE')
                OR (c.relkind <> 'S' AND (has_table_privilege(c.oid, 'INSERT') OR has_table_privilege(c.oid, 'UPDATE')
                    OR has_table_privilege(c.oid, 'DELETE') OR has_table_privilege(c.oid, 'TRUNCATE')))
                OR (c.relkind = 'S' AND has_sequence_privilege(c.oid, 'UPDATE'))
              )
            LIMIT 1
            SQL)?->fetch(PDO::FETCH_ASSOC);

        if (is_array($offender)) {
            throw new QueryRefused("The '{$connection->getName()}' connection can write: its role {$offender['why']} {$offender['name']}. Use a role that only has SELECT: CREATE ROLE dashboard LOGIN PASSWORD '...'; GRANT USAGE ON SCHEMA public TO dashboard; GRANT SELECT ON ALL TABLES IN SCHEMA public TO dashboard;");
        }
    }

    private function sqlite(Connection $connection): void
    {
        $options = (array) $connection->getConfig('options');
        $flags = (int) ($options[PdoConstants::sqliteOpenFlags()] ?? 0);

        if (($flags & PdoConstants::sqliteReadOnly()) === 0) {
            throw new QueryRefused("The '{$connection->getName()}' SQLite connection opens the file for writing. Add 'options' => [Pdo\\Sqlite::ATTR_OPEN_FLAGS => Pdo\\Sqlite::OPEN_READONLY] to it (PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY on PHP 8.3).");
        }
    }

    private function short(string $text): string
    {
        return strlen($text) > 160 ? substr($text, 0, 160).'…' : $text;
    }
}
