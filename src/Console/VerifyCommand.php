<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Console;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use Illuminate\Console\Command;

final class VerifyCommand extends Command
{
    protected $signature = 'sql-dashboard:verify';

    protected $description = 'Check that the SQL dashboard connection is read-only and can run a query';

    public function handle(Runner $runner): int
    {
        $limits = Limits::fromConfig();
        $this->line('Connection: '.($runner->connectionName() ?? '(not set: SQL_DASHBOARD_CONNECTION)'));
        $this->line('Driver:     '.($runner->dialect()?->label() ?? 'unknown'));
        $this->line("Limits:     {$limits->timeout}s timeout, {$limits->maxRows} rows");

        try {
            $result = $runner->run('SELECT 1 AS ok', [], [], Actor::system('artisan'), 'console');
        } catch (QueryRefused|QueryFailed $e) {
            $this->error('Not ready: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Ready: the connection is read-only and SELECT 1 returned '.json_encode($result->rows[0][0] ?? null).' in '.round($result->durationMs).' ms.');

        return self::SUCCESS;
    }
}
