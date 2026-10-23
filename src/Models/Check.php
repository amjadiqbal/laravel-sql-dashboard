<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A scheduled check on a saved query. It fails when the query returns any
 * row ("rows") or when the first row's column crosses a threshold.
 *
 * @property int $id
 * @property int $query_id
 * @property string $name
 * @property string $interval
 * @property string $condition
 * @property ?string $threshold_column
 * @property ?string $operator
 * @property ?string $threshold_value
 * @property ?string $mail_to
 * @property bool $slack
 * @property string $state
 * @property ?Carbon $last_run_at
 * @property ?Carbon $last_changed_at
 * @property ?string $last_message
 * @property ?string $owner_id
 * @property ?string $owner_label
 * @property Query $savedQuery
 */
final class Check extends Model
{
    public const CONDITIONS = ['rows' => 'Fails when the query returns any row', 'threshold' => 'Fails when a value crosses a threshold'];

    public const OPERATORS = ['>' => 'above', '>=' => 'at or above', '<' => 'below', '<=' => 'at or below', '=' => 'equal to', '!=' => 'not equal to'];

    protected $table = 'sql_dashboard_checks';

    protected $casts = ['slack' => 'boolean', 'last_run_at' => 'datetime', 'last_changed_at' => 'datetime'];

    /** @return BelongsTo<Query, $this> */
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    public function minutes(): int
    {
        return max(1, (int) (config('sql-dashboard.intervals')[$this->interval] ?? 60));
    }

    public function isDue(Carbon $now): bool
    {
        return $this->last_run_at === null || $this->last_run_at->copy()->addMinutes($this->minutes())->lte($now->copy()->addSeconds(30));
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $this->mail_to) ?: [])));
    }

    public function describe(): string
    {
        return $this->condition === 'threshold'
            ? "{$this->threshold_column} ".(self::OPERATORS[$this->operator] ?? $this->operator)." {$this->threshold_value}"
            : 'any row returned';
    }
}
