<?php

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\Drivers\SqliteExecutor;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Models\Audit;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use Illuminate\Support\Facades\DB;

function runner(): Runner
{
    return app(Runner::class);
}

function ada(): Actor
{
    return new Actor('1', 'Ada', '127.0.0.1');
}

it('runs a read on a real SQLite file through the read-only child process', function () {
    $result = runner()->run('SELECT status, count(*) AS n FROM orders GROUP BY status ORDER BY status', [], [], ada(), 'web');

    expect($result->columns)->toBe(['status', 'n'])
        ->and($result->rows)->toBe([['failed', 30], ['paid', 60], ['refunded', 30]])
        ->and($result->truncated)->toBeNull()
        ->and(Audit::query()->sole()->only(['user_id', 'user_label', 'source', 'status', 'row_count', 'ip']))
        ->toBe(['user_id' => '1', 'user_label' => 'Ada', 'source' => 'web', 'status' => 'ok', 'row_count' => 3, 'ip' => '127.0.0.1']);
});

it('binds typed variables as parameters', function () {
    $defs = ['status' => ['type' => 'text', 'label' => 'S', 'default' => null, 'options' => []], 'min' => ['type' => 'decimal', 'label' => 'M', 'default' => '100', 'options' => []]];

    $paid = runner()->run('SELECT count(*) FROM orders WHERE status = {status} AND total >= {min}', $defs, ['status' => 'paid'], ada(), 'web');
    $injection = runner()->run('SELECT count(*) FROM orders WHERE status = {status}', $defs, ['status' => "paid' OR '1'='1"], ada(), 'web');

    expect($paid->rows[0][0])->toBe(27)
        ->and($injection->rows[0][0])->toBe(0)
        ->and(Audit::query()->orderBy('id')->first()->variables)->toBe(['status' => 'paid', 'min' => '100']);
});

it('refuses UPDATE, DELETE, DROP, stacked statements and a write hidden in a CTE, and logs each refusal', function (string $sql) {
    expect(fn () => runner()->run($sql, [], [], ada(), 'editor'))->toThrow(QueryRefused::class);
    expect(Audit::query()->sole()->status)->toBe('refused')
        ->and(DB::connection('seed')->table('orders')->count())->toBe(120);
})->with([
    'update' => 'UPDATE orders SET total = 0',
    'delete' => 'DELETE FROM orders',
    'drop' => 'DROP TABLE orders',
    'stacked' => 'SELECT 1; DELETE FROM orders',
    'cte write' => 'WITH c AS (SELECT 1) UPDATE orders SET total = 0',
]);

it('lets SQLite itself refuse a write even if the guard were bypassed', function (string $sql) {
    $connection = DB::connection('dashboard');
    expect(fn () => (new SqliteExecutor)->run($connection, new CompiledStatement($sql, [], []), Limits::fromConfig()))
        ->toThrow(QueryFailed::class, 'refused to write');
    expect(DB::connection('seed')->table('orders')->count())->toBe(120);
})->with([
    'delete' => 'DELETE FROM orders',
    'create' => 'CREATE TABLE x (id int)',
    'update' => 'UPDATE orders SET total = 0',
]);

it('refuses a connection that can write', function () {
    config(['sql-dashboard.connection' => 'seed']);

    expect(fn () => runner()->run('SELECT 1', [], [], ada(), 'web'))->toThrow(QueryRefused::class, 'opens the file for writing');
    expect(Audit::query()->sole()->status)->toBe('refused');
});

it('refuses no connection, an unknown one, or the storage connection', function (?string $name, string $message) {
    config(['sql-dashboard.connection' => $name]);

    expect(fn () => runner()->run('SELECT 1', [], [], ada(), 'web'))->toThrow(QueryRefused::class, $message);
})->with([
    [null, 'No read-only connection'],
    ['nope', "no database connection named 'nope'"],
    ['testing', 'stores its own data'],
]);

it('stops a long-running query at the timeout', function () {
    $started = microtime(true);
    $slow = 'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 500000000) SELECT count(*) FROM n';

    expect(fn () => runner()->run($slow, [], [], ada(), 'web', null, new Limits(1.0, 100, 1 << 20)))
        ->toThrow(QueryFailed::class, 'statement timeout');
    expect(microtime(true) - $started)->toBeLessThan(5.0)
        ->and(Audit::query()->sole()->status)->toBe('timeout');
});

it('cuts off an oversized result at the row and byte limits', function () {
    $many = 'SELECT a.id, b.id FROM orders a CROSS JOIN orders b';
    $rows = runner()->run($many, [], [], ada(), 'web', null, new Limits(10, 250, 1 << 20));
    $bytes = runner()->run("SELECT printf('%.2000c', 'x') FROM orders", [], [], ada(), 'web', null, new Limits(10, 1000, 50_000));

    expect($rows->rowCount())->toBe(250)->and($rows->truncated)->toBe('rows')
        ->and($bytes->rowCount())->toBeLessThan(30)->and($bytes->truncated)->toBe('bytes')
        ->and(Audit::query()->orderBy('id')->first()->message)->toContain('row limit');
});

it('reports SQL errors without leaking a stack trace', function () {
    expect(fn () => runner()->run('SELECT nope FROM orders', [], [], ada(), 'web'))->toThrow(QueryFailed::class, 'no such column');
    expect(Audit::query()->sole()->status)->toBe('failed');
});

it('refuses missing variables before touching the database', function () {
    $defs = ['since' => ['type' => 'date', 'label' => 'Since', 'default' => null, 'options' => []]];

    expect(fn () => runner()->run('SELECT * FROM orders WHERE day >= {since}', $defs, [], ada(), 'web'))->toThrow(QueryRefused::class, 'Give a value for {since}');
});
