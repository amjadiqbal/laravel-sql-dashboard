<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;

/**
 * The statement allow-list. A statement passes only if it is one SELECT or
 * WITH query with no write, lock, file or session side effect anywhere in it.
 *
 * This is the first of three layers. The database then runs it in a
 * read-only transaction (or a read-only SQLite handle), on a connection
 * whose user has been checked to have no write privilege. Each layer stops
 * a write on its own; see the README's "Security" section.
 */
final class StatementGuard
{
    /** Words that only appear in a statement that writes, locks or changes the session. */
    private const DENIED_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'UPSERT', 'INTO', 'OUTFILE', 'DUMPFILE',
        'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE', 'COPY',
        'CALL', 'EXEC', 'EXECUTE', 'PREPARE', 'DEALLOCATE', 'HANDLER', 'LOCK', 'UNLOCK',
        'ATTACH', 'DETACH', 'PRAGMA', 'VACUUM', 'REINDEX', 'CLUSTER', 'REFRESH',
        'NOTIFY', 'LISTEN', 'UNLISTEN', 'CHECKPOINT', 'SAVEPOINT', 'COMMIT', 'ROLLBACK',
    ];

    /**
     * Functions with side effects: locks that outlive the transaction, files, other sessions, sequences,
     * other connections, or running SQL from a string. Matched however the name is written: bare,
     * "quoted", `quoted` or [quoted], schema-qualified or not, in any letter case.
     *
     * A deny-list, not an allow-list, on purpose: an allow-list of every safe built-in function on three
     * databases (plus each app's own functions and extensions) would refuse ordinary reports. The
     * connection check backs this up by refusing a PostgreSQL role that can read server files, run
     * programs, signal other sessions or use dblink, so a name missing here still cannot be used.
     */
    private const DENIED_FUNCTIONS = [
        // PostgreSQL
        'set_config', 'nextval', 'setval', 'pg_promote', 'pg_notify', 'pg_stat_file', 'pg_logdir_ls',
        'query_to_xml', 'query_to_xmlschema', 'query_to_xml_and_xmlschema', 'cursor_to_xml', 'cursor_to_xmlschema',
        'pg_import_system_collations', 'pg_stat_statements_reset', 'pg_log_backend_memory_contexts',
        'pg_backup_start', 'pg_backup_stop', 'pg_start_backup', 'pg_stop_backup', 'txid_current', 'pg_current_xact_id',
        // MySQL / MariaDB
        'get_lock', 'release_lock', 'release_all_locks', 'load_file', 'sys_exec', 'sys_eval', 'sys_get', 'sys_set',
        'master_pos_wait', 'source_pos_wait', 'wait_for_executed_gtid_set', 'wait_until_sql_thread_after_gtids',
        'master_gtid_wait',
        // SQLite
        'load_extension', 'readfile', 'writefile', 'edit', 'fts3_tokenizer',
    ];

    private const DENIED_FUNCTION_PREFIXES = [
        // PostgreSQL families: advisory locks, large objects (lo_import/lo_export read and write server files),
        // dblink (a second, writable connection), server files and directories, replication, backends.
        'pg_advisory', 'pg_try_advisory', 'lo_', 'dblink', 'pg_read_', 'pg_ls_', 'pg_file_', 'pg_replication_',
        'pg_logical_', 'pg_create_', 'pg_drop_', 'pg_copy_', 'pg_terminate_', 'pg_cancel_', 'pg_reload_',
        'pg_rotate_', 'pg_switch_', 'pg_wal_replay_', 'pg_stat_reset', 'postgres_fdw',
        // MySQL: lib_mysqludf_sys style UDFs
        'lib_mysqludf',
    ];

    /**
     * @param  array<string, mixed>  $values  variable name => value already cast to its type
     */
    public static function compile(string $sql, Dialect $dialect, array $values = []): CompiledStatement
    {
        $all = Lexer::tokenize($sql, $dialect);
        $tokens = array_values(array_filter($all, fn (Token $t) => $t->type !== TokenType::Comment));

        if ($tokens === []) {
            throw new QueryRefused('The statement is empty.');
        }

        // One statement: only semicolons (and comments) may follow the first semicolon.
        foreach ($tokens as $i => $token) {
            if ($token->type === TokenType::Semicolon) {
                foreach (array_slice($tokens, $i + 1) as $rest) {
                    if ($rest->type !== TokenType::Semicolon) {
                        throw new QueryRefused('Only one statement can run at a time: remove everything after the first semicolon.');
                    }
                }
                break;
            }
        }

        $first = null;
        foreach ($tokens as $token) {
            if (! $token->is(TokenType::Punctuation, '(')) {
                $first = $token;
                break;
            }
        }
        if ($first === null || ! ($first->is(TokenType::Word, 'SELECT') || $first->is(TokenType::Word, 'WITH'))) {
            throw new QueryRefused('Only SELECT and WITH queries can run here. Refused: '.self::preview($sql));
        }

        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;

            if ($token->type === TokenType::Placeholder) {
                throw new QueryRefused($dialect === Dialect::Postgres && str_starts_with($token->text, '?')
                    ? 'A bare ? is read as a parameter. Use {name} variables, and jsonb_exists() instead of the ? operator.'
                    : "Parameters like {$token->text} are not supported here: use {name} variables instead.");
            }

            if ($token->is(TokenType::Punctuation, ':=')) {
                throw new QueryRefused('Assignments (:=) are not allowed.');
            }

            // A "quoted" (or `quoted`, [quoted]) name followed by ( is a function call too: check it by its real name.
            if ($token->type === TokenType::QuotedIdentifier) {
                $name = strtolower($token->identifierName());
                if ($next !== null && $next->is(TokenType::Punctuation, '(') && self::deniedFunction($name)) {
                    throw new QueryRefused("{$name}() is not allowed: it has side effects outside this query.");
                }

                continue;
            }

            if ($token->type !== TokenType::Word) {
                continue;
            }

            $word = $token->upper();
            $isCall = $next !== null && $next->is(TokenType::Punctuation, '(');

            if ($word === 'REPLACE' && ! $isCall) {
                throw new QueryRefused('REPLACE is only allowed as the REPLACE() function: this dashboard never writes.');
            }

            if (in_array($word, self::DENIED_WORDS, true)) {
                throw new QueryRefused("{$word} is not allowed: this dashboard only reads. (If it is a column name, put it in quotes.)");
            }

            if ($word === 'FOR' && $next !== null && $next->type === TokenType::Word && in_array($next->upper(), ['SHARE', 'KEY', 'NO'], true)) {
                throw new QueryRefused('Locking reads (FOR SHARE, FOR UPDATE) are not allowed.');
            }

            if ($isCall && self::deniedFunction(strtolower($token->text))) {
                throw new QueryRefused(strtolower($token->text).'() is not allowed: it has side effects outside this query.');
            }
        }

        // Rebuild the SQL up to the first semicolon (only semicolons and comments
        // follow it), with each {variable} replaced by a bound ?.
        $end = strlen($sql);
        foreach ($all as $token) {
            if ($token->type === TokenType::Semicolon) {
                $end = $token->offset;
                break;
            }
        }
        $out = '';
        $bindings = [];
        $names = [];
        $cursor = 0;
        foreach ($all as $token) {
            if ($token->offset >= $end) {
                break;
            }
            if ($token->type === TokenType::Variable) {
                $name = $token->variableName();
                if (! array_key_exists($name, $values)) {
                    throw new QueryRefused("No value for the variable {{$name}}.");
                }
                $out .= substr($sql, $cursor, $token->offset - $cursor).'?';
                $cursor = $token->offset + strlen($token->text);
                $bindings[] = $values[$name];
                $names[] = $name;
            }
        }
        $out = rtrim($out.substr($sql, $cursor, $end - $cursor));

        return new CompiledStatement($out, $bindings, array_values(array_unique($names)));
    }

    /**
     * The {variables} a statement uses, in order of first use, and those that
     * sit inside quotes (where they are not replaced).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function variables(string $sql, Dialect $dialect): array
    {
        $used = [];
        $quoted = [];
        foreach (Lexer::tokenize($sql, $dialect) as $token) {
            if ($token->type === TokenType::Variable) {
                $used[] = $token->variableName();
            } elseif (in_array($token->type, [TokenType::String, TokenType::QuotedIdentifier], true)
                && preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $token->text, $m)) {
                array_push($quoted, ...$m[1]);
            }
        }

        return [array_values(array_unique($used)), array_values(array_unique($quoted))];
    }

    private static function deniedFunction(string $name): bool
    {
        if (in_array($name, self::DENIED_FUNCTIONS, true)) {
            return true;
        }
        foreach (self::DENIED_FUNCTION_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function preview(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($text) > 40 ? substr($text, 0, 40).'…' : $text;
    }
}
