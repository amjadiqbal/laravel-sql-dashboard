<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Typed {variables}: their definitions and turning raw input into a value of
 * the right type. Values are always bound as parameters, never put into SQL.
 *
 * A definition is ['type' => 'date', 'label' => ..., 'default' => ..., 'options' => [...]].
 * Date and date-time defaults may be relative: now, today, yesterday, or
 * "-7 days" style offsets, so scheduled checks always look at a fresh window.
 */
final class Variables
{
    public const MAX_TEXT = 1000;

    private const RELATIVE = '/^(now|today|yesterday|tomorrow|[+-]\d{1,4} (minute|hour|day|week|month|year)s?)$/i';

    /**
     * Definitions for the variables a statement uses: existing ones kept,
     * new ones added as text.
     *
     * @param  array<string, mixed>  $definitions
     * @param  list<string>  $used
     * @return array<string, array{type: string, label: string, default: ?string, options: list<string>}>
     */
    public static function normalize(array $definitions, array $used): array
    {
        $out = [];
        foreach ($used as $name) {
            $d = is_array($definitions[$name] ?? null) ? $definitions[$name] : [];
            $type = VariableType::tryFrom((string) ($d['type'] ?? '')) ?? VariableType::Text;
            $options = $d['options'] ?? [];
            if (is_string($options)) {
                $options = preg_split('/\s*[,\n]\s*/', trim($options)) ?: [];
            }
            $default = isset($d['default']) && $d['default'] !== '' ? (string) $d['default'] : null;
            $out[$name] = [
                'type' => $type->value,
                'label' => trim((string) ($d['label'] ?? '')) ?: ucfirst(str_replace('_', ' ', $name)),
                'default' => $default,
                'options' => array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) $options), fn ($o) => $o !== '')),
            ];
        }

        return $out;
    }

    /**
     * Problems with the definitions themselves (shown in the editor).
     *
     * @param  array<string, array{type: string, label: string, default: ?string, options: list<string>}>  $definitions
     * @return array<string, string>
     */
    public static function definitionErrors(array $definitions): array
    {
        $errors = [];
        foreach ($definitions as $name => $d) {
            if ($d['type'] === VariableType::Select->value && $d['options'] === []) {
                $errors[$name] = "{{$name}} is a list: give it at least one option.";

                continue;
            }
            if ($d['default'] !== null) {
                try {
                    self::cast($name, $d, $d['default']);
                } catch (QueryRefused $e) {
                    $errors[$name] = 'Default: '.$e->getMessage();
                }
            }
        }

        return $errors;
    }

    /**
     * The value of every variable from the input, falling back to defaults.
     * Variables without a value are listed in $missing instead.
     *
     * @param  array<string, array{type: string, label: string, default: ?string, options: list<string>}>  $definitions
     * @param  array<string, mixed>  $input
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    public static function resolve(array $definitions, array $input, array &$missing = []): array
    {
        $values = [];
        $missing = [];
        foreach ($definitions as $name => $d) {
            $raw = $input[$name] ?? null;
            if ($raw === null || $raw === '' || is_array($raw)) {
                $raw = $d['default'];
            }
            if ($raw === null) {
                $missing[] = $name;

                continue;
            }
            $values[$name] = self::cast($name, $d, (string) $raw);
        }

        return $values;
    }

    /**
     * @param  array{type: string, label: string, default: ?string, options: list<string>}  $d
     */
    public static function cast(string $name, array $d, string $raw): int|string|bool
    {
        $type = VariableType::tryFrom($d['type']) ?? VariableType::Text;
        $raw = trim($raw);

        return match ($type) {
            VariableType::Text => mb_strlen($raw) <= self::MAX_TEXT ? $raw : throw new QueryRefused("{{$name}} is longer than ".self::MAX_TEXT.' characters.'),
            VariableType::Integer => preg_match('/^-?\d{1,18}$/', $raw) ? (int) $raw : throw new QueryRefused("{{$name}} must be a whole number."),
            VariableType::Decimal => preg_match('/^-?\d{1,30}(\.\d{1,30})?$/', $raw) ? $raw : throw new QueryRefused("{{$name}} must be a number."),
            VariableType::Date => self::date($name, $raw)->format('Y-m-d'),
            VariableType::DateTime => self::date($name, $raw)->format('Y-m-d H:i:s'),
            VariableType::Boolean => match (strtolower($raw)) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => throw new QueryRefused("{{$name}} must be yes or no."),
            },
            VariableType::Select => in_array($raw, $d['options'], true) ? $raw : throw new QueryRefused("{{$name}} must be one of: ".implode(', ', $d['options']).'.'),
        };
    }

    private static function date(string $name, string $raw): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $raw) || preg_match(self::RELATIVE, $raw)) {
            try {
                $date = CarbonImmutable::parse(str_replace('T', ' ', $raw));
                // parse() rolls 2026-02-31 over to March; refuse it instead.
                if (! ctype_digit($raw[0]) || $date->format('Y-m-d') === substr($raw, 0, 10)) {
                    return $date;
                }
            } catch (Throwable) {
                // fall through
            }
        }

        throw new QueryRefused("{{$name}} must be a date like 2026-01-31 (or now, today, yesterday, -7 days).");
    }
}
