@extends('sql-dashboard::layout', ['section' => 'home'])
@section('title', $query->name)
@section('content')
    <div class="row spread" style="margin-bottom:12px; align-items:flex-start">
        <div style="min-width:0">
            <h1>{{ $query->name }} @if (! $query->shared)<span class="badge muted">private</span>@endif</h1>
            <p class="sub" style="margin:0">{{ $query->description }} @if ($query->owner_label)<span>· by {{ $query->owner_label }}</span>@endif</p>
        </div>
        <div class="row">
            @if ($canRun && $result)
                <a class="btn" href="{{ route('sql-dashboard.queries.export', ['query' => $query, 'v' => $input]) }}">Download CSV</a>
            @endif
            @if ($canEdit)
                <a class="btn" href="{{ route('sql-dashboard.checks.create', ['query' => $query->id]) }}">Add check</a>
                <a class="btn" href="{{ route('sql-dashboard.queries.edit', $query) }}">Edit</a>
            @endif
        </div>
    </div>

    @include('sql-dashboard::partials.variables', ['definitions' => $query->definitions(), 'action' => route('sql-dashboard.queries.show', $query)])

    @if (! $canRun)
        <div class="flash notice">You can see this query but not run it.</div>
    @elseif ($error)
        <div class="flash error" role="alert"><b>Not run.</b> {{ $error }}</div>
    @elseif ($missing !== [])
        <div class="flash notice">Fill in the variables above and press Run.</div>
    @endif

    @if ($result)
        <div class="card">@include('sql-dashboard::partials.result')</div>
    @endif

    <details class="card">
        <summary>SQL</summary>
        <pre class="sql" style="margin-top:10px">{{ $query->statement }}</pre>
    </details>
@endsection
