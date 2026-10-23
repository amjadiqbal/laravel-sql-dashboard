<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution\Drivers;

use AmjadIqbal\SqlDashboard\Execution\Limits;
use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Sql\CompiledStatement;
use Illuminate\Database\Connection;

/**
 * Runs one compiled statement so that the database itself refuses any write
 * and stops it at the timeout. Throws QueryFailed when the database does.
 */
interface Executor
{
    public function run(Connection $connection, CompiledStatement $statement, Limits $limits): Result;
}
