<?php

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Execution\ConnectionVerifier;
use AmjadIqbal\SqlDashboard\Execution\Drivers\MysqlExecutor;
use AmjadIqbal\SqlDashboard\Execution\Drivers\PostgresExecutor;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use AmjadIqbal\SqlDashboard\Sql\Dialect;
use AmjadIqbal\SqlDashboard\Sql\StatementGuard;
use AmjadIqbal\SqlDashboard\Tests\Integration\IntegrationTestCase;
use AmjadIqbal\SqlDashboard\Tests\Sweep\SweepLog;
use Illuminate\Support\Facades\DB;

/*
 * The adversarial sweep on a real server (research/laravel-sql-dashboard-SECURITY-SWEEP.md).
 * Every attempt runs end to end through the Runner on the read-only user; the canary (rows, total,
 * sequence, locks, temp and copied tables) must not change. Attempts the text cannot reveal ('db') also
 * run with the guard and the user check skipped, on an admin login, to show the database refuses them.
 */

function sweepDriver(): string
{
    $driver = IntegrationTestCase::driver();
    if ($driver === 'mysql') {
        return MysqlExecutor::isMariaDb(DB::connection('root')->getPdo()) ? 'mariadb' : 'mysql';
    }

    return $driver;
}

function sweepSetUp(): void
{
    $root = DB::connection('root');
    if (IntegrationTestCase::pg()) {
        $root->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sweep_write() RETURNS int LANGUAGE plpgsql AS $$
            BEGIN INSERT INTO orders (id, customer_id, day, status, total) VALUES (9999, 1, '2026-01-01', 'sweep', 1); RETURN 1; END $$;
            CREATE OR REPLACE VIEW sweep_nextval_view AS SELECT nextval('orders_id_seq') AS n;
            GRANT SELECT ON sweep_nextval_view TO dash_ro;
            SQL);
    } else {
        $root->unprepared('SET GLOBAL log_bin_trust_function_creators = 1');
        $root->unprepared('DROP FUNCTION IF EXISTS sweep_write');
        $root->unprepared("CREATE DEFINER = 'root'@'%' FUNCTION sweep_write() RETURNS INT MODIFIES SQL DATA SQL SECURITY DEFINER BEGIN INSERT INTO orders (id, customer_id, day, status, total) VALUES (9999, 1, '2026-01-01', 'sweep', 1); RETURN 1; END");
        if (sweepDriver() === 'mariadb') {
            $root->unprepared('CREATE OR REPLACE SEQUENCE sweep_seq');
        }
    }
}

/** A fingerprint of everything an attempt could change. */
function sweepCanary(): string
{
    $root = DB::connection('root');
    $base = $root->selectOne('SELECT count(*) AS n, sum(total) AS t FROM orders');
    $parts = ['rows='.$base->n, 'total='.$base->t];
    if (IntegrationTestCase::pg()) {
        $parts[] = 'seq='.$root->selectOne('SELECT last_value FROM orders_id_seq')->last_value;
        $parts[] = 'advisory='.$root->selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory'")->n;
        $parts[] = 'temp='.$root->selectOne("SELECT count(*) AS n FROM pg_class WHERE relpersistence = 't'")->n;
        $parts[] = 'copies='.$root->selectOne("SELECT count(*) AS n FROM pg_class WHERE relname LIKE 'sweep\\_copy%' OR relname = 'sweep_tmp'")->n;
        $parts[] = 'slots='.$root->selectOne('SELECT count(*) AS n FROM pg_replication_slots')->n;
    } else {
        $parts[] = 'tables='.$root->selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_name LIKE 'sweep%' AND table_type = 'BASE TABLE'")->n;
        $parts[] = 'lock='.json_encode($root->selectOne("SELECT IS_USED_LOCK('sweep') AS l")->l);
        if (sweepDriver() === 'mariadb') {
            $parts[] = 'seq='.$root->selectOne('SELECT next_not_cached_value AS v FROM sweep_seq')->v;
        }
    }

    return implode(' ', $parts);
}

function sweepCases(): array
{
    $all = require __DIR__.'/../Sweep/cases.php';
    $driver = IntegrationTestCase::driver() === 'pgsql' ? 'pgsql' : 'mysql';
    $cases = $all[$driver];
    if (IntegrationTestCase::driver() === 'mariadb') {
        $cases += $all['mariadb'];
    }

    return array_map(fn ($c) => [$c[0], $c[1]], $cases);
}

it('refuses every attempt end to end and changes nothing', function (string $sql, string $expect) {
    sweepSetUp();
    $before = sweepCanary();

    $full = SweepLog::attempt(fn () => app(Runner::class)->run($sql, [], [], new Actor('1', 'sweep'), 'web', null, new Limits(3, 50, 1 << 20)));
    $direct = null;
    if ($expect === 'db') {
        // Skip the guard and the user check: an admin login, straight into the read-only transaction.
        $executor = IntegrationTestCase::pg() ? new PostgresExecutor : new MysqlExecutor;
        $direct = SweepLog::attempt(fn () => $executor->run(DB::connection('root'), new CompiledStatement($sql, [], []), new Limits(3, 50, 1 << 20)));
    }
    $after = sweepCanary();

    SweepLog::write(['driver' => sweepDriver(), 'case' => (string) $this->dataName(), 'sql' => $sql, 'expect' => $expect, 'full' => $full, 'direct_admin' => $direct, 'changed' => $before !== $after, 'canary' => $after]);

    expect($after)->toBe($before);
    if ($expect === 'guard') {
        expect($full['outcome'])->toBe('refused');
    } elseif ($expect === 'db') {
        expect($full['outcome'])->not->toBe('ran')
            ->and($direct['outcome'])->not->toBe('ran');
    } else {
        expect($full['outcome'])->toBe('ran');
    }
})->with(fn () => sweepCases());

it('MariaDB sequences: NEXT VALUE FOR is refused by the database user check even with the guard skipped', function () {
    if (sweepDriver() !== 'mariadb') {
        $this->markTestSkipped('MariaDB only');
    }
    sweepSetUp();
    $before = sweepCanary();
    $asReader = SweepLog::attempt(fn () => (new MysqlExecutor)->run(DB::connection('dashboard'), new CompiledStatement('SELECT NEXT VALUE FOR sweep_seq', [], []), Limits::fromConfig()));
    $asAdmin = SweepLog::attempt(fn () => (new MysqlExecutor)->run(DB::connection('root'), new CompiledStatement('SELECT NEXT VALUE FOR sweep_seq', [], []), Limits::fromConfig()));
    $after = sweepCanary();
    SweepLog::write(['driver' => 'mariadb', 'case' => 'NEXT VALUE FOR, guard skipped', 'sql' => 'SELECT NEXT VALUE FOR sweep_seq', 'expect' => 'db', 'full' => $asReader, 'direct_admin' => $asAdmin, 'changed' => $before !== $after, 'canary' => $after]);

    expect($asReader['outcome'])->not->toBe('ran');
});

it('PostgreSQL: a SECURITY DEFINER function that writes through dblink is refused', function () {
    if (! IntegrationTestCase::pg()) {
        $this->markTestSkipped('PostgreSQL only');
    }
    $root = DB::connection('root');
    $root->unprepared(<<<'SQL'
        CREATE SCHEMA IF NOT EXISTS sweep_ext;
        CREATE EXTENSION IF NOT EXISTS dblink SCHEMA sweep_ext;
        REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA sweep_ext FROM PUBLIC;
        CREATE OR REPLACE FUNCTION sweep_definer() RETURNS int LANGUAGE plpgsql SECURITY DEFINER AS $$
        BEGIN PERFORM sweep_ext.dblink_exec('dbname=dash user=postgres password=root host=127.0.0.1', 'DELETE FROM orders WHERE id = 1'); RETURN 1; END $$;
        SQL);
    $verifier = app(ConnectionVerifier::class);
    $verifier->forget();
    $before = sweepCanary();

    try {
        // Proof the hole is real without the user check: the statement check lets the call through
        // (nothing in the text says it writes) and the read-only transaction does not cover dblink's
        // own connection, so the row is deleted.
        $proof = SweepLog::attempt(fn () => (new PostgresExecutor)->run(DB::connection('dashboard'), StatementGuard::compile('SELECT sweep_definer()', Dialect::Postgres), Limits::fromConfig()));
        $afterProof = sweepCanary();
        SweepLog::write(['driver' => 'pgsql', 'case' => 'SECURITY DEFINER via dblink, user check skipped (proof)', 'sql' => 'SELECT sweep_definer()', 'expect' => 'proof', 'full' => $proof, 'direct_admin' => null, 'changed' => $before !== $afterProof, 'canary' => $afterProof]);
        expect($afterProof)->not->toBe($before);
        $root->unprepared("INSERT INTO orders (id, customer_id, day, status, total) VALUES (1, 2, '2026-09-02', 'paid', 1.5)");
        expect(sweepCanary())->toBe($before);

        $full = SweepLog::attempt(fn () => app(Runner::class)->run('SELECT sweep_definer()', [], [], new Actor('1', 'sweep'), 'web'));
        $after = sweepCanary();
        SweepLog::write(['driver' => 'pgsql', 'case' => 'SECURITY DEFINER function writing through dblink', 'sql' => 'SELECT sweep_definer()', 'expect' => 'verifier', 'full' => $full, 'direct_admin' => null, 'changed' => $before !== $after, 'canary' => $after]);

        expect($after)->toBe($before)->and($full['outcome'])->toBe('refused')->and($full['message'])->toContain('SECURITY DEFINER');
    } finally {
        $root->unprepared('DROP FUNCTION IF EXISTS sweep_definer(); DROP EXTENSION IF EXISTS dblink; DROP SCHEMA IF EXISTS sweep_ext');
        $verifier->forget();
    }
});
