<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Exceptions;

use RuntimeException;
use Throwable;

/** The database ran the statement and stopped it: an SQL error, a timeout, or a write it refused. */
final class QueryFailed extends RuntimeException
{
    public const TIMEOUT = 'timeout';

    public const READ_ONLY = 'read_only';

    public const ERROR = 'error';

    public function __construct(string $message, public readonly string $kind = self::ERROR, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function timeout(float $seconds, ?Throwable $previous = null): self
    {
        return new self('Stopped after the '.rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.').'s statement timeout.', self::TIMEOUT, $previous);
    }

    public static function readOnly(?Throwable $previous = null): self
    {
        return new self('The database refused to write: this dashboard runs every query in a read-only transaction.', self::READ_ONLY, $previous);
    }
}
