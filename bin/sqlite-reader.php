<?php

/*
 * laravel-sql-dashboard: runs one SQLite query in its own process.
 *
 * SQLite has no statement timeout and PHP cannot interrupt a running
 * statement, so the dashboard starts this script and kills it when the
 * timeout passes. The database file is opened read-only, with query_only on
 * and SQLite's authorizer allowing reads only, so SQLite itself refuses any
 * write, including to other files (ATTACH, VACUUM INTO). Reads a JSON job on
 * stdin, writes a JSON result on stdout. It does not boot Laravel.
 */

declare(strict_types=1);

$job = json_decode((string) stream_get_contents(STDIN), true);
$reply = static function (array $data): void {
    fwrite(STDOUT, (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
};

if (! is_array($job) || ! isset($job['database'], $job['sql'])) {
    $reply(['error' => 'Bad job.']);
    exit(2);
}

// SQLite's own parser reports every action a statement would take: allow reading only.
// Codes from sqlite3.h: 20 READ, 21 SELECT, 31 FUNCTION, 33 RECURSIVE. Everything else (ATTACH,
// PRAGMA, VACUUM INTO, any write) is denied, and so are functions that touch files or load code.
$authorize = static function (int $action, ?string $arg1 = null, ?string $arg2 = null): int {
    if ($action === 31) {
        return in_array(strtolower((string) $arg2), ['load_extension', 'readfile', 'writefile', 'edit', 'fts3_tokenizer'], true) ? 1 : 0;
    }

    return in_array($action, [20, 21, 33], true) ? 0 : 1;
};

try {
    $maxRows = (int) ($job['max_rows'] ?? 1000);
    $maxBytes = (int) ($job['max_bytes'] ?? 16777216);
    $rows = [];
    $bytes = 0;
    $truncated = null;
    $columns = [];
    $add = static function (array $row) use (&$rows, &$bytes, &$truncated, $maxRows, $maxBytes): bool {
        if (count($rows) >= $maxRows) {
            $truncated = 'rows';

            return false;
        }
        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) : 8;
        }
        $rows[] = $row;
        if ($bytes > $maxBytes) {
            $truncated = 'bytes';

            return false;
        }

        return true;
    };

    if (class_exists(SQLite3::class)) {
        // ext-sqlite3 has had setAuthorizer() since PHP 8.0, so the authorizer applies on every PHP version.
        $db = new SQLite3((string) $job['database'], SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->exec('PRAGMA query_only = 1');
        $db->setAuthorizer($authorize);

        $stmt = $db->prepare((string) $job['sql']);
        if ($stmt === false) {
            throw new RuntimeException($db->lastErrorMsg());
        }
        foreach ((array) ($job['bindings'] ?? []) as $i => $value) {
            $stmt->bindValue($i + 1, is_bool($value) ? (int) $value : $value, match (true) {
                is_int($value), is_bool($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                $value === null => SQLITE3_NULL,
                default => SQLITE3_TEXT,
            });
        }
        $result = $stmt->execute();
        for ($i = 0, $n = $result->numColumns(); $i < $n; $i++) {
            $columns[] = $result->columnName($i) ?: 'column_'.($i + 1);
        }
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            if (! $add($row)) {
                break;
            }
        }
    } else {
        // Without ext-sqlite3: PDO, read-only flags, and the authorizer where PDO has one (PHP 8.5+).
        $flags = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') : constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
        $readOnly = defined('Pdo\Sqlite::OPEN_READONLY') ? constant('Pdo\Sqlite::OPEN_READONLY') : constant('PDO::SQLITE_OPEN_READONLY');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, $flags => $readOnly];
        $pdo = method_exists(PDO::class, 'connect')
            ? PDO::connect('sqlite:'.$job['database'], null, null, $options)
            : new PDO('sqlite:'.$job['database'], null, null, $options);
        $pdo->exec('PRAGMA query_only = 1');
        if (method_exists($pdo, 'setAuthorizer')) {
            $pdo->setAuthorizer($authorize);
        }
        $stmt = $pdo->prepare((string) $job['sql']);
        foreach ((array) ($job['bindings'] ?? []) as $i => $value) {
            $stmt->bindValue($i + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $stmt->execute();
        for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = is_array($meta) && ($meta['name'] ?? '') !== '' ? (string) $meta['name'] : 'column_'.($i + 1);
        }
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            if (! $add($row)) {
                break;
            }
        }
    }

    $reply(['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated]);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $reply([
        'error' => preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $message),
        'read_only' => stripos($message, 'readonly') !== false || stripos($message, 'query_only') !== false || stripos($message, 'read-only') !== false
            || stripos($message, 'not authorized') !== false || stripos($message, 'authorization denied') !== false,
    ]);
}
