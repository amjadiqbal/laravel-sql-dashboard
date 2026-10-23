<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property bool $shared
 * @property ?string $owner_id
 * @property ?string $owner_label
 * @property Collection<int, Query> $queries
 */
final class Dashboard extends Model
{
    protected $table = 'sql_dashboard_dashboards';

    protected $casts = ['shared' => 'boolean'];

    /** @return BelongsToMany<Query, $this> */
    public function queries(): BelongsToMany
    {
        return $this->belongsToMany(Query::class, 'sql_dashboard_dashboard_query', 'dashboard_id', 'query_id')
            ->withPivot('position')->orderByPivot('position');
    }

    /** @param Builder<Dashboard> $query */
    public function scopeVisibleTo(Builder $query, ?string $userId): void
    {
        $query->where(fn (Builder $q) => $q->where('shared', true)->when($userId !== null, fn (Builder $q) => $q->orWhere('owner_id', $userId)));
    }

    public function isVisibleTo(?string $userId): bool
    {
        return $this->shared || ($userId !== null && $this->owner_id === $userId);
    }
}
