<?php

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Sql\Dialect;
use AmjadIqbal\SqlDashboard\Sql\StatementGuard;

function guard(string $sql, Dialect $dialect = Dialect::Postgres, array $values = []): string
{
    return StatementGuard::compile($sql, $dialect, $values)->sql;
}

$all = ['mysql' => Dialect::MySql, 'pgsql' => Dialect::Postgres, 'sqlite' => Dialect::Sqlite];

it('allows plain reads', function (Dialect $d) {
    expect(guard('SELECT id, total FROM orders WHERE total > 10', $d))->toBe('SELECT id, total FROM orders WHERE total > 10')
        ->and(guard("WITH x AS (SELECT 1 AS n) SELECT n FROM x;\n", $d))->toBe('WITH x AS (SELECT 1 AS n) SELECT n FROM x')
        ->and(guard('(SELECT 1) UNION (SELECT 2)', $d))->toBe('(SELECT 1) UNION (SELECT 2)')
        ->and(guard("-- top customers\nselect 1 ;;", $d))->toBe("-- top customers\nselect 1");
})->with($all);

it('ignores write words and semicolons inside strings, quoted names and comments', function (Dialect $d) {
    $sql = "SELECT 'DROP TABLE x; DELETE FROM y' AS note, \"update\" /* ; INSERT */ FROM t -- ; UPDATE\n";

    expect(guard($sql, $d))->toBe(rtrim($sql));
})->with(['pgsql' => Dialect::Postgres, 'sqlite' => Dialect::Sqlite]);

it('allows REPLACE() the function but not REPLACE the statement', function () {
    expect(guard("SELECT REPLACE(name, 'a', 'b') FROM t", Dialect::MySql))->toContain('REPLACE(');
    guard('REPLACE INTO t VALUES (1)', Dialect::MySql);
})->throws(QueryRefused::class, 'Only SELECT and WITH');

it('refuses statements that write', function (string $sql, Dialect $d) {
    guard($sql, $d);
})->with([
    'update' => ['UPDATE orders SET total = 0', Dialect::MySql],
    'delete' => ['DELETE FROM orders', Dialect::Postgres],
    'drop' => ['DROP TABLE orders', Dialect::Sqlite],
    'insert' => ['INSERT INTO orders (id) VALUES (1)', Dialect::MySql],
    'truncate' => ['TRUNCATE orders', Dialect::Postgres],
    'alter' => ['ALTER TABLE orders ADD COLUMN x int', Dialect::MySql],
    'create table as' => ['CREATE TABLE copy AS SELECT * FROM orders', Dialect::Postgres],
    'grant' => ['GRANT ALL ON orders TO public', Dialect::Postgres],
    'set' => ['SET GLOBAL read_only = 0', Dialect::MySql],
    'pragma' => ['PRAGMA writable_schema = 1', Dialect::Sqlite],
    'attach' => ["ATTACH DATABASE '/tmp/x.db' AS x", Dialect::Sqlite],
    'copy' => ["COPY orders TO '/tmp/orders.csv'", Dialect::Postgres],
    'do block' => ['DO $$ BEGIN DELETE FROM orders; END $$', Dialect::Postgres],
    'call' => ['CALL wipe()', Dialect::MySql],
    'explain analyze' => ['EXPLAIN ANALYZE DELETE FROM orders', Dialect::Postgres],
    'lowercase' => ['delete from orders', Dialect::Sqlite],
    'leading comment' => ['/* report */ DELETE FROM orders', Dialect::Postgres],
    'in parens' => ['(DELETE FROM orders)', Dialect::Postgres],
])->throws(QueryRefused::class, 'Only SELECT and WITH');

it('refuses stacked statements', function (string $sql, Dialect $d) {
    guard($sql, $d);
})->with([
    'drop after select' => ['SELECT 1; DROP TABLE orders', Dialect::MySql],
    'no space' => ['SELECT 1;DELETE FROM orders', Dialect::Postgres],
    'two selects' => ['SELECT 1; SELECT 2', Dialect::Sqlite],
    'after a comment' => ["SELECT 1 /* x */ ; -- y\n UPDATE orders SET total = 0", Dialect::Postgres],
    'mysql --1 is not a comment' => ['SELECT 1 --1; DROP TABLE orders', Dialect::MySql],
    'mysql string escape' => ["SELECT 'it\\'s'; DROP TABLE orders", Dialect::MySql],
    'pg E-string escape' => ["SELECT E'it\\'s'; DROP TABLE orders", Dialect::Postgres],
])->throws(QueryRefused::class, 'one statement');

it('reads -- the way each database does', function () {
    // PostgreSQL: "--1; DROP ..." is a comment to the end of the line, so this is one harmless statement.
    expect(guard('SELECT 1 --1; DROP TABLE orders', Dialect::Postgres))->toBe('SELECT 1 --1; DROP TABLE orders');
});

it('keeps a semicolon inside a dollar-quoted string', function () {
    expect(guard('SELECT $tag$a; DROP TABLE orders$tag$ AS t', Dialect::Postgres))->toContain('$tag$');
});

it('refuses writes hidden inside a read', function (string $sql, Dialect $d, string $message) {
    expect(fn () => guard($sql, $d))->toThrow(QueryRefused::class, $message);
})->with([
    'pg delete in CTE' => ['WITH gone AS (DELETE FROM orders RETURNING *) SELECT count(*) FROM gone', Dialect::Postgres, 'DELETE is not allowed'],
    'pg update in CTE' => ['WITH x AS (UPDATE orders SET total = 0 RETURNING id) SELECT * FROM x', Dialect::Postgres, 'UPDATE is not allowed'],
    'pg insert in CTE' => ['WITH x AS (INSERT INTO log VALUES (1) RETURNING *) SELECT 1', Dialect::Postgres, 'INSERT is not allowed'],
    'mysql CTE then delete' => ['WITH c AS (SELECT 1 AS id) DELETE FROM orders WHERE id IN (SELECT id FROM c)', Dialect::MySql, 'DELETE is not allowed'],
    'sqlite CTE then update' => ['WITH c AS (SELECT 1) UPDATE orders SET total = 0', Dialect::Sqlite, 'UPDATE is not allowed'],
    'select into table' => ['SELECT * INTO backup FROM orders', Dialect::Postgres, 'INTO is not allowed'],
    'into outfile' => ["SELECT * FROM orders INTO OUTFILE '/tmp/o.csv'", Dialect::MySql, 'INTO is not allowed'],
    'for update' => ['SELECT * FROM orders FOR UPDATE', Dialect::Postgres, 'UPDATE is not allowed'],
    'for share' => ['SELECT * FROM orders FOR SHARE', Dialect::Postgres, 'Locking reads'],
    'for key share' => ['SELECT * FROM orders FOR KEY SHARE', Dialect::Postgres, 'Locking reads'],
    'lock in share mode' => ['SELECT * FROM orders LOCK IN SHARE MODE', Dialect::MySql, 'LOCK is not allowed'],
    'mysql executable comment' => ['SELECT 1 /*! ; DROP TABLE orders */', Dialect::MySql, 'executable comments'],
    'mariadb executable comment' => ['SELECT 1 /*M! , SLEEP(1) */', Dialect::MySql, 'executable comments'],
    'set_config' => ["SELECT set_config('role', 'admin', false)", Dialect::Postgres, 'set_config() is not allowed'],
    'qualified set_config' => ["SELECT pg_catalog.set_config('x', 'y', false)", Dialect::Postgres, 'set_config() is not allowed'],
    'advisory lock' => ['SELECT pg_advisory_lock(1)', Dialect::Postgres, 'pg_advisory_lock() is not allowed'],
    'nextval' => ["SELECT nextval('orders_id_seq')", Dialect::Postgres, 'nextval() is not allowed'],
    'query_to_xml' => ["SELECT query_to_xml('DELETE FROM orders', true, true, '')", Dialect::Postgres, 'query_to_xml() is not allowed'],
    'dblink' => ["SELECT * FROM dblink('host=x', 'DELETE FROM t') AS t(x int)", Dialect::Postgres, 'dblink() is not allowed'],
    'pg_read_file' => ["SELECT pg_read_file('/etc/passwd')", Dialect::Postgres, 'pg_read_file() is not allowed'],
    'get_lock' => ["SELECT GET_LOCK('x', 10)", Dialect::MySql, 'get_lock() is not allowed'],
    'load_file' => ["SELECT LOAD_FILE('/etc/passwd')", Dialect::MySql, 'load_file() is not allowed'],
    'load_extension' => ["SELECT load_extension('/tmp/evil.so')", Dialect::Sqlite, 'load_extension() is not allowed'],
    'assignment' => ['SELECT @x := 1', Dialect::MySql, 'Assignments'],
]);

it('refuses raw parameters', function (string $sql, Dialect $d) {
    guard($sql, $d);
})->with([
    ['SELECT * FROM orders WHERE id = ?', Dialect::MySql],
    ['SELECT * FROM orders WHERE id = $1', Dialect::Postgres],
    ['SELECT * FROM orders WHERE id = :id', Dialect::Sqlite],
    ['SELECT * FROM orders WHERE id = @id', Dialect::Sqlite],
    ["SELECT data ? 'key' FROM t", Dialect::Postgres],
])->throws(QueryRefused::class);

it('fails closed on text it cannot read one way', function (string $sql, Dialect $d, string $message) {
    expect(fn () => guard($sql, $d))->toThrow(QueryRefused::class, $message);
})->with([
    ["SELECT 'open", Dialect::Postgres, 'never ends'],
    ['SELECT 1 /* open', Dialect::MySql, 'never ends'],
    ['SELECT $x$ open', Dialect::Postgres, 'never ends'],
    ['SELECT "a\\"; DROP TABLE t; --"', Dialect::MySql, 'single quotes'],
    ['', Dialect::Sqlite, 'empty'],
    ['-- only a comment', Dialect::Sqlite, 'empty'],
]);

it('nests block comments only on PostgreSQL', function () {
    // PostgreSQL: the comment ends at the second */, so this is one statement.
    expect(guard('SELECT 1 /* a /* b */ ; DROP TABLE t */', Dialect::Postgres))->toContain('SELECT 1');
    // MySQL: the comment ends at the first */, so the DROP is real.
    guard('SELECT 1 /* a /* b */ ; DROP TABLE t */', Dialect::MySql);
})->throws(QueryRefused::class, 'one statement');

it('binds variables as parameters, in order, outside quotes only', function () {
    $compiled = StatementGuard::compile(
        "SELECT * FROM orders WHERE status = {status} AND day >= {since} AND note <> '{status}' OR status = {status}",
        Dialect::Postgres,
        ['status' => "paid' OR 1=1 --", 'since' => '2026-09-01'],
    );

    expect($compiled->sql)->toBe("SELECT * FROM orders WHERE status = ? AND day >= ? AND note <> '{status}' OR status = ?")
        ->and($compiled->bindings)->toBe(["paid' OR 1=1 --", '2026-09-01', "paid' OR 1=1 --"])
        ->and($compiled->variables)->toBe(['status', 'since']);
});

it('refuses a variable without a value', function () {
    StatementGuard::compile('SELECT {x}', Dialect::Sqlite, []);
})->throws(QueryRefused::class, 'No value for the variable {x}');

it('lists used and quoted variables', function () {
    expect(StatementGuard::variables("SELECT {a}, '{b}', {a} FROM t WHERE c = {c}", Dialect::MySql))
        ->toBe([['a', 'c'], ['b']]);
});

it('lets quoted names that look like keywords through', function () {
    expect(guard('SELECT "delete", "into" FROM "update"', Dialect::Postgres))->toContain('"update"')
        ->and(guard('SELECT `delete` FROM `update`', Dialect::MySql))->toContain('`update`')
        ->and(guard('SELECT [delete] FROM [update]', Dialect::Sqlite))->toContain('[update]');
});

it('allows optimizer hints and slow functions (the timeout handles those)', function () {
    expect(guard('SELECT /*+ MAX_EXECUTION_TIME(100) */ SLEEP(1)', Dialect::MySql))->toContain('SLEEP')
        ->and(guard('SELECT pg_sleep(1)', Dialect::Postgres))->toContain('pg_sleep');
});
