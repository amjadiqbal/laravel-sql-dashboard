<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

enum VariableType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Select = 'select';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Integer => 'Whole number',
            self::Decimal => 'Number',
            self::Date => 'Date',
            self::DateTime => 'Date and time',
            self::Boolean => 'Yes / no',
            self::Select => 'One of a list',
        };
    }

    /** The <input type> used for it in the browser. */
    public function inputType(): string
    {
        return match ($this) {
            self::Integer, self::Decimal => 'number',
            self::Date => 'date',
            self::DateTime => 'datetime-local',
            default => 'text',
        };
    }
}
