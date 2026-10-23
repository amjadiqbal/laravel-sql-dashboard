<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

/** A statement that passed the guard: SQL with ? placeholders and the values bound to them, in order. */
final readonly class CompiledStatement
{
    /**
     * @param  list<mixed>  $bindings
     * @param  list<string>  $variables
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public array $variables,
    ) {}
}
