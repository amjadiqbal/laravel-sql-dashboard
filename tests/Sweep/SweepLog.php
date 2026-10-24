<?php

namespace AmjadIqbal\SqlDashboard\Tests\Sweep;

use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use Closure;
use Throwable;

/** Runs one sweep attempt and appends what happened to SQL_DASHBOARD_SWEEP_LOG (JSON lines), for the report. */
final class SweepLog
{
    /** @return array{outcome: string, message: string, rows: ?int} */
    public static function attempt(Closure $run): array
    {
        try {
            $result = $run();

            return ['outcome' => 'ran', 'message' => '', 'rows' => $result->rowCount()];
        } catch (QueryRefused $e) {
            return ['outcome' => 'refused', 'message' => $e->getMessage(), 'rows' => null];
        } catch (QueryFailed $e) {
            return ['outcome' => 'failed:'.$e->kind, 'message' => $e->getMessage(), 'rows' => null];
        } catch (Throwable $e) {
            return ['outcome' => 'error', 'message' => $e::class.': '.$e->getMessage(), 'rows' => null];
        }
    }

    /** @param array<string, mixed> $entry */
    public static function write(array $entry): void
    {
        $path = getenv('SQL_DASHBOARD_SWEEP_LOG');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
