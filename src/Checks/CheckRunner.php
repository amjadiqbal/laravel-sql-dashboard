<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Checks;

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Exceptions\QueryFailed;
use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Models\Check;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Runs a check's query (with its variables' defaults) through the Runner,
 * decides passing / failing / error, and alerts when the state changes.
 */
final class CheckRunner
{
    public function __construct(private readonly Runner $runner) {}

    /** @return array{state: string, message: string, alerted: bool} */
    public function run(Check $check, ?Actor $actor = null): array
    {
        $query = $check->savedQuery;
        $result = null;

        try {
            $result = $this->runner->run($query->statement, $query->definitions(), [], $actor ?? Actor::system('scheduler'), 'check', $query->id);
            [$state, $message] = $this->evaluate($check, $result);
        } catch (QueryRefused|QueryFailed $e) {
            [$state, $message] = ['error', $e->getMessage()];
        }

        $previous = $check->state;
        $changed = $state !== $previous;
        $now = Carbon::now();
        $check->forceFill([
            'state' => $state,
            'last_run_at' => $now,
            'last_message' => mb_substr($message, 0, 2000),
            'last_changed_at' => $changed ? $now : $check->last_changed_at,
        ])->save();

        // Alert on every change, except the first run of a new check coming up as passing.
        $alerted = false;
        if ($changed && ! ($previous === 'unknown' && $state === 'passing')) {
            $alerted = $this->alert($check, $previous, $result);
        }

        return ['state' => $state, 'message' => $message, 'alerted' => $alerted];
    }

    /** @return array{0: string, 1: string} */
    public function evaluate(Check $check, Result $result): array
    {
        if ($check->condition !== 'threshold') {
            $n = $result->rowCount();

            return $n > 0
                ? ['failing', ($result->truncated ? 'At least ' : '').$n.' '.($n === 1 ? 'row' : 'rows').' returned.']
                : ['passing', 'No rows returned.'];
        }

        $column = (string) $check->threshold_column;
        $index = array_search($column, $result->columns, true);
        if ($index === false) {
            return ['error', "The result has no column named {$column}."];
        }
        if ($result->rowCount() === 0) {
            return ['error', 'The query returned no rows, so there is no value to compare.'];
        }
        $value = $result->rows[0][$index] ?? null;
        if (! is_numeric($value) || ! is_numeric($check->threshold_value)) {
            return ['error', "{$column} is ".var_export($value, true).', not a number.'];
        }
        $v = (float) $value;
        $t = (float) $check->threshold_value;
        $crossed = match ($check->operator) {
            '>' => $v > $t,
            '>=' => $v >= $t,
            '<' => $v < $t,
            '<=' => $v <= $t,
            '=' => $v == $t,
            '!=' => $v != $t,
            default => false,
        };
        $shown = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');

        return $crossed
            ? ['failing', "{$column} is {$shown}, ".(Check::OPERATORS[$check->operator] ?? $check->operator)." {$check->threshold_value}."]
            : ['passing', "{$column} is {$shown}."];
    }

    private function alert(Check $check, string $previous, ?Result $result): bool
    {
        $sent = false;
        $recipients = array_values(array_filter($check->recipients(), fn (string $to) => self::allowedRecipient($to)));
        if ($recipients !== []) {
            try {
                Mail::to($recipients)->send(new CheckAlert($check, $previous, $result));
                $sent = true;
            } catch (Throwable $e) {
                Log::warning("laravel-sql-dashboard could not mail the alert for check #{$check->id}: ".$e->getMessage());
            }
        }

        $webhook = config('sql-dashboard.alerts.slack_webhook_url');
        if ($check->slack && is_string($webhook) && $webhook !== '') {
            try {
                Http::timeout(10)->post($webhook, ['text' => CheckAlert::plainText($check, $previous, $result)])->throw();
                $sent = true;
            } catch (Throwable $e) {
                Log::warning("laravel-sql-dashboard could not post the Slack alert for check #{$check->id}: ".$e->getMessage());
            }
        }

        return $sent;
    }

    public static function allowedRecipient(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domains = array_map('strtolower', (array) config('sql-dashboard.alerts.mail_domains', []));

        return $domains === [] || in_array(strtolower(substr(strrchr($address, '@') ?: '', 1)), $domains, true);
    }
}
