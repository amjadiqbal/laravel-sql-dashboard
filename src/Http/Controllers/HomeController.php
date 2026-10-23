<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http\Controllers;

use AmjadIqbal\SqlDashboard\Models\Check;
use AmjadIqbal\SqlDashboard\Models\Dashboard;
use AmjadIqbal\SqlDashboard\Models\Query;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $userId = $this->userId($request);
        $search = trim((string) $request->query('q', ''));

        $queries = Query::query()->visibleTo($userId)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')))
            ->orderBy('name')->get();

        return view('sql-dashboard::home', [
            'queries' => $queries,
            'dashboards' => Dashboard::query()->visibleTo($userId)->withCount('queries')->orderBy('name')->get(),
            'checks' => Check::query()->whereIn('query_id', Query::query()->visibleTo($userId)->select('id'))->get(),
            'search' => $search,
            'connection' => $this->runner->connectionName(),
            'canEdit' => $this->can($request, 'editSqlDashboardQueries'),
        ]);
    }
}
