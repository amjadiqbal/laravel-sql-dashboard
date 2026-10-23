@extends('sql-dashboard::layout', ['section' => $query->exists ? 'home' : 'new'])
@section('title', $query->exists ? 'Edit '.$query->name : 'New query')
@section('content')
    <h1>{{ $query->exists ? 'Edit query' : 'New query' }}</h1>
    <p class="sub">Runs on <b>{{ $connection ?? '(no connection set)' }}</b> ({{ $dialect->label() }}). One <code>SELECT</code> or <code>WITH</code> statement; use <code>{name}</code> for variables, they are bound as parameters. Ctrl/⌘ + Enter runs it.</p>

    <form method="post" action="{{ $query->exists ? route('sql-dashboard.queries.update', $query) : route('sql-dashboard.queries.store') }}">
        @csrf
        @if ($query->exists) @method('PUT') @endif

        <div class="grid2">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $query->name) }}" required maxlength="200">
                @error('name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input id="description" type="text" name="description" value="{{ old('description', $query->description) }}" maxlength="2000">
            </div>
        </div>

        <div class="field">
            <label for="statement">SQL</label>
            <textarea id="statement" class="sql" name="statement" spellcheck="false" required>{{ old('statement', $query->statement) }}</textarea>
            @error('statement')<div class="err">{{ $message }}</div>@enderror
            @isset($problems['statement'])<div class="err">{{ $problems['statement'] }}</div>@endisset
            @if ($quoted !== [])
                <div class="hint">Inside quotes, {{ implode(', ', array_map(fn ($n) => '{'.$n.'}', $quoted)) }} is plain text, not a variable. Write <code>{{ '{'.$quoted[0].'}' }}</code> without quotes to bind it.</div>
            @endif
        </div>

        @if (($query->variables ?? []) !== [])
            <div class="card">
                <h2>Variables</h2>
                <div class="vars">
                    @foreach ($query->variables as $name => $d)
                        <div>
                            <label>{{ '{'.$name.'}' }}</label>
                            <select name="variables[{{ $name }}][type]" aria-label="Type of {{ $name }}">
                                @foreach (\AmjadIqbal\SqlDashboard\Sql\VariableType::cases() as $type)
                                    <option value="{{ $type->value }}" @selected($d['type'] === $type->value)>{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="variables[{{ $name }}][label]" value="{{ $d['label'] }}" placeholder="Label" aria-label="Label of {{ $name }}" style="margin-top:6px">
                            <input type="text" name="variables[{{ $name }}][default]" value="{{ $d['default'] }}" placeholder="Default (e.g. -7 days)" aria-label="Default of {{ $name }}" style="margin-top:6px">
                            <input type="text" name="variables[{{ $name }}][options]" value="{{ implode(', ', $d['options']) }}" placeholder="Options, comma separated (lists)" aria-label="Options of {{ $name }}" style="margin-top:6px">
                            <input type="text" name="values[{{ $name }}]" value="{{ $values[$name] ?? '' }}" placeholder="Value for this run" aria-label="Value of {{ $name }} for this run" style="margin-top:6px">
                            @isset($problems[$name])<div class="err">{{ $problems[$name] }}</div>@endisset
                        </div>
                    @endforeach
                </div>
                <p class="hint">Variables are found in the SQL when you run or save. Dates accept 2026-01-31, now, today, yesterday or -7 days.</p>
            </div>
        @endif

        <div class="row" style="margin-bottom:16px">
            <div style="min-width:180px">
                <label for="chart">Chart</label>
                <select id="chart" name="chart">
                    @foreach (\AmjadIqbal\SqlDashboard\Models\Query::CHARTS as $value => $label)
                        <option value="{{ $value }}" @selected(old('chart', $query->chart) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <label class="check" style="margin-top:18px"><input type="hidden" name="shared" value="0"><input type="checkbox" name="shared" value="1" @checked(old('shared', $query->shared))> Share with the team</label>
        </div>

        <div class="row">
            <button class="btn" type="submit" name="intent" value="run">Run</button>
            <button class="btn primary" type="submit" name="intent" value="save">Save</button>
            @if ($query->exists)<a class="btn" href="{{ route('sql-dashboard.queries.show', $query) }}">Cancel</a>@endif
        </div>
    </form>

    @if ($error)
        <div class="flash error" role="alert" style="margin-top:16px"><b>Not run.</b> {{ $error }}</div>
    @endif
    @if ($result)
        <div class="card" style="margin-top:16px">@include('sql-dashboard::partials.result')</div>
    @endif

    @if ($query->exists)
        <form method="post" action="{{ route('sql-dashboard.queries.destroy', $query) }}" data-confirm="Delete this query, its checks and its place on dashboards?" style="margin-top:28px">
            @csrf @method('DELETE')
            <button class="btn danger" type="submit">Delete query</button>
        </form>
    @endif
@endsection
