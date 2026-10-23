<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Exceptions;

use RuntimeException;

/** The statement, its variables or the connection failed a safety rule, so nothing was sent to run. */
final class QueryRefused extends RuntimeException {}
