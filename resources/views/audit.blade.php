@extends('sql-dashboard::layout', ['section' => 'audit'])
@section('title', 'Audit log')
@section('content')
    <h1>Audit log</h1>
    <p class="sub">Every run, export, check and refused attempt, newest first.</p>
    <form method="get" class="row" style="margin-bottom:12px">
        <select name="status" aria-label="Status" style="width:auto"><option value="">any status</option>@foreach (\AmjadIqbal\SqlDashboard\Http\Controllers\AuditController::STATUSES as $s)<option @selected($status === $s)>{{ $s }}</option>@endforeach</select>
        <select name="source" aria-label="Source" style="width:auto"><option value="">any source</option>@foreach (\AmjadIqbal\SqlDashboard\Http\Controllers\AuditController::SOURCES as $s)<option @selected($source === $s)>{{ $s }}</option>@endforeach</select>
        <input type="search" name="user" value="{{ $user }}" placeholder="User" aria-label="User" style="width:180px">
        <button class="btn" type="submit">Filter</button>
    </form>
    <div class="table-wrap" style="max-height:none">
        <table class="data">
            <thead><tr><th>When</th><th>Who</th><th>Source</th><th>Status</th><th>Query</th><th>Rows</th><th>ms</th><th>Statement</th></tr></thead>
            <tbody>
            @forelse ($entries as $e)
                <tr>
                    <td title="{{ $e->created_at }}" style="white-space:nowrap">{{ $e->created_at?->diffForHumans() }}</td>
                    <td>{{ $e->user_label }}@if ($e->ip)<div class="hint">{{ $e->ip }}</div>@endif</td>
                    <td>{{ $e->source }}</td>
                    <td><span class="badge {{ $e->status }}">{{ $e->status }}</span></td>
                    <td>@if ($e->query_id)<a href="{{ route('sql-dashboard.queries.show', $e->query_id) }}">#{{ $e->query_id }}</a>@endif</td>
                    <td class="num">{{ $e->row_count }}</td>
                    <td class="num">{{ $e->duration_ms }}</td>
                    <td style="min-width:260px">
                        <code style="font-size:12.5px">{{ \Illuminate\Support\Str::limit($e->statement, 200) }}</code>
                        @if ($e->variables)<div class="hint">{{ json_encode($e->variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</div>@endif
                        @if ($e->message)<div class="hint">{{ $e->message }}</div>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Nothing recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pager">
        @if ($entries->previousPageUrl())<a class="btn small" href="{{ $entries->previousPageUrl() }}">Newer</a>@endif
        @if ($entries->nextPageUrl())<a class="btn small" href="{{ $entries->nextPageUrl() }}">Older</a>@endif
    </div>
@endsection
