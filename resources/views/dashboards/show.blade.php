@extends('sql-dashboard::layout', ['section' => 'home'])
@section('title', $dashboard->name)
@section('content')
    <div class="row spread" style="margin-bottom:12px; align-items:flex-start">
        <div style="min-width:0">
            <h1>{{ $dashboard->name }} @if (! $dashboard->shared)<span class="badge muted">private</span>@endif</h1>
            @if ($dashboard->description)<p class="sub" style="margin:0">{{ $dashboard->description }}</p>@endif
        </div>
        @if ($canEdit)<a class="btn" href="{{ route('sql-dashboard.dashboards.edit', $dashboard) }}">Edit</a>@endif
    </div>

    @include('sql-dashboard::partials.variables', ['action' => route('sql-dashboard.dashboards.show', $dashboard), 'missing' => []])

    @if (! $canRun)
        <div class="flash notice">You can see this dashboard but not run its queries.</div>
    @endif

    <div class="grid2">
        @forelse ($panels as $panel)
            <section class="card">
                <h2><a href="{{ route('sql-dashboard.queries.show', ['query' => $panel['query'], 'v' => $input]) }}">{{ $panel['query']->name }}</a></h2>
                @if ($panel['error'])
                    <div class="err">{{ $panel['error'] }}</div>
                @elseif ($panel['missing'] !== [])
                    <p class="hint">Needs {{ implode(', ', array_map(fn ($n) => '{'.$n.'}', $panel['missing'])) }}.</p>
                @elseif ($panel['result'])
                    @include('sql-dashboard::partials.result', ['result' => $panel['result'], 'chart' => $panel['chart'], 'chartWhy' => null, 'rowsShown' => $panel['chart'] ? 10 : 50, 'maxHeight' => 320])
                @endif
            </section>
        @empty
            <div class="card empty">This dashboard has no queries yet.</div>
        @endforelse
    </div>
@endsection
