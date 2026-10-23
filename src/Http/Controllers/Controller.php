<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http\Controllers;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Models\Dashboard;
use AmjadIqbal\SqlDashboard\Models\Query;
use AmjadIqbal\SqlDashboard\Sql\Dialect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** @internal */
abstract class Controller
{
    public function __construct(protected readonly Runner $runner) {}

    protected function userId(Request $request): ?string
    {
        return Actor::id($request->user());
    }

    protected function can(Request $request, string $ability): bool
    {
        if ($ability === 'editSqlDashboardQueries' && $request->user() === null) {
            return false;
        }

        return Gate::check($ability, [$request]);
    }

    /** Private queries and dashboards of other people do not exist as far as you can tell. */
    protected function visible(Request $request, Query|Dashboard $model): void
    {
        abort_unless($model->isVisibleTo($this->userId($request)), 404);
    }

    protected function dialect(): Dialect
    {
        $name = $this->runner->connectionName();
        $driver = $name === null ? null : config("database.connections.{$name}.driver");

        return is_string($driver) ? (Dialect::tryFrom($driver === 'mariadb' ? 'mysql' : $driver) ?? Dialect::MySql) : Dialect::MySql;
    }

    /** @return array<string, mixed> */
    protected function owner(Request $request): array
    {
        return ['owner_id' => $this->userId($request), 'owner_label' => Actor::label($request->user())];
    }
}
