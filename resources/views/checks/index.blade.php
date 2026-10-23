@extends('sql-dashboard::layout', ['section' => 'checks'])
@section('title', 'Checks')
@section('content')
    <div class="row spread" style="margin-bottom:12px">
        <div>
            <h1>Checks</h1>
            <p class="sub" style="margin:0">Scheduled queries that alert when they return rows or cross a threshold.</p>
        </div>
        <a class="btn primary" href="{{ route('sql-dashboard.checks.create') }}">New check</a>
    </div>
    @if (! $scheduled)
        <div class="flash notice">Scheduling is off (<code>sql-dashboard.schedule</code>): checks only run with <code>php artisan sql-dashboard:run-checks</code> or the Run button.</div>
    @endif
    <div class="list">
        @forelse ($checks as $c)
            <div class="item">
                <div style="min-width:0">
                    <span class="badge {{ $c->state }}">{{ $c->state }}</span>
                    <b>{{ $c->name }}</b>
                    <div class="meta">
                        <a href="{{ route('sql-dashboard.queries.show', $c->query_id) }}">{{ $c->savedQuery->name }}</a> ·
                        {{ str_replace('_', ' ', $c->interval) }} · fails on {{ $c->describe() }}
                        @if ($c->recipients() !== []) · mail {{ implode(', ', $c->recipients()) }} @endif
                        @if ($c->slack) · Slack{{ $slack ? '' : ' (no webhook set)' }} @endif
                    </div>
                    @if ($c->last_run_at)
                        <div class="meta">Last run {{ $c->last_run_at->diffForHumans() }}: {{ $c->last_message }}</div>
                    @endif
                </div>
                <div class="row">
                    <form method="post" action="{{ route('sql-dashboard.checks.run', $c) }}">@csrf<button class="btn small" type="submit">Run now</button></form>
                    <a class="btn small" href="{{ route('sql-dashboard.checks.edit', $c) }}">Edit</a>
                </div>
            </div>
        @empty
            <div class="empty">No checks yet. Open a query and press “Add check”.</div>
        @endforelse
    </div>
@endsection
