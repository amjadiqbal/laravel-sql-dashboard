<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data connection (required)
    |--------------------------------------------------------------------------
    |
    | The connection every query runs on. It must be a separate connection
    | whose database user can only read: the dashboard checks this before
    | every run and refuses a connection that can write. Supported drivers:
    | mysql, mariadb, pgsql and sqlite. See the README for the GRANTs.
    |
    |   MySQL / MariaDB:  GRANT SELECT, SHOW VIEW ON app.* TO 'dashboard'@'%';
    |   PostgreSQL:       GRANT SELECT ON ALL TABLES IN SCHEMA public TO dashboard;
    |   SQLite:           'options' => [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]
    |
    | Left empty, nothing runs and the dashboard tells you how to set it up.
    |
    */

    'connection' => env('SQL_DASHBOARD_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Storage connection
    |--------------------------------------------------------------------------
    |
    | Where saved queries, dashboards, checks and the audit log are stored.
    | Null uses your default connection. Never the read-only one above.
    |
    */

    'storage_connection' => env('SQL_DASHBOARD_STORAGE_CONNECTION'),

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | timeout: seconds before the database stops a statement (MySQL
    | max_execution_time, MariaDB max_statement_time, PostgreSQL
    | statement_timeout; SQLite runs in a child process that is killed).
    | max_rows: rows shown in the browser and used for charts and checks.
    | export_max_rows: rows in a CSV export. max_bytes: a result stops growing
    | once the values read pass this size. Every cut-off result says so.
    |
    */

    'limits' => [
        'timeout' => (float) env('SQL_DASHBOARD_TIMEOUT', 15),
        'max_rows' => (int) env('SQL_DASHBOARD_MAX_ROWS', 1000),
        'export_max_rows' => (int) env('SQL_DASHBOARD_EXPORT_MAX_ROWS', 10000),
        'max_bytes' => 16 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQLite
    |--------------------------------------------------------------------------
    |
    | SQLite has no statement timeout, so SQLite queries run in a short-lived
    | PHP process that is killed when the timeout passes. Null finds the PHP
    | CLI binary next to the running PHP.
    |
    */

    'sqlite' => [
        'php_binary' => env('SQL_DASHBOARD_PHP_BINARY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard routes
    |--------------------------------------------------------------------------
    |
    | Access is decided by three gates, which allow the local environment only
    | until you define them yourself (see the README):
    |
    |   viewSqlDashboard         open the dashboard and see saved queries
    |   runSqlDashboardQueries   run saved queries, dashboards and exports
    |   editSqlDashboardQueries  write SQL, manage dashboards and checks, read the audit log
    |
    | Writing SQL also needs a signed-in user, whatever the gate says. The
    | gate checks are always added after the middleware below, so emptying
    | this list cannot expose the dashboard.
    |
    */

    'path' => env('SQL_DASHBOARD_PATH', 'sql-dashboard'),
    'domain' => env('SQL_DASHBOARD_DOMAIN'),
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Checks and alerts
    |--------------------------------------------------------------------------
    |
    | The package schedules sql-dashboard:run-checks every minute (your app
    | must run `php artisan schedule:run`); each check runs at its own
    | interval. Alerts go out when a check starts failing, passes again or
    | errors. The Slack webhook comes from your environment only, so nobody
    | can point alerts at an address of their choosing from the browser.
    | mail_domains limits who can receive alert mail (e.g. ['acme.com']);
    | empty allows any address.
    |
    */

    'schedule' => (bool) env('SQL_DASHBOARD_SCHEDULE', true),

    'alerts' => [
        'slack_webhook_url' => env('SQL_DASHBOARD_SLACK_WEBHOOK_URL'),
        'mail_domains' => [],
        'rows_in_alert' => 10,
    ],

    'intervals' => [
        'every_5_minutes' => 5,
        'hourly' => 60,
        'daily' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Every run, export, check and refused attempt is recorded with who ran
    | it. Entries older than this many days are pruned daily (null keeps all).
    |
    */

    'audit' => [
        'retention_days' => 90,
    ],

];
