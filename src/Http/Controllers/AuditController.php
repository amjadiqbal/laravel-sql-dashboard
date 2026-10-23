<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Http\Controllers;

use AmjadIqbal\SqlDashboard\Models\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AuditController extends Controller
{
    public const STATUSES = ['ok', 'refused', 'failed', 'timeout'];

    public const SOURCES = ['web', 'editor', 'dashboard', 'export', 'check', 'console'];

    public function __invoke(Request $request): View
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : null;
        $source = in_array($request->query('source'), self::SOURCES, true) ? (string) $request->query('source') : null;
        $user = trim((string) $request->query('user', ''));

        $entries = Audit::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->when($user !== '', fn ($q) => $q->where('user_label', 'like', '%'.$user.'%'))
            ->when($request->integer('query') > 0, fn ($q) => $q->where('query_id', $request->integer('query')))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('sql-dashboard::audit', compact('entries', 'status', 'source', 'user'));
    }
}
