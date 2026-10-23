@extends('sql-dashboard::layout', ['section' => 'home'])
@section('title', 'Queries')
@section('content')
    @if (! $connection)
        <div class="flash error">
            <b>No read-only connection is set up, so nothing can run yet.</b><br>
            Add a database connection whose user can only read (see the README), then set <code>SQL_DASHBOARD_CONNECTION</code> to its name and run <code>php artisan sql-dashboard:verify</code>.
        </div>
    @endif

    <div class="row spread" style="margin-bottom:14px">
        <div>
            <h1>Queries</h1>
            <p class="sub" style="margin:0">Saved read-only SQL on the <b>{{ $connection ?? '(not set)' }}</b> connection.</p>
        </div>
        <form method="get" action="{{ route('sql-dashboard.home') }}" class="row">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search queries" aria-label="Search queries" style="width:220px">
            @if ($canEdit)<a class="btn primary" href="{{ route('sql-dashboard.queries.create') }}">New query</a>@endif
        </form>
    </div>

    <div class="grid2" style="grid-template-columns: minmax(0, 2fr) minmax(0, 1fr)">
        <div class="list">
            @forelse ($queries as $q)
                <div class="item">
                    <div style="min-width:0">
                        <a href="{{ route('sql-dashboard.queries.show', $q) }}"><b>{{ $q->name }}</b></a>
                        @if (! $q->shared)<span class="badge muted">private</span>@endif
                        @if ($q->description)<div class="meta">{{ \Illuminate\Support\Str::limit($q->description, 140) }}</div>@endif
                    </div>
                    <span class="meta">{{ $q->owner_label }} · {{ $q->updated_at?->diffForHumans() }}</span>
                </div>
            @empty
                <div class="empty">{{ $search !== '' ? 'No query matches “'.$search.'”.' : 'No saved queries yet.' }}</div>
            @endforelse
        </div>
        <div>
            <div class="row spread"><h2 style="margin:0">Dashboards</h2>@if ($canEdit)<a class="btn small" href="{{ route('sql-dashboard.dashboards.create') }}">New</a>@endif</div>
            <div class="list" style="margin:8px 0 18px">
                @forelse ($dashboards as $d)
                    <div class="item"><a href="{{ route('sql-dashboard.dashboards.show', $d) }}"><b>{{ $d->name }}</b></a><span class="meta">{{ $d->queries_count }} {{ $d->queries_count === 1 ? 'query' : 'queries' }}</span></div>
                @empty
                    <div class="empty">No dashboards yet.</div>
                @endforelse
            </div>
            <div class="row spread"><h2 style="margin:0">Checks</h2>@if ($canEdit)<a class="btn small" href="{{ route('sql-dashboard.checks.index') }}">All</a>@endif</div>
            <div class="list" style="margin-top:8px">
                @forelse ($checks as $c)
                    <div class="item"><span>{{ $c->name }}</span><span class="badge {{ $c->state }}">{{ $c->state }}</span></div>
                @empty
                    <div class="empty">No checks yet.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
