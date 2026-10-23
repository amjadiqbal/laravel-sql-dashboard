<?php

namespace AmjadIqbal\SqlDashboard\Tests;

use AmjadIqbal\SqlDashboard\Execution\ConnectionVerifier;
use AmjadIqbal\SqlDashboard\SqlDashboardServiceProvider;
use AmjadIqbal\SqlDashboard\Support\PdoConstants;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;

/**
 * Storage on the in-memory "testing" connection; the data the dashboard
 * reads is a real SQLite file, opened read-only by the "dashboard"
 * connection and writable through "seed" (used only to set it up).
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public static ?string $dataFile = null;

    protected function getPackageProviders($app): array
    {
        return [SqlDashboardServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $file = self::dataFile();
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.connections.seed', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        $app['config']->set('database.connections.dashboard', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false,
            'options' => [PdoConstants::sqliteOpenFlags() => PdoConstants::sqliteReadOnly()],
        ]);
        $app['config']->set('sql-dashboard.connection', 'dashboard');
        $app['config']->set('mail.default', 'array');
    }

    protected function tearDown(): void
    {
        app(ConnectionVerifier::class)->forget();
        parent::tearDown();
    }

    /** A seeded SQLite file shared by the whole run: 10 customers, 120 orders over 30 days. */
    public static function dataFile(): string
    {
        if (self::$dataFile !== null && is_file(self::$dataFile)) {
            return self::$dataFile;
        }
        $file = sys_get_temp_dir().'/sql-dashboard-tests-'.getmypid().'.sqlite';
        @unlink($file);
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, country TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, day TEXT NOT NULL, status TEXT NOT NULL, total REAL NOT NULL)');
        $countries = ['PK', 'GB', 'US', 'DE', 'PK'];
        for ($c = 1; $c <= 10; $c++) {
            $pdo->exec("INSERT INTO customers (id, name, country) VALUES ({$c}, 'Customer {$c}', '{$countries[$c % 5]}')");
        }
        for ($o = 1; $o <= 120; $o++) {
            $day = date('Y-m-d', strtotime('2026-09-01 +'.($o % 30).' days'));
            $status = ['paid', 'paid', 'refunded', 'failed'][$o % 4];
            $pdo->exec('INSERT INTO orders (id, customer_id, day, status, total) VALUES ('.$o.', '.(($o % 10) + 1).", '{$day}', '{$status}', ".($o * 1.5).')');
        }
        register_shutdown_function(fn () => @unlink($file));

        return self::$dataFile = $file;
    }

    protected function allowAll(): void
    {
        foreach (SqlDashboardServiceProvider::ABILITIES as $ability) {
            Gate::define($ability, fn ($user = null) => true);
        }
    }

    protected function user(int $id = 1, string $name = 'Ada'): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => $name, 'remember_token' => null]);
    }
}
