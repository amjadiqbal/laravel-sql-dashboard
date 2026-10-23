<?php

namespace AmjadIqbal\SqlDashboard\Tests\Integration;

use AmjadIqbal\SqlDashboard\Tests\TestCase;
use PDO;

/**
 * Real servers from scripts/integration.sh (Docker). SQL_DASHBOARD_IT_DRIVER is
 * mysql, mariadb or pgsql. Three connections to one seeded database:
 *   root       an admin that can write anything
 *   dashboard  a user with SELECT only (what the README tells you to create)
 *   writer     SELECT plus INSERT on one table
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $seeded = false;

    public static function driver(): string
    {
        return (string) (getenv('SQL_DASHBOARD_IT_DRIVER') ?: 'mysql');
    }

    public static function port(): int
    {
        return (int) (getenv('SQL_DASHBOARD_IT_PORT') ?: 33306);
    }

    public static function pg(): bool
    {
        return self::driver() === 'pgsql';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        self::seedServer();
        $base = ['driver' => self::driver(), 'host' => '127.0.0.1', 'port' => self::port(), 'database' => 'dash', 'prefix' => '', 'charset' => self::pg() ? 'utf8' : 'utf8mb4'];
        if (self::pg()) {
            $base += ['search_path' => 'public', 'sslmode' => 'disable'];
        }
        $root = self::pg() ? ['username' => 'postgres', 'password' => 'root'] : ['username' => 'root', 'password' => 'root'];
        $app['config']->set('database.connections.root', [...$base, ...$root]);
        $app['config']->set('database.connections.dashboard', [...$base, 'username' => 'dash_ro', 'password' => 'secret']);
        $app['config']->set('database.connections.writer', [...$base, 'username' => 'dash_rw', 'password' => 'secret']);
        // SELECT-only, plus one power that reaches outside the database: pg_read_server_files on PostgreSQL, FILE on MySQL.
        $app['config']->set('database.connections.files', [...$base, 'username' => 'dash_files', 'password' => 'secret']);
        // SELECT-only plus PROCESS (other sessions' SQL) on MySQL; pg_signal_backend on PostgreSQL.
        $app['config']->set('database.connections.other', [...$base, 'username' => 'dash_other', 'password' => 'secret']);
        $app['config']->set('sql-dashboard.connection', 'dashboard');
    }

    public static function rootPdo(string $database): PDO
    {
        $dsn = self::pg()
            ? 'pgsql:host=127.0.0.1;port='.self::port().';dbname='.$database
            : 'mysql:host=127.0.0.1;port='.self::port().';dbname='.$database;

        return new PDO($dsn, self::pg() ? 'postgres' : 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function seedServer(): void
    {
        if (self::$seeded) {
            return;
        }
        self::$seeded = true;

        if (self::pg()) {
            $admin = self::rootPdo('postgres');
            $admin->exec('DROP DATABASE IF EXISTS dash');
            $admin->exec('CREATE DATABASE dash');
            foreach (['dash_ro', 'dash_rw', 'dash_files', 'dash_other'] as $role) {
                $admin->exec("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN CREATE ROLE {$role} LOGIN PASSWORD 'secret'; END IF; END \$\$");
            }
            $pdo = self::rootPdo('dash');
            $pdo->exec('CREATE TABLE customers (id serial PRIMARY KEY, name text NOT NULL, country text NOT NULL)');
            $pdo->exec('CREATE TABLE orders (id serial PRIMARY KEY, customer_id int NOT NULL, day date NOT NULL, status text NOT NULL, total numeric(10,2) NOT NULL)');
        } else {
            $pdo = self::rootPdo('mysql');
            $pdo->exec('DROP DATABASE IF EXISTS dash');
            $pdo->exec('CREATE DATABASE dash');
            $pdo->exec('USE dash');
            $pdo->exec('CREATE TABLE customers (id int AUTO_INCREMENT PRIMARY KEY, name varchar(100) NOT NULL, country char(2) NOT NULL)');
            $pdo->exec('CREATE TABLE orders (id int AUTO_INCREMENT PRIMARY KEY, customer_id int NOT NULL, day date NOT NULL, status varchar(20) NOT NULL, total decimal(10,2) NOT NULL)');
        }

        $countries = ['PK', 'GB', 'US', 'DE', 'PK'];
        for ($c = 1; $c <= 10; $c++) {
            $pdo->exec("INSERT INTO customers (id, name, country) VALUES ({$c}, 'Customer {$c}', '{$countries[$c % 5]}')");
        }
        for ($o = 1; $o <= 120; $o++) {
            $day = date('Y-m-d', strtotime('2026-09-01 +'.($o % 30).' days'));
            $status = ['paid', 'paid', 'refunded', 'failed'][$o % 4];
            $pdo->exec('INSERT INTO orders (id, customer_id, day, status, total) VALUES ('.$o.', '.(($o % 10) + 1).", '{$day}', '{$status}', ".($o * 1.5).')');
        }

        if (self::pg()) {
            $pdo->exec('GRANT USAGE ON SCHEMA public TO dash_ro, dash_rw, dash_files, dash_other');
            $pdo->exec('GRANT SELECT ON ALL TABLES IN SCHEMA public TO dash_ro, dash_rw, dash_files, dash_other');
            $pdo->exec('GRANT INSERT ON orders TO dash_rw');
            $pdo->exec('GRANT pg_read_server_files TO dash_files');
            $pdo->exec('GRANT pg_signal_backend TO dash_other');
        } else {
            foreach (['dash_ro', 'dash_rw', 'dash_files', 'dash_other'] as $user) {
                $pdo->exec("DROP USER IF EXISTS '{$user}'@'%'");
                $pdo->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY 'secret'");
            }
            $pdo->exec("GRANT SELECT, SHOW VIEW ON dash.* TO 'dash_ro'@'%'");
            $pdo->exec("GRANT SELECT ON dash.* TO 'dash_rw'@'%'");
            $pdo->exec("GRANT INSERT ON dash.orders TO 'dash_rw'@'%'");
            $pdo->exec("GRANT SELECT ON dash.* TO 'dash_files'@'%'");
            $pdo->exec("GRANT FILE ON *.* TO 'dash_files'@'%'");
            $pdo->exec("GRANT SELECT ON dash.* TO 'dash_other'@'%'");
            $pdo->exec("GRANT PROCESS ON *.* TO 'dash_other'@'%'");
        }
    }
}
