<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Console;

use AmjadIqbal\SqlDashboard\Checks\CheckRunner;
use AmjadIqbal\SqlDashboard\Models\Check;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class RunChecksCommand extends Command
{
    protected $signature = 'sql-dashboard:run-checks
        {--check=* : Run only these check IDs}
        {--force : Run every selected check now, due or not}';

    protected $description = 'Run the SQL dashboard checks that are due and send alerts for any that changed state';

    public function handle(CheckRunner $runner): int
    {
        $now = Carbon::now();
        $ids = array_filter(array_map('intval', (array) $this->option('check')));
        $checks = Check::query()->with('savedQuery')->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();

        $ran = 0;
        $failing = 0;
        foreach ($checks as $check) {
            if (! $this->option('force') && $ids === [] && ! $check->isDue($now)) {
                continue;
            }
            $outcome = $runner->run($check);
            $ran++;
            $failing += $outcome['state'] === 'passing' ? 0 : 1;
            $this->line(sprintf('  %-8s #%d %s: %s%s', strtoupper($outcome['state']), $check->id, $check->name, $outcome['message'], $outcome['alerted'] ? ' (alert sent)' : ''));
        }

        $this->info($ran === 0 ? 'No checks were due.' : "Ran {$ran} ".($ran === 1 ? 'check' : 'checks').", {$failing} not passing.");

        return self::SUCCESS;
    }
}
