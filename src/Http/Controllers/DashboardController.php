<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http\Controllers;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Charts\Chart;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Models\Dashboard;
use AmjadIqbal\SqlDashboard\Models\Query;
use AmjadIqbal\SqlDashboard\Sql\Variables;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function show(Request $request, Dashboard $dashboard): View
    {
        $this->visible($request, $dashboard);
        $input = (array) $request->query('v', []);
        $canRun = $this->can($request, 'runSqlDashboardQueries');
        $actor = Actor::fromRequest($request);
        $userId = $this->userId($request);

        // One form for every query's variables; the first definition of a name wins.
        $definitions = [];
        $panels = [];
        foreach ($dashboard->queries as $query) {
            if (! $query->isVisibleTo($userId)) {
                continue;
            }
            $definitions += $query->definitions();
            $panel = ['query' => $query, 'result' => null, 'error' => null, 'chart' => null, 'missing' => []];
            if ($canRun) {
                try {
                    $missing = [];
                    Variables::resolve($query->definitions(), $input, $missing);
                    $panel['missing'] = $missing;
                    if ($missing === []) {
                        $panel['result'] = $this->runner->run($query->statement, $query->definitions(), $input, $actor, 'dashboard', $query->id);
                        $panel['chart'] = Chart::pick($panel['result'], $query->chart);
                    }
                } catch (QueryRefused|QueryFailed $e) {
                    $panel['error'] = $e->getMessage();
                }
            }
            $panels[] = $panel;
        }

        return view('sql-dashboard::dashboards.show', [
            'dashboard' => $dashboard,
            'panels' => $panels,
            'definitions' => $definitions,
            'input' => $input,
            'canRun' => $canRun,
            'canEdit' => $this->can($request, 'editSqlDashboardQueries'),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Dashboard(['shared' => true]));
    }

    public function edit(Request $request, Dashboard $dashboard): View
    {
        $this->visible($request, $dashboard);

        return $this->form($request, $dashboard);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Dashboard);
    }

    public function update(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->visible($request, $dashboard);

        return $this->save($request, $dashboard);
    }

    public function destroy(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->visible($request, $dashboard);
        $dashboard->delete();

        return redirect()->route('sql-dashboard.home')->with('status', "Deleted the dashboard “{$dashboard->name}”.");
    }

    private function form(Request $request, Dashboard $dashboard): View
    {
        return view('sql-dashboard::dashboards.edit', [
            'dashboard' => $dashboard,
            'queries' => Query::query()->visibleTo($this->userId($request))->orderBy('name')->get(),
            'selected' => $dashboard->exists ? $dashboard->queries->pluck('id')->all() : [],
        ]);
    }

    private function save(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'shared' => ['nullable', 'boolean'],
            'queries' => ['nullable', 'array'],
            'queries.*' => ['integer'],
        ]);

        $allowed = Query::query()->visibleTo($this->userId($request))->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', (array) ($data['queries'] ?? [])), $allowed));

        $dashboard->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'shared' => (bool) ($data['shared'] ?? false)]);
        if (! $dashboard->exists) {
            $dashboard->fill($this->owner($request));
        }
        $dashboard->save();
        $dashboard->queries()->sync(collect($ids)->mapWithKeys(fn (int $id, int $i) => [$id => ['position' => $i]])->all());

        return redirect()->route('sql-dashboard.dashboards.show', $dashboard)->with('status', 'Saved.');
    }
}
