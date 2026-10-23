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

    /** Functions with side effects: locks, files, other sessions, sequences, or running SQL from a string. */
    private const DENIED_FUNCTIONS = [
        // PostgreSQL
        'pg_terminate_backend', 'pg_cancel_backend', 'pg_reload_conf', 'pg_rotate_logfile', 'set_config',
        'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir', 'pg_stat_file', 'pg_file_write', 'pg_logdir_ls',
        'nextval', 'setval', 'pg_switch_wal', 'pg_create_restore_point', 'pg_promote', 'pg_notify',
        'query_to_xml', 'query_to_xmlschema', 'query_to_xml_and_xmlschema', 'cursor_to_xml', 'cursor_to_xmlschema',
        'pg_import_system_collations', 'pg_stat_reset', 'pg_stat_statements_reset',
        // MySQL / MariaDB
        'get_lock', 'release_lock', 'release_all_locks', 'load_file', 'sys_exec', 'sys_eval',
        'master_pos_wait', 'source_pos_wait',
        // SQLite
        'load_extension', 'readfile', 'writefile', 'edit', 'fts3_tokenizer',
    ];

    private const DENIED_FUNCTION_PREFIXES = ['pg_advisory', 'pg_try_advisory', 'lo_', 'dblink', 'pg_replication_', 'pg_drop_'];

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
