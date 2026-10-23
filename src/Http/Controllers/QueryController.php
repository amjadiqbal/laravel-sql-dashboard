<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http\Controllers;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Charts\Chart;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Models\Query;
use AmjadIqbal\SqlDashboard\Sql\StatementGuard;
use AmjadIqbal\SqlDashboard\Sql\Variables;
use AmjadIqbal\SqlDashboard\Support\Csv;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class QueryController extends Controller
{
    public function create(Request $request): View
    {
        return $this->editor($request, new Query(['chart' => 'auto', 'shared' => true, 'statement' => '']));
    }

    public function store(Request $request): View|RedirectResponse
    {
        return $this->save($request, new Query);
    }

    public function edit(Request $request, Query $query): View
    {
        $this->visible($request, $query);

        return $this->editor($request, $query);
    }

    public function update(Request $request, Query $query): View|RedirectResponse
    {
        $this->visible($request, $query);

        return $this->save($request, $query);
    }

    public function destroy(Request $request, Query $query): RedirectResponse
    {
        $this->visible($request, $query);
        $query->delete();

        return redirect()->route('sql-dashboard.home')->with('status', "Deleted the query “{$query->name}”.");
    }

    public function show(Request $request, Query $query): View
    {
        $this->visible($request, $query);
        $definitions = $query->definitions();
        $input = (array) $request->query('v', []);
        $canRun = $this->can($request, 'runSqlDashboardQueries');

        $missing = [];
        $error = null;
        $result = null;
        try {
            Variables::resolve($definitions, $input, $missing);
        } catch (QueryRefused $e) {
            $error = $e->getMessage();
        }
        if ($canRun && $missing === [] && $error === null) {
            [$result, $error] = $this->attempt(fn () => $this->runner->run($query->statement, $definitions, $input, Actor::fromRequest($request), 'web', $query->id));
        }

        return view('sql-dashboard::queries.show', [
            'query' => $query,
            'input' => $input,
            'missing' => $missing,
            'canRun' => $canRun,
            'canEdit' => $this->can($request, 'editSqlDashboardQueries'),
            'result' => $result,
            'error' => $error,
            ...$this->chart($result, $query->chart),
        ]);
    }

    public function export(Request $request, Query $query): StreamedResponse|RedirectResponse
    {
        $this->visible($request, $query);
        $input = (array) $request->query('v', []);
        [$result, $error] = $this->attempt(fn () => $this->runner->run($query->statement, $query->definitions(), $input, Actor::fromRequest($request), 'export', $query->id, Limits::fromConfig(export: true)));

        if (! $result instanceof Result) {
            return redirect()->route('sql-dashboard.queries.show', ['query' => $query, 'v' => $input])->with('error', $error);
        }

        $name = Str::slug($query->name) ?: 'query-'.$query->id;

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            Csv::write($out, $result);
            fclose($out);
        }, $name.'-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function save(Request $request, Query $query): View|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'statement' => ['required', 'string', 'max:65000'],
            'chart' => ['required', Rule::in(array_keys(Query::CHARTS))],
            'shared' => ['nullable', 'boolean'],
            'variables' => ['nullable', 'array'],
            'values' => ['nullable', 'array'],
            'intent' => ['nullable', Rule::in(['run', 'save'])],
        ]);

        $dialect = $this->dialect();
        $statement = (string) $data['statement'];
        $problems = [];
        $definitions = [];
        try {
            [$used] = StatementGuard::variables($statement, $dialect);
            $definitions = Variables::normalize((array) ($data['variables'] ?? []), $used);
            $problems = Variables::definitionErrors($definitions);
            // Structural check only: every guard rule, with placeholder values.
            StatementGuard::compile($statement, $dialect, array_fill_keys($used, ''));
        } catch (QueryRefused $e) {
            $problems['statement'] = $e->getMessage();
        }

        $query->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'statement' => $statement,
            'chart' => $data['chart'],
            'shared' => (bool) ($data['shared'] ?? false),
            'variables' => $definitions,
        ]);

        if (($data['intent'] ?? 'save') === 'run' || $problems !== []) {
            $result = null;
            $error = $problems['statement'] ?? null;
            $values = (array) ($data['values'] ?? []);
            // A run always goes through the Runner, so a refused statement is in the audit log too.
            if (($data['intent'] ?? 'save') === 'run') {
                $error = null;
                if (! $this->can($request, 'runSqlDashboardQueries')) {
                    $error = 'You can save queries but not run them.';
                } else {
                    [$result, $error] = $this->attempt(fn () => $this->runner->run($statement, $definitions, $values, Actor::fromRequest($request), 'editor', $query->exists ? $query->id : null));
                }
            }

            return $this->editor($request, $query, $result, $error, $problems, $values);
        }

        if (! $query->exists) {
            $query->fill($this->owner($request));
        }
        $query->save();

        return redirect()->route('sql-dashboard.queries.show', $query)->with('status', 'Saved.');
    }

    /**
     * @param  array<string, string>  $problems
     * @param  array<string, mixed>  $values
     */
    private function editor(Request $request, Query $query, ?Result $result = null, ?string $error = null, array $problems = [], array $values = []): View
    {
        $dialect = $this->dialect();
        $quoted = [];
        try {
            [, $quoted] = StatementGuard::variables((string) $query->statement, $dialect);
        } catch (QueryRefused) {
            // shown when saving
        }

        return view('sql-dashboard::queries.edit', [
            'query' => $query,
            'result' => $result,
            'error' => $error,
            'problems' => $problems,
            'values' => $values,
            'quoted' => $quoted,
            'dialect' => $dialect,
            'connection' => $this->runner->connectionName(),
            ...$this->chart($result, (string) $query->chart),
        ]);
    }

    /**
     * @param  callable(): Result  $run
     * @return array{0: ?Result, 1: ?string}
     */
    private function attempt(callable $run): array
    {
        try {
            return [$run(), null];
        } catch (QueryRefused|QueryFailed $e) {
            return [null, $e->getMessage()];
        }
    }

    /** @return array{chart: ?Chart, chartWhy: ?string} */
    private function chart(?Result $result, string $preferred): array
    {
        $why = null;
        $chart = $result ? Chart::pick($result, $preferred, $why) : null;

        return ['chart' => $chart, 'chartWhy' => $why];
    }
}
