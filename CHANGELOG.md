# Changelog

All notable changes to `laravel-sql-dashboard` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.2] - 2026-10-24

### Security
Found by an internal adversarial sweep (278 attempts on PostgreSQL 16, MySQL 8.4, MariaDB 11 and SQLite).
- PostgreSQL: a role that could execute another role's `SECURITY DEFINER` function was accepted. Such a
  function runs with its owner's powers, and the read-only transaction does not limit what it does outside
  the session: in the sweep, an admin-created function calling `dblink_exec` deleted a row. The connection
  check now refuses a role that can execute a `SECURITY DEFINER` function it does not own (outside
  `pg_catalog` and `information_schema`). Needs such a function to exist on the server; the dashboard user
  cannot create one.
- The statement check now refuses invisible or unusual space characters (byte order mark, zero-width
  space/joiner, no-break space) outside quotes, which could make it and the database read different words.
  No write got through this way: the servers rejected the statements.
- MariaDB `NEXT VALUE FOR sequence` is now refused by the statement check (MariaDB's read-only transaction
  already refused it).

### Changed
- README and code docs describe the statement check as a dialect-aware tokenizer (not a full SQL parser),
  and the connection check's scope (tables, views, materialized views, foreign tables and sequences).

## [0.1.1] - 2026-10-23

### Security
Found by an internal code review. Upgrade if anyone can write SQL in your dashboard.
- The statement check only recognised a function call when the function name was written bare. Writing the
  name in quotes (`"pg_advisory_lock"(1)`, `pg_catalog."pg_read_file"(...)`, `"dblink"(...)`,
  `` `GET_LOCK`(...) ``, `[load_extension](...)`) slipped past the deny-list. Quoted, bracketed,
  back-ticked and schema-qualified names are now checked by their real name, and PostgreSQL `U&"..."`
  escaped names are refused.
- The deny-list covers whole families: PostgreSQL server-file, large-object, replication, backend-signal
  and logical-decoding functions, `dblink` and `postgres_fdw`, MySQL wait/lock functions and UDF names.
- The connection check refuses PostgreSQL roles that are members of `pg_read_server_files`,
  `pg_write_server_files`, `pg_execute_server_program` or `pg_signal_backend`, can execute `dblink` or the
  server-file functions, or have `USAGE` on a foreign server, also on a standby; a superuser is refused even
  on a standby. MySQL users with `PROCESS` are now refused too (`FILE` and `SUPER` already were).
- After each query the dashboard releases any PostgreSQL advisory lock or MySQL `GET_LOCK` lock the session
  took, since those outlive the rolled-back transaction.
- SQLite queries run with SQLite's authorizer allowing reads only (PHP 8.5+), on top of the read-only file.

### Changed
- README: the security section now describes the checks as they are (a tokenizer, not a full SQL parser);
  the default gate is `app()->environment('local')`; `alerts.rows_in_alert` is documented; anyone with the
  edit gate can edit or delete shared queries, dashboards and checks.
- Docs and art: Slack alerts are marked untested (tested only against a faked HTTP client); the art says
  "mail alerts".

## [0.1.0] - 2026-10-23

First release.

### Added
- Saved SQL queries with a name, description and owner, shared with the team or private to their owner.
- Typed `{variables}`: text, whole number, number, date, date and time (with `today`, `yesterday`, `-7 days`
  style defaults), yes/no and one-of-a-list. Values are validated and bound as parameters.
- Result tables, CSV export with formula cells neutralised, and line, bar and pie charts picked from the
  result's shape (or chosen per query), drawn as SVG on the server.
- Dashboards of several queries with one shared variable form.
- Scheduled checks (every 5 minutes, hourly, daily) that fail on any row or on a threshold, with mail and
  Slack alerts when a check starts failing, passes again or errors. Registered with Laravel's scheduler.
- Audit log of every run, export, check and refused attempt; `sql-dashboard:prune-audit` keeps 90 days.
- Gates `viewSqlDashboard`, `runSqlDashboardQueries` and `editSqlDashboardQueries`, local-only by default;
  writing SQL always needs a signed-in user.
- Security: a dialect-aware statement allow-list (one SELECT or WITH, no writes, locks, files or side-effect
  functions), the database's own read-only mode (MySQL/MariaDB and PostgreSQL read-only transactions, SQLite
  opened read-only in a separate process), and a check that refuses any connection able to write.
- Statement timeouts, row limits and a size limit on MySQL, MariaDB, PostgreSQL and SQLite.
- `sql-dashboard:verify`, `sql-dashboard:run-checks` and an `about` entry.
