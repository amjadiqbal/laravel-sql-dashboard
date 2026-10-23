<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Checks;

use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Models\Check;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Route;

final class CheckAlert extends Mailable
{
    public function __construct(
        public Check $check,
        public string $previous,
        public ?Result $result,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::headline($this->check));
    }

    public function content(): Content
    {
        $rows = (int) config('sql-dashboard.alerts.rows_in_alert', 10);

        return new Content(view: 'sql-dashboard::mail.alert', with: [
            'check' => $this->check,
            'previous' => $this->previous,
            'columns' => $this->result->columns ?? [],
            'rows' => array_slice($this->result->rows ?? [], 0, max(0, $rows)),
            'more' => max(0, ($this->result?->rowCount() ?? 0) - $rows),
            'url' => self::url($this->check),
        ]);
    }

    public static function headline(Check $check): string
    {
        return match ($check->state) {
            'failing' => "Check failing: {$check->name}",
            'passing' => "Check passing again: {$check->name}",
            default => "Check error: {$check->name}",
        };
    }

    public static function plainText(Check $check, string $previous, ?Result $result): string
    {
        $text = '*'.self::headline($check)."*\n{$check->last_message}";
        if ($result !== null && $result->rowCount() > 0 && $check->state === 'failing') {
            $lines = [implode(' | ', $result->columns)];
            foreach (array_slice($result->rows, 0, 5) as $row) {
                $lines[] = implode(' | ', array_map(fn ($v) => $v === null ? 'NULL' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), $row));
            }
            $text .= "\n```\n".mb_substr(implode("\n", $lines), 0, 2500)."\n```";
        }
        $url = self::url($check);

        return $url ? "{$text}\n{$url}" : $text;
    }

    private static function url(Check $check): ?string
    {
        return Route::has('sql-dashboard.queries.show') ? route('sql-dashboard.queries.show', $check->query_id) : null;
    }
}
