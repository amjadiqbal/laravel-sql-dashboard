@extends('sql-dashboard::layout', ['section' => 'home'])
@section('title', $dashboard->exists ? 'Edit '.$dashboard->name : 'New dashboard')
@section('content')
    <h1>{{ $dashboard->exists ? 'Edit dashboard' : 'New dashboard' }}</h1>
    <p class="sub">Pick the queries to show, in order. Variables with the same name share one input.</p>
    <form method="post" action="{{ $dashboard->exists ? route('sql-dashboard.dashboards.update', $dashboard) : route('sql-dashboard.dashboards.store') }}" class="card">
        @csrf
        @if ($dashboard->exists) @method('PUT') @endif
        <div class="grid2">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $dashboard->name) }}" required maxlength="200">
                @error('name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input id="description" type="text" name="description" value="{{ old('description', $dashboard->description) }}">
            </div>
        </div>
        <div class="field">
            <label>Queries (shown on the dashboard in this order)</label>
            @php($ordered = $queries->sortBy(fn ($q) => ($i = array_search($q->id, $selected, true)) === false ? PHP_INT_MAX : $i))
            @forelse ($ordered as $q)
                <label class="check"><input type="checkbox" name="queries[]" value="{{ $q->id }}" @checked(in_array($q->id, old('queries', $selected)))> {{ $q->name }}</label>
            @empty
                <p class="hint">Save a query first.</p>
            @endforelse
        </div>
        <label class="check"><input type="hidden" name="shared" value="0"><input type="checkbox" name="shared" value="1" @checked(old('shared', $dashboard->shared))> Share with the team</label>
        <div class="row" style="margin-top:14px"><button class="btn primary" type="submit">Save</button></div>
    </form>
    @if ($dashboard->exists)
        <form method="post" action="{{ route('sql-dashboard.dashboards.destroy', $dashboard) }}" data-confirm="Delete this dashboard? Its queries stay.">
            @csrf @method('DELETE')
            <button class="btn danger" type="submit">Delete dashboard</button>
        </form>
    @endif
@endsection
