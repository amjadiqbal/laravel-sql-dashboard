<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http\Controllers;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Checks\CheckRunner;
use AmjadIqbal\SqlDashboard\Models\Check;
use AmjadIqbal\SqlDashboard\Models\Query;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CheckController extends Controller
{
    public function index(Request $request): View
    {
        return view('sql-dashboard::checks.index', [
            'checks' => Check::query()->with('savedQuery')->whereIn('query_id', Query::query()->visibleTo($this->userId($request))->select('id'))->orderBy('name')->get(),
            'slack' => (bool) config('sql-dashboard.alerts.slack_webhook_url'),
            'scheduled' => (bool) config('sql-dashboard.schedule'),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Check(['query_id' => $request->integer('query') ?: null, 'interval' => 'hourly', 'condition' => 'rows', 'operator' => '>']));
    }

    public function edit(Request $request, Check $check): View
    {
        $this->visible($request, $check->savedQuery);

        return $this->form($request, $check);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Check);
    }

    public function update(Request $request, Check $check): RedirectResponse
    {
        $this->visible($request, $check->savedQuery);

        return $this->save($request, $check);
    }

    public function destroy(Request $request, Check $check): RedirectResponse
    {
        $this->visible($request, $check->savedQuery);
        $check->delete();

        return redirect()->route('sql-dashboard.checks.index')->with('status', "Deleted the check “{$check->name}”.");
    }

    public function run(Request $request, Check $check, CheckRunner $runner): RedirectResponse
    {
        $this->visible($request, $check->savedQuery);
        $outcome = $runner->run($check, Actor::fromRequest($request));

        return redirect()->route('sql-dashboard.checks.index')->with('status', "“{$check->name}” is {$outcome['state']}: {$outcome['message']}".($outcome['alerted'] ? ' Alert sent.' : ''));
    }

    private function form(Request $request, Check $check): View
    {
        return view('sql-dashboard::checks.edit', [
            'check' => $check,
            'queries' => Query::query()->visibleTo($this->userId($request))->orderBy('name')->get(),
            'slack' => (bool) config('sql-dashboard.alerts.slack_webhook_url'),
            'domains' => (array) config('sql-dashboard.alerts.mail_domains', []),
        ]);
    }

    private function save(Request $request, Check $check): RedirectResponse
    {
        $visible = Query::query()->visibleTo($this->userId($request))->pluck('id')->all();
        $data = $request->validate([
            'query_id' => ['required', 'integer', Rule::in($visible)],
            'name' => ['required', 'string', 'max:200'],
            'interval' => ['required', Rule::in(array_keys((array) config('sql-dashboard.intervals')))],
            'condition' => ['required', Rule::in(array_keys(Check::CONDITIONS))],
            'threshold_column' => ['nullable', 'required_if:condition,threshold', 'string', 'max:200'],
            'operator' => ['nullable', 'required_if:condition,threshold', Rule::in(array_keys(Check::OPERATORS))],
            'threshold_value' => ['nullable', 'required_if:condition,threshold', 'numeric'],
            'mail_to' => ['nullable', 'string', 'max:2000'],
            'slack' => ['nullable', 'boolean'],
        ]);

        validator($data)->after(function (Validator $v) use ($data): void {
            $probe = new Check(['mail_to' => $data['mail_to'] ?? '']);
            foreach ($probe->recipients() as $address) {
                if (! CheckRunner::allowedRecipient($address)) {
                    $v->errors()->add('mail_to', "{$address} is not an allowed alert address.");
                }
            }
            $query = Query::query()->find($data['query_id']);
            foreach ($query?->definitions() ?? [] as $name => $definition) {
                if ($definition['default'] === null) {
                    $v->errors()->add('query_id', "The query's {{$name}} variable needs a default value: a check has nobody to type it in.");
                }
            }
        })->validate();

        $check->fill([
            ...$data,
            'slack' => (bool) ($data['slack'] ?? false),
            'threshold_column' => $data['condition'] === 'threshold' ? $data['threshold_column'] : null,
            'operator' => $data['condition'] === 'threshold' ? $data['operator'] : null,
            'threshold_value' => $data['condition'] === 'threshold' ? (string) $data['threshold_value'] : null,
        ]);
        if (! $check->exists) {
            $check->fill($this->owner($request));
        }
        if ($check->isDirty(['query_id', 'condition', 'threshold_column', 'operator', 'threshold_value'])) {
            $check->state = 'unknown';
        }
        $check->save();

        return redirect()->route('sql-dashboard.checks.index')->with('status', 'Saved.');
    }
}
