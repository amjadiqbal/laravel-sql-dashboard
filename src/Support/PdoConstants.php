<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Support;

/**
 * Driver constants without the PHP 8.5 deprecation: Pdo\Sqlite and Pdo\Mysql
 * exist from PHP 8.4, the old PDO:: names are deprecated in 8.5. Same values.
 */
final class PdoConstants
{
    public static function sqliteOpenFlags(): int
    {
        return self::get('Pdo\Sqlite::ATTR_OPEN_FLAGS', 'PDO::SQLITE_ATTR_OPEN_FLAGS');
    }

    public static function sqliteReadOnly(): int
    {
        return self::get('Pdo\Sqlite::OPEN_READONLY', 'PDO::SQLITE_OPEN_READONLY');
    }

    public static function mysqlBufferedQuery(): int
    {
        return self::get('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY', 'PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    private static function get(string $new, string $old): int
    {
        return (int) (defined($new) ? constant($new) : constant($old));
    }
}
