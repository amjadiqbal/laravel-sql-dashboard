<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Execution;

/** Collects fetched rows until the row or byte limit, then says why it stopped. */
final class RowCollector
{
    /** @var list<list<mixed>> */
    public array $rows = [];

    /** @var 'rows'|'bytes'|null */
    public ?string $truncated = null;

    private int $bytes = 0;

    public function __construct(private readonly Limits $limits) {}

    /**
     * @param  list<mixed>  $row
     * @return bool false once the result is full
     */
    public function add(array $row): bool
    {
        if (count($this->rows) >= $this->limits->maxRows) {
            $this->truncated = 'rows';

            return false;
        }
        foreach ($row as $value) {
            $this->bytes += is_string($value) ? strlen($value) : 8;
        }
        $this->rows[] = array_values($row);
        if ($this->bytes > $this->limits->maxBytes) {
            $this->truncated = 'bytes';

            return false;
        }

        return true;
    }

    public function full(): bool
    {
        return $this->truncated !== null;
    }
}
