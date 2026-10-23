@extends('sql-dashboard::layout', ['section' => 'checks'])
@section('title', $check->exists ? 'Edit '.$check->name : 'New check')
@section('content')
    <h1>{{ $check->exists ? 'Edit check' : 'New check' }}</h1>
    <p class="sub">Runs on a schedule with each variable's default value. You get an alert when it starts failing, passes again, or errors.</p>
    <form method="post" action="{{ $check->exists ? route('sql-dashboard.checks.update', $check) : route('sql-dashboard.checks.store') }}" class="card">
        @csrf
        @if ($check->exists) @method('PUT') @endif
        <div class="grid2">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $check->name) }}" required maxlength="200">
                @error('name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="query_id">Query</label>
                <select id="query_id" name="query_id">
                    @foreach ($queries as $q)
                        <option value="{{ $q->id }}" @selected((int) old('query_id', $check->query_id) === $q->id)>{{ $q->name }}</option>
                    @endforeach
                </select>
                @error('query_id')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="interval">How often</label>
                <select id="interval" name="interval">
                    @foreach (array_keys((array) config('sql-dashboard.intervals')) as $interval)
                        <option value="{{ $interval }}" @selected(old('interval', $check->interval) === $interval)>{{ str_replace('_', ' ', $interval) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="condition">Fails when</label>
                <select id="condition" name="condition">
                    @foreach (\AmjadIqbal\SqlDashboard\Models\Check::CONDITIONS as $value => $label)
                        <option value="{{ $value }}" @selected(old('condition', $check->condition) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <fieldset class="card" style="background:var(--bg)">
            <legend class="hint">Threshold (only for “crosses a threshold”): the first row's value in this column</legend>
            <div class="vars">
                <div><label for="threshold_column">Column</label><input id="threshold_column" type="text" name="threshold_column" value="{{ old('threshold_column', $check->threshold_column) }}">@error('threshold_column')<div class="err">{{ $message }}</div>@enderror</div>
                <div><label for="operator">is</label>
                    <select id="operator" name="operator">
                        @foreach (\AmjadIqbal\SqlDashboard\Models\Check::OPERATORS as $op => $label)
                            <option value="{{ $op }}" @selected(old('operator', $check->operator) === $op)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label for="threshold_value">Value</label><input id="threshold_value" type="number" step="any" name="threshold_value" value="{{ old('threshold_value', $check->threshold_value) }}">@error('threshold_value')<div class="err">{{ $message }}</div>@enderror</div>
            </div>
        </fieldset>
        <div class="field">
            <label for="mail_to">Mail alerts to</label>
            <input id="mail_to" type="text" name="mail_to" value="{{ old('mail_to', $check->mail_to) }}" placeholder="ops@example.com, data@example.com">
            @if ($domains !== [])<div class="hint">Only addresses at {{ implode(', ', $domains) }}.</div>@endif
            @error('mail_to')<div class="err">{{ $message }}</div>@enderror
        </div>
        <label class="check"><input type="hidden" name="slack" value="0"><input type="checkbox" name="slack" value="1" @checked(old('slack', $check->slack))> Post to Slack {{ $slack ? '' : '(set SQL_DASHBOARD_SLACK_WEBHOOK_URL first)' }}</label>
        <div class="row" style="margin-top:14px"><button class="btn primary" type="submit">Save</button></div>
    </form>
    @if ($check->exists)
        <form method="post" action="{{ route('sql-dashboard.checks.destroy', $check) }}" data-confirm="Delete this check?">
            @csrf @method('DELETE')
            <button class="btn danger" type="submit">Delete check</button>
        </form>
    @endif
@endsection
