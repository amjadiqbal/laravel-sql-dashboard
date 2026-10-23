<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Console;

use AmjadIqbal\SqlDashboard\Models\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class PruneAuditCommand extends Command
{
    protected $signature = 'sql-dashboard:prune-audit {--days= : Keep this many days (default: sql-dashboard.audit.retention_days)}';

    protected $description = 'Delete SQL dashboard audit log entries older than the retention period';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('sql-dashboard.audit.retention_days');
        if ($days === null || $days === '') {
            $this->info('Retention is off (sql-dashboard.audit.retention_days is null): nothing pruned.');

            return self::SUCCESS;
        }

        $deleted = Audit::query()->where('created_at', '<', Carbon::now()->subDays((int) $days))->delete();
        $this->info("Deleted {$deleted} audit log ".($deleted === 1 ? 'entry' : 'entries').' older than '.(int) $days.' days.');

        return self::SUCCESS;
    }
}
