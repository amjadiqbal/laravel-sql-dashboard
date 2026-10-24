<?php

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Sql\Dialect;
use AmjadIqbal\SqlDashboard\Sql\StatementGuard;

/** Every 'guard' case of the adversarial sweep is refused by the statement check alone. */
function sweepGuardCases(): array
{
    $cases = [];
    foreach (require __DIR__.'/../Sweep/cases.php' as $driver => $list) {
        foreach ($list as $name => [$sql, $expect]) {
            if ($expect === 'guard') {
                $cases["{$driver}: {$name}"] = [$sql, Dialect::fromDriver($driver)];
            }
        }
    }

    return $cases;
}

it('refuses every guard-level attempt of the sweep', function (string $sql, Dialect $dialect) {
    expect(fn () => StatementGuard::compile($sql, $dialect))->toThrow(QueryRefused::class);
})->with(sweepGuardCases());
