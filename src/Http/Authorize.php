<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: sql-dashboard.authorize:<ability>. Writing SQL
 * (editSqlDashboardQueries) also needs a signed-in user, whatever the gate
 * says: raw SQL never comes from a guest.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next, string $ability = 'viewSqlDashboard'): Response
    {
        abort_unless(Gate::check($ability, [$request]), 403, 'You are not allowed to use the SQL dashboard here.');

        if ($ability === 'editSqlDashboardQueries' && $request->user() === null) {
            abort(403, 'Sign in to write SQL.');
        }

        return $next($request);
    }
}
