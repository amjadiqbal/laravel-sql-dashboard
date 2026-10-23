<?php

/*
 * laravel-sql-dashboard: runs one SQLite query in its own process.
 *
 * SQLite has no statement timeout and PHP cannot interrupt a running
 * statement, so the dashboard starts this script and kills it when the
 * timeout passes. The database file is opened read-only and with
 * query_only on, so SQLite itself refuses any write. Reads a JSON job on
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

$flags = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') : constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
$readOnly = defined('Pdo\Sqlite::OPEN_READONLY') ? constant('Pdo\Sqlite::OPEN_READONLY') : constant('PDO::SQLITE_OPEN_READONLY');

try {
    $pdo = new PDO('sqlite:'.$job['database'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        $flags => $readOnly,
    ]);
    $pdo->exec('PRAGMA query_only = 1');

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

    $columns = [];
    for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
        $meta = $stmt->getColumnMeta($i);
        $columns[] = is_array($meta) && ($meta['name'] ?? '') !== '' ? (string) $meta['name'] : 'column_'.($i + 1);
    }

    $maxRows = (int) ($job['max_rows'] ?? 1000);
    $maxBytes = (int) ($job['max_bytes'] ?? 16777216);
    $rows = [];
    $bytes = 0;
    $truncated = null;
    while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        if (count($rows) >= $maxRows) {
            $truncated = 'rows';
            break;
        }
        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) : 8;
        }
        $rows[] = $row;
        if ($bytes > $maxBytes) {
            $truncated = 'bytes';
            break;
        }
    }

    $reply(['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated]);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $reply([
        'error' => preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $message),
        'read_only' => stripos($message, 'readonly') !== false || stripos($message, 'query_only') !== false || stripos($message, 'read-only') !== false,
    ]);
}
