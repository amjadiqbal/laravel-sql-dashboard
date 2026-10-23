<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'SQL Dashboard') · SQL Dashboard</title>
    <style>
        :root {
            --bg: #F8FAFC; --panel: #FFFFFF; --border: #E2E8F0; --text: #0F172A; --muted: #64748B; --soft: #F1F5F9;
            --accent: #059669; --accent-text: #047857; --accent-soft: #ECFDF5; --on-accent: #FFFFFF;
            --code-bg: #0F172A; --code-text: #E2E8F0; --code-accent: #6EE7B7;
            --critical: #DC2626; --critical-bg: #FEE2E2; --notice: #0284C7; --notice-bg: #E0F2FE; --ok: #16A34A; --ok-bg: #DCFCE7;
            --grid: #E2E8F0; --tick: #64748B;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0B1220; --panel: #111C33; --border: #24324F; --text: #F8FAFC; --muted: #94A3B8; --soft: #16213A;
                --accent: #34D399; --accent-text: #6EE7B7; --accent-soft: #16213A; --on-accent: #052E21;
                --code-bg: #0B1220; --code-text: #E2E8F0; --code-accent: #6EE7B7;
                /* Solid colours only: on dark, translucent warm fills read as brown. Badges use a neutral fill and coloured text. */
                --critical: #F87171; --critical-bg: #16213A; --notice: #38BDF8; --notice-bg: #16213A; --ok: #4ADE80; --ok-bg: #16213A;
                --grid: #24324F; --tick: #94A3B8;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; }
        a { color: var(--accent-text); }
        code, pre, textarea.sql, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 22px 16px 56px; }
        header.top { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text); }
        .brand b { font-size: 19px; letter-spacing: -0.01em; }
        nav.main { display: flex; flex-wrap: wrap; gap: 6px; }
        nav.main a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--panel); color: var(--text); text-decoration: none; font-size: 14px; }
        nav.main a.on { border-color: var(--accent); color: var(--accent-text); font-weight: 600; }
        h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -0.01em; word-break: break-word; }
        h2 { font-size: 16px; margin: 0 0 8px; }
        .sub { color: var(--muted); font-size: 13.5px; margin: 0 0 16px; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .spread { justify-content: space-between; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; min-width: 0; }
        .grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .btn { display: inline-block; padding: 7px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--text); text-decoration: none; font-size: 14px; cursor: pointer; font-family: inherit; line-height: 1.4; }
        .btn:hover { border-color: var(--accent); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); font-weight: 600; }
        .btn.danger { color: var(--critical); }
        .btn.small { padding: 3px 10px; font-size: 13px; }
        .flash { border: 1px solid var(--border); border-left: 4px solid var(--accent); background: var(--panel); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; font-size: 14px; }
        .flash.error { border-left-color: var(--critical); }
        .flash.notice { border-left-color: var(--notice); }
        .badge { display: inline-block; padding: 1px 9px; border-radius: 999px; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; border: 1px solid currentColor; white-space: nowrap; }
        .badge.ok, .badge.passing { color: var(--ok); background: var(--ok-bg); }
        .badge.refused, .badge.failing, .badge.failed, .badge.timeout, .badge.error { color: var(--critical); background: var(--critical-bg); }
        .badge.unknown, .badge.muted { color: var(--muted); background: var(--soft); }
        .badge.shared { color: var(--accent-text); background: var(--accent-soft); }
        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; font-weight: 600; }
        input[type=text], input[type=number], input[type=date], input[type=datetime-local], input[type=search], select, textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; }
        textarea.sql { min-height: 220px; font-size: 13.5px; line-height: 1.5; tab-size: 4; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .field { margin-bottom: 12px; }
        .check { display: flex; gap: 8px; align-items: center; font-size: 14px; color: var(--text); font-weight: 400; }
        .vars { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px 12px; align-items: end; }
        .err { color: var(--critical); font-size: 13px; margin-top: 4px; }
        .hint { color: var(--muted); font-size: 12.5px; margin-top: 4px; }
        pre.sql { margin: 0; background: var(--code-bg); color: var(--code-text); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; overflow-x: auto; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
        .table-wrap { overflow: auto; max-height: 560px; border: 1px solid var(--border); border-radius: 10px; }
        table.data { border-collapse: collapse; width: 100%; font-size: 13.5px; }
        table.data th { position: sticky; top: 0; background: var(--soft); text-align: left; font-weight: 600; padding: 7px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        table.data td { padding: 6px 10px; border-top: 1px solid var(--border); vertical-align: top; max-width: 420px; overflow-wrap: anywhere; }
        table.data td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.data td.null { color: var(--muted); font-style: italic; }
        .list { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; }
        .list .item { padding: 11px 16px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .list .item:first-child { border-top: 0; }
        .list .item .meta { color: var(--muted); font-size: 13px; }
        .empty { text-align: center; padding: 26px 16px; color: var(--muted); }
        svg.chart { width: 100%; height: auto; display: block; }
        svg.chart text.tick { fill: var(--tick); font-size: 11px; }
        svg.chart line.grid { stroke: var(--grid); stroke-width: 1; }
        svg.chart line.axis { stroke: var(--tick); stroke-width: 1; }
        svg.chart circle.grid { fill: none; stroke: var(--grid); }
        svg.chart.pie { max-width: 280px; }
        svg.chart .slice { stroke: var(--panel); stroke-width: 2; }
        .pie-wrap { display: flex; flex-wrap: wrap; gap: 16px 28px; align-items: center; }
        ul.legend { list-style: none; padding: 0; margin: 8px 0 0; display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; }
        .pie-wrap ul.legend { flex-direction: column; }
        ul.legend i { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
        ul.legend span { color: var(--muted); }
        .stats { color: var(--muted); font-size: 13px; }
        details summary { cursor: pointer; color: var(--muted); font-size: 13.5px; }
        footer { margin-top: 34px; color: var(--muted); font-size: 13px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }
        .pagination nav, .pager { display: flex; gap: 10px; margin-top: 12px; font-size: 14px; }
        @media (max-width: 760px) { .grid2 { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <a class="brand" href="{{ route('sql-dashboard.home') }}">
            <svg width="34" height="34" viewBox="0 0 512 512" aria-hidden="true">
                <rect width="512" height="512" rx="112" fill="#0F172A" stroke="#334155" stroke-width="16"/>
                <rect x="56" y="64" width="400" height="128" rx="26" fill="#1E293B"/>
                <rect x="88" y="98" width="92" height="20" rx="10" fill="#34D399"/>
                <rect x="196" y="98" width="136" height="20" rx="10" fill="#94A3B8"/>
                <rect x="56" y="216" width="292" height="232" rx="26" fill="#1E293B"/>
                <rect x="98" y="336" width="40" height="72" rx="8" fill="#10B981"/>
                <rect x="152" y="296" width="40" height="112" rx="8" fill="#34D399"/>
                <rect x="206" y="316" width="40" height="92" rx="8" fill="#10B981"/>
                <rect x="260" y="256" width="40" height="152" rx="8" fill="#6EE7B7"/>
                <path d="M398 318 L448 336 V372 C448 404 428 428 398 440 C368 428 348 404 348 372 V336 Z" fill="#10B981"/>
                <path d="M374 378 L392 396 L424 360" fill="none" stroke="#FFFFFF" stroke-width="16" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <b>SQL Dashboard</b>
        </a>
        @php($section = $section ?? '')
        <nav class="main" aria-label="Sections">
            <a href="{{ route('sql-dashboard.home') }}" @class(['on' => $section === 'home'])>Queries</a>
            @can('editSqlDashboardQueries', [request()])
                @auth
                    <a href="{{ route('sql-dashboard.checks.index') }}" @class(['on' => $section === 'checks'])>Checks</a>
                    <a href="{{ route('sql-dashboard.audit') }}" @class(['on' => $section === 'audit'])>Audit log</a>
                    <a href="{{ route('sql-dashboard.queries.create') }}" @class(['on' => $section === 'new'])>New query</a>
                @endauth
            @endcan
        </nav>
    </header>

    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="flash error" role="alert">{{ session('error') }}</div>
    @endif

    @yield('content')

    <footer>
        <span>laravel-sql-dashboard {{ \AmjadIqbal\SqlDashboard\SqlDashboardServiceProvider::version() }} · read-only</span>
        <span>Signed in as {{ \AmjadIqbal\SqlDashboard\Audit\Actor::label(auth()->user()) }}</span>
    </footer>
</div>
<script>
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) { if (!window.confirm(form.dataset.confirm)) { e.preventDefault(); } });
    });
    document.querySelectorAll('textarea.sql').forEach(function (area) {
        area.addEventListener('keydown', function (e) {
            if (e.key === 'Tab' && !e.shiftKey) { e.preventDefault(); area.setRangeText('    ', area.selectionStart, area.selectionEnd, 'end'); }
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { var run = area.form.querySelector('button[value=run]'); if (run) { e.preventDefault(); run.click(); } }
        });
    });
</script>
</body>
</html>
