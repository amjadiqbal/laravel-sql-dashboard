<?php

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Execution\Drivers\SqliteExecutor;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use AmjadIqbal\SqlDashboard\Tests\Sweep\SweepLog;
use AmjadIqbal\SqlDashboard\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/* The adversarial sweep on SQLite: every attempt end to end on the real file, and again straight into the read-only child process. */

function sqliteCanary(): string
{
    clearstatcache();
    $row = DB::connection('seed')->selectOne('SELECT count(*) AS n, sum(total) AS t FROM orders');

    return "rows={$row->n} total={$row->t} file=".md5_file(TestCase::dataFile()).' tmp='.(int) file_exists('/tmp/sweep.db').(int) file_exists('/tmp/sweep');
}

it('refuses every SQLite attempt end to end and changes nothing', function (string $sql, string $expect) {
    $before = sqliteCanary();
    $full = SweepLog::attempt(fn () => app(Runner::class)->run($sql, [], [], new Actor('1', 'sweep'), 'web', null, new Limits(3, 50, 1 << 20)));
    // The guard and the user check skipped: straight into the read-only child process.
    $direct = SweepLog::attempt(fn () => (new SqliteExecutor)->run(DB::connection('dashboard'), new CompiledStatement($sql, [], []), new Limits(3, 50, 1 << 20)));
    $after = sqliteCanary();
    SweepLog::write(['driver' => 'sqlite', 'case' => (string) $this->dataName(), 'sql' => $sql, 'expect' => $expect, 'full' => $full, 'direct_admin' => $direct, 'changed' => $before !== $after, 'canary' => $after]);

    // End to end: refused. With the guard skipped, SQLite may still run a harmless first statement
    // (it prepares only the first one), so the property checked there is the canary: nothing changed.
    expect($after)->toBe($before)->and($full['outcome'])->toBe('refused');
})->with(fn () => array_map(fn ($c) => [$c[0], $c[1]], (require __DIR__.'/../Sweep/cases.php')['sqlite']));
