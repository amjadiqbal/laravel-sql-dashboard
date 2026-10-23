<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** @internal Stores on the configured storage connection, never on the read-only data connection. */
abstract class Model extends Eloquent
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('sql-dashboard.storage_connection') ?: parent::getConnectionName();
    }
}
