<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;

/**
 * Splits SQL into tokens the way the target database reads it.
 *
 * The point is to know, for every character, whether it is code or sits
 * inside a string, a quoted identifier or a comment, so the guard can see
 * a semicolon or a DELETE that is really there and ignore one inside quotes.
 * Where the database could read the text two ways, it refuses rather than
 * guess: an unterminated string or comment, a MySQL executable comment
 * (/*! ... *\/ runs as SQL), or a MySQL double-quoted value with a backslash
 * (its meaning depends on the ANSI_QUOTES mode).
 *
 * The runner pins the modes this relies on: MySQL without
 * NO_BACKSLASH_ESCAPES, PostgreSQL with standard_conforming_strings on.
 */
final class Lexer
{
    private string $sql;

    private int $length;

    private int $pos = 0;

    /** @var list<Token> */
    private array $tokens = [];

    private function __construct(private readonly Dialect $dialect) {}

    /** @return list<Token> */
    public static function tokenize(string $sql, Dialect $dialect): array
    {
        $lexer = new self($dialect);
        $lexer->sql = $sql;
        $lexer->length = strlen($sql);
        $lexer->run();

        return $lexer->tokens;
    }

    private function run(): void
    {
        while ($this->pos < $this->length) {
            $c = $this->sql[$this->pos];
            $next = $this->sql[$this->pos + 1] ?? '';
            $start = $this->pos;

            if (ctype_space($c)) {
                $this->pos++;

                continue;
            }

            if ($c === '-' && $next === '-' && $this->dashDashIsComment()) {
                $this->lineComment($start);
            } elseif ($c === '#' && $this->dialect === Dialect::MySql) {
                $this->lineComment($start);
            } elseif ($c === '/' && $next === '*') {
                $this->blockComment($start);
            } elseif ($c === "'") {
                $this->quoted($start, "'", TokenType::String, $this->dialect === Dialect::MySql);
            } elseif ($c === '"') {
                $this->doubleQuoted($start);
            } elseif ($c === '`' && $this->dialect !== Dialect::Postgres) {
                $this->quoted($start, '`', TokenType::QuotedIdentifier, false);
            } elseif ($c === '[' && $this->dialect === Dialect::Sqlite) {
                $this->bracketIdentifier($start);
            } elseif ($c === '$' && $this->dialect === Dialect::Postgres && $this->dollar($start)) {
                // dollar-quoted string or $1 parameter, handled
            } elseif ($c === '{' && preg_match('/\G\{([A-Za-z_][A-Za-z0-9_]*)\}/', $this->sql, $m, 0, $this->pos)) {
                $this->pos += strlen($m[0]);
                $this->push(TokenType::Variable, $start);
            } elseif ($c === '?') {
                $this->pos++;
                $this->push(TokenType::Placeholder, $start);
            } elseif ($this->dialect === Dialect::Sqlite && ($c === ':' || $c === '@' || $c === '$') && preg_match('/\G[:@$][A-Za-z_]/', $this->sql, $m, 0, $this->pos)) {
                // SQLite named parameters (:name, @name, $name).
                $this->pos++;
                $this->word();
                $this->push(TokenType::Placeholder, $start);
            } elseif ($c === ';') {
                $this->pos++;
                $this->push(TokenType::Semicolon, $start);
            } elseif (ctype_digit($c) || ($c === '.' && ctype_digit($next))) {
                $this->pos++;
                while ($this->pos < $this->length && (ctype_alnum($this->sql[$this->pos]) || $this->sql[$this->pos] === '.' || $this->sql[$this->pos] === '_')) {
                    $this->pos++;
                }
                $this->push(TokenType::Number, $start);
            } elseif (ctype_alpha($c) || $c === '_' || ord($c) >= 0x80 || ($c === '$' && $this->dialect === Dialect::MySql)) {
                $this->word();
                if ($this->pos - $start === 1 && ($c === 'e' || $c === 'E') && ($this->sql[$this->pos] ?? '') === "'" && $this->dialect === Dialect::Postgres) {
                    // E'...' escape string: backslashes escape.
                    $this->quoted($this->pos, "'", TokenType::String, true, $start);
                } else {
                    $this->push(TokenType::Word, $start);
                }
            } elseif (($c === ':' && ($next === '=' || $next === ':')) || ($c === '|' && $next === '|')) {
                $this->pos += 2;
                $this->push(TokenType::Punctuation, $start);
            } else {
                $this->pos++;
                $this->push(TokenType::Punctuation, $start);
            }
        }
    }

    private function push(TokenType $type, int $start): void
    {
        $this->tokens[] = new Token($type, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function word(): void
    {
        while ($this->pos < $this->length) {
            $c = $this->sql[$this->pos];
            if (! (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80)) {
                break;
            }
            $this->pos++;
        }
    }

    /** MySQL only treats "--" as a comment when whitespace or the end follows it: "1--1" is 1 - -1. */
    private function dashDashIsComment(): bool
    {
        if ($this->dialect !== Dialect::MySql) {
            return true;
        }
        $after = $this->sql[$this->pos + 2] ?? '';

        return $after === '' || ctype_space($after) || ctype_cntrl($after);
    }

    private function lineComment(int $start): void
    {
        $end = strpos($this->sql, "\n", $this->pos);
        $this->pos = $end === false ? $this->length : $end + 1;
        $this->push(TokenType::Comment, $start);
    }

    private function blockComment(int $start): void
    {
        if ($this->dialect === Dialect::MySql && preg_match('/\G\/\*(M?!)/', $this->sql, $m, 0, $this->pos)) {
            throw new QueryRefused('MySQL executable comments (/*! ... */) are not allowed: the server runs what is inside them.');
        }

        $depth = 0;
        while ($this->pos < $this->length) {
            if (substr($this->sql, $this->pos, 2) === '/*') {
                $depth++;
                $this->pos += 2;
                if ($this->dialect !== Dialect::Postgres && $depth > 1) {
                    $depth = 1; // only PostgreSQL nests block comments
                }

                continue;
            }
            if (substr($this->sql, $this->pos, 2) === '*/') {
                $depth--;
                $this->pos += 2;
                if ($depth === 0) {
                    $this->push(TokenType::Comment, $start);

                    return;
                }

                continue;
            }
            $this->pos++;
        }

        throw new QueryRefused('The statement has a comment that never ends (/* without */).');
    }

    /** A quoted run where the quote is escaped by doubling it and, if $backslash, by a backslash. */
    private function quoted(int $quoteAt, string $quote, TokenType $type, bool $backslash, ?int $tokenStart = null): void
    {
        $this->pos = $quoteAt + 1;
        while ($this->pos < $this->length) {
            $c = $this->sql[$this->pos];
            if ($backslash && $c === '\\') {
                $this->pos += 2;

                continue;
            }
            if ($c === $quote) {
                if (($this->sql[$this->pos + 1] ?? '') === $quote) {
                    $this->pos += 2;

                    continue;
                }
                $this->pos++;
                $this->push($type, $tokenStart ?? $quoteAt);

                return;
            }
            $this->pos++;
        }

        throw new QueryRefused($type === TokenType::String
            ? 'The statement has a string that never ends (a missing closing quote).'
            : 'The statement has a quoted name that never ends (a missing closing quote).');
    }

    private function doubleQuoted(int $start): void
    {
        if ($this->dialect !== Dialect::MySql) {
            $this->quoted($start, '"', TokenType::QuotedIdentifier, false);

            return;
        }

        // MySQL: a string by default, an identifier under ANSI_QUOTES. With a
        // backslash inside, the two modes end it at different places.
        $this->quoted($start, '"', TokenType::String, true);
        $token = end($this->tokens);
        if ($token !== false && str_contains($token->text, '\\')) {
            throw new QueryRefused('Use single quotes for text with a backslash: in MySQL a double-quoted value can end in two different places depending on the server mode.');
        }
    }

    private function bracketIdentifier(int $start): void
    {
        $end = strpos($this->sql, ']', $this->pos + 1);
        if ($end === false) {
            throw new QueryRefused('The statement has a [quoted name] that never ends.');
        }
        $this->pos = $end + 1;
        $this->push(TokenType::QuotedIdentifier, $start);
    }

    /** PostgreSQL $tag$...$tag$ strings and $1 parameters. Returns false for a lone $. */
    private function dollar(int $start): bool
    {
        if (preg_match('/\G\$[0-9]+/', $this->sql, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $this->push(TokenType::Placeholder, $start);

            return true;
        }
        if (! preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $this->sql, $m, 0, $this->pos)) {
            return false;
        }
        $tag = $m[0];
        $end = strpos($this->sql, $tag, $this->pos + strlen($tag));
        if ($end === false) {
            throw new QueryRefused("The statement has a {$tag} quoted string that never ends.");
        }
        $this->pos = $end + strlen($tag);
        $this->push(TokenType::String, $start);

        return true;
    }
}
