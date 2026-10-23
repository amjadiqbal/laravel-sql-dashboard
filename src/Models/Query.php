<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A saved query. Shared queries are visible to (and editable by) everyone
 * allowed into the dashboard; private ones only to their owner.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property string $statement
 * @property array<string, array{type: string, label: string, default: ?string, options: list<string>}>|null $variables
 * @property string $chart
 * @property bool $shared
 * @property ?string $owner_id
 * @property ?string $owner_label
 * @property ?Carbon $updated_at
 */
final class Query extends Model
{
    public const CHARTS = ['auto' => 'Pick from the result', 'line' => 'Line', 'bar' => 'Bar', 'pie' => 'Pie', 'table' => 'Table only'];

    protected $table = 'sql_dashboard_queries';

    protected $casts = ['variables' => 'array', 'shared' => 'boolean'];

    /** @param Builder<Query> $query */
    public function scopeVisibleTo(Builder $query, ?string $userId): void
    {
        $query->where(fn (Builder $q) => $q->where('shared', true)->when($userId !== null, fn (Builder $q) => $q->orWhere('owner_id', $userId)));
    }

    public function isVisibleTo(?string $userId): bool
    {
        return $this->shared || ($userId !== null && $this->owner_id === $userId);
    }

    /** @return HasMany<Check, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class, 'query_id');
    }

    /** @return BelongsToMany<Dashboard, $this> */
    public function dashboards(): BelongsToMany
    {
        return $this->belongsToMany(Dashboard::class, 'sql_dashboard_dashboard_query', 'query_id', 'dashboard_id');
    }

    /** @return array<string, array{type: string, label: string, default: ?string, options: list<string>}> */
    public function definitions(): array
    {
        return $this->variables ?? [];
    }
}
