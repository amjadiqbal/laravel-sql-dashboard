<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;

enum Dialect: string
{
    case MySql = 'mysql';
    case Postgres = 'pgsql';
    case Sqlite = 'sqlite';

    public static function fromDriver(string $driver): self
    {
        return match ($driver) {
            'mysql', 'mariadb' => self::MySql,
            'pgsql' => self::Postgres,
            'sqlite' => self::Sqlite,
            default => throw new QueryRefused("The {$driver} driver is not supported. Use mysql, mariadb, pgsql or sqlite."),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MySql => 'MySQL / MariaDB',
            self::Postgres => 'PostgreSQL',
            self::Sqlite => 'SQLite',
        };
    }
}
