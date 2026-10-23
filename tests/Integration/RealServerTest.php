<?php

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\ConnectionVerifier;
use AmjadIqbal\SqlDashboard\Execution\Drivers\MysqlExecutor;
use AmjadIqbal\SqlDashboard\Execution\Drivers\PostgresExecutor;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Models\Audit;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use AmjadIqbal\SqlDashboard\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\DB;

function it_run(string $sql, array $defs = [], array $input = [], ?Limits $limits = null)
{
    return app(Runner::class)->run($sql, $defs, $input, new Actor('1', 'Ada'), 'web', null, $limits);
}

function orderCount(): int
{
    return (int) DB::connection('root')->table('orders')->count();
}

it('runs reads with typed variables bound as parameters', function () {
    $defs = [
        'status' => ['type' => 'text', 'label' => 'S', 'default' => null, 'options' => []],
        'since' => ['type' => 'date', 'label' => 'D', 'default' => '2026-09-25', 'options' => []],
    ];

    $paid = it_run('SELECT count(*) AS n FROM orders WHERE status = {status} AND day >= {since}', $defs, ['status' => 'paid']);
    $evil = it_run('SELECT count(*) AS n FROM orders WHERE status = {status}', $defs, ['status' => "paid' OR '1'='1"]);
    $grouped = it_run('SELECT status, count(*) AS n FROM orders GROUP BY status ORDER BY status');

    expect((int) $paid->rows[0][0])->toBe(12)
        ->and((int) $evil->rows[0][0])->toBe(0)
        ->and(array_map(fn ($r) => [$r[0], (int) $r[1]], $grouped->rows))->toBe([['failed', 30], ['paid', 60], ['refunded', 30]]);
});

it('refuses a connection that can write', function (string $connection, string $message) {
    config(['sql-dashboard.connection' => $connection]);

    expect(fn () => it_run('SELECT 1'))->toThrow(QueryRefused::class, $message);
})->with([
    ['root', 'can write'],
    ['writer', 'can write'],
]);

it('refuses UPDATE, DELETE, DROP, stacked statements and writes hidden in a CTE', function (string $sql) {
    expect(fn () => it_run($sql))->toThrow(QueryRefused::class);
    expect(orderCount())->toBe(120)
        ->and(Audit::query()->sole()->status)->toBe('refused');
})->with([
    'update' => 'UPDATE orders SET total = 0',
    'delete' => 'DELETE FROM orders',
    'drop' => 'DROP TABLE orders',
    'stacked' => 'SELECT 1; DELETE FROM orders',
    'mysql-style CTE delete' => 'WITH c AS (SELECT 1 AS id) DELETE FROM orders WHERE id IN (SELECT id FROM c)',
    'pg-style CTE delete' => 'WITH gone AS (DELETE FROM orders RETURNING id) SELECT count(*) FROM gone',
    'locking read' => 'SELECT * FROM orders FOR UPDATE',
]);

it('has the database refuse a write on its own, even with an admin login and the guard bypassed', function (string $sql) {
    $executor = IntegrationTestCase::pg() ? new PostgresExecutor : new MysqlExecutor;

    // MySQL/MariaDB: the READ ONLY transaction refuses (error 1792). PostgreSQL refuses a write inside
    // DECLARE ... CURSOR FOR at parse time, and a write from a function (nextval) in the READ ONLY transaction.
    try {
        $executor->run(DB::connection('root'), new CompiledStatement($sql, [], []), Limits::fromConfig());
        $this->fail('The database ran a write.');
    } catch (QueryFailed $e) {
        expect($e->getMessage())->toMatch('/refused to write|syntax error at or near "(UPDATE|DELETE)"|must not contain data-modifying/');
    }
    expect(orderCount())->toBe(120)
        ->and((float) DB::connection('root')->table('orders')->sum('total'))->toBe(10890.0);
})->with(function () {
    $cases = ['update' => ['UPDATE orders SET total = 0'], 'delete' => ['DELETE FROM orders WHERE id = 1']];
    if (IntegrationTestCase::pg()) {
        $cases['delete hidden in a CTE'] = ['WITH gone AS (DELETE FROM orders RETURNING id) SELECT count(*) FROM gone'];
        $cases['nextval'] = ["SELECT nextval('orders_id_seq')"];
    }

    return $cases;
});

it('stops a long-running query at the statement timeout', function (string $sql) {
    $started = microtime(true);

    expect(fn () => it_run($sql, [], [], new Limits(1.5, 100, 1 << 20)))->toThrow(QueryFailed::class, 'statement timeout');
    expect(microtime(true) - $started)->toBeLessThan(6.0)
        ->and(Audit::query()->sole()->status)->toBe('timeout');
})->with(function () {
    return IntegrationTestCase::pg()
        ? ['pg_sleep' => ['SELECT pg_sleep(10)'], 'with + pg_sleep' => ['WITH x AS (SELECT 1) SELECT pg_sleep(10) FROM x']]
        : ['sleep per row' => ['SELECT SLEEP(1), id FROM orders'], 'with + sleep per row' => ['WITH x AS (SELECT id FROM orders) SELECT SLEEP(1), id FROM x']];
});

it('cuts off an oversized result at the row limit without loading it all', function () {
    $result = it_run('SELECT a.id, b.id, c.id FROM orders a CROSS JOIN orders b CROSS JOIN orders c', [], [], new Limits(15, 100, 1 << 24));

    expect($result->rowCount())->toBe(100)->and($result->truncated)->toBe('rows');
});

it('leaves the session as it found it', function () {
    it_run('SELECT 1');
    $pdo = DB::connection('dashboard')->getPdo();

    if (IntegrationTestCase::pg()) {
        expect($pdo->query('SHOW statement_timeout')->fetchColumn())->toBe('0')
            ->and($pdo->inTransaction())->toBeFalse();
    } else {
        $maria = MysqlExecutor::isMariaDb($pdo);
        expect((string) $pdo->query('SELECT @@SESSION.sql_select_limit')->fetchColumn())->toBe('18446744073709551615')
            ->and((float) $pdo->query($maria ? 'SELECT @@SESSION.max_statement_time' : 'SELECT @@SESSION.max_execution_time')->fetchColumn())->toBe(0.0);
    }
    expect((int) it_run('SELECT count(*) FROM customers')->rows[0][0])->toBe(10);
});

it('passes sql-dashboard:verify on the read-only user', function () {
    $this->artisan('sql-dashboard:verify')->expectsOutputToContain('Ready')->assertSuccessful();
});

it('refuses side-effect functions spelled with quotes, before they reach the server', function (string $sql) {
    expect(fn () => it_run($sql))->toThrow(QueryRefused::class, 'is not allowed');
    expect(Audit::query()->sole()->status)->toBe('refused');
})->with(function () {
    return IntegrationTestCase::pg()
        ? [
            'quoted advisory lock' => ['SELECT "pg_advisory_lock"(1)'],
            'qualified quoted read_file' => ["SELECT pg_catalog.\"pg_read_file\"('/etc/passwd')"],
            'fully quoted read_file' => ["SELECT \"pg_catalog\".\"pg_read_file\"('/etc/passwd')"],
            'quoted dblink' => ["SELECT * FROM \"dblink\"('dbname=dash', 'DELETE FROM orders') AS t(x int)"],
        ]
        : [
            'backticked get_lock' => ["SELECT `GET_LOCK`('x', 10)"],
            'backticked load_file' => ["SELECT `LOAD_FILE`('/etc/passwd')"],
            'qualified sys_exec' => ["SELECT `mysql`.`sys_exec`('id')"],
        ];
});

it('refuses a user that can reach outside the database', function (string $connection, string $message) {
    config(['sql-dashboard.connection' => $connection]);

    expect(fn () => it_run('SELECT 1'))->toThrow(QueryRefused::class, $message);
})->with(function () {
    return IntegrationTestCase::pg()
        ? [['files', 'member of pg_read_server_files'], ['other', 'member of pg_signal_backend']]
        : [['files', 'FILE ON'], ['other', 'PROCESS ON']];
});

it('releases session-level locks a statement took, even with the guard bypassed', function () {
    $dashboard = DB::connection('dashboard');
    $sql = IntegrationTestCase::pg() ? 'SELECT pg_advisory_lock(424242)' : "SELECT GET_LOCK('sqld-test', 0)";
    $executor = IntegrationTestCase::pg() ? new PostgresExecutor : new MysqlExecutor;

    $executor->run($dashboard, new CompiledStatement($sql, [], []), Limits::fromConfig());

    $held = IntegrationTestCase::pg()
        ? (int) DB::connection('root')->selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND objid = 424242")->n
        : (int) (DB::connection('root')->selectOne("SELECT IS_USED_LOCK('sqld-test') IS NOT NULL AS n")->n);
    expect($held)->toBe(0);
});

it('refuses PostgreSQL when dblink is installed and callable, and passes once EXECUTE is revoked', function () {
    if (! IntegrationTestCase::pg()) {
        $this->markTestSkipped('PostgreSQL only');
    }
    $root = DB::connection('root');
    $root->statement('CREATE EXTENSION IF NOT EXISTS dblink');
    $verifier = app(ConnectionVerifier::class);

    try {
        $verifier->forget();
        expect(fn () => it_run('SELECT 1'))->toThrow(QueryRefused::class, 'can execute dblink');

        $root->statement('REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA public FROM PUBLIC');
        $verifier->forget();
        expect((int) it_run('SELECT 1')->rows[0][0])->toBe(1);
    } finally {
        $root->statement('DROP EXTENSION IF EXISTS dblink');
        $verifier->forget();
    }
});
