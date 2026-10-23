<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ?string $user_id
 * @property ?string $user_label
 * @property ?int $query_id
 * @property string $source
 * @property ?string $connection
 * @property string $statement
 * @property ?array<string, mixed> $variables
 * @property string $status
 * @property ?int $row_count
 * @property ?int $duration_ms
 * @property ?string $message
 * @property ?string $ip
 * @property ?Carbon $created_at
 */
final class Audit extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sql_dashboard_audits';

    protected $casts = ['variables' => 'array', 'query_id' => 'integer', 'row_count' => 'integer', 'duration_ms' => 'integer'];
}
