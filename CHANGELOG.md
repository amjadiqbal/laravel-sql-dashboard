# Changelog

All notable changes to `laravel-sql-dashboard` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Docs and art: Slack alerts are marked untested (tested only against a faked HTTP client); the art now says
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
