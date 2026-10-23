<?php

use AmjadIqbal\SqlDashboard\Exceptions\QueryRefused;
use AmjadIqbal\SqlDashboard\Sql\Variables;
use Carbon\CarbonImmutable;

function def(string $type, ?string $default = null, array $options = []): array
{
    return ['type' => $type, 'label' => 'X', 'default' => $default, 'options' => $options];
}

it('casts each type', function () {
    expect(Variables::cast('n', def('integer'), ' 42 '))->toBe(42)
        ->and(Variables::cast('n', def('decimal'), '-3.50'))->toBe('-3.50')
        ->and(Variables::cast('d', def('date'), '2026-02-28'))->toBe('2026-02-28')
        ->and(Variables::cast('d', def('datetime'), '2026-02-28T13:45'))->toBe('2026-02-28 13:45:00')
        ->and(Variables::cast('b', def('boolean'), 'yes'))->toBeTrue()
        ->and(Variables::cast('b', def('boolean'), '0'))->toBeFalse()
        ->and(Variables::cast('s', def('select', null, ['paid', 'failed']), 'paid'))->toBe('paid')
        ->and(Variables::cast('t', def('text'), "paid' OR 1=1 --"))->toBe("paid' OR 1=1 --");
});

it('resolves relative dates', function () {
    CarbonImmutable::setTestNow('2026-10-23 10:00:00');
    expect(Variables::cast('d', def('date'), 'today'))->toBe('2026-10-23')
        ->and(Variables::cast('d', def('date'), '-7 days'))->toBe('2026-10-16')
        ->and(Variables::cast('d', def('datetime'), 'yesterday'))->toBe('2026-10-22 00:00:00');
    CarbonImmutable::setTestNow();
});

it('refuses values of the wrong type', function (string $type, string $raw, array $options = []) {
    Variables::cast('v', def($type, null, $options), $raw);
})->with([
    ['integer', '4.5'],
    ['integer', '1; DROP TABLE x'],
    ['decimal', '1e5'],
    ['date', '2026-02-31'],
    ['date', 'next friday'],
    ['date', "2026-01-01'; --"],
    ['boolean', 'maybe'],
    ['select', 'refunded', ['paid', 'failed']],
])->throws(QueryRefused::class);

it('refuses text over the length limit', function () {
    Variables::cast('t', def('text'), str_repeat('a', Variables::MAX_TEXT + 1));
})->throws(QueryRefused::class, 'longer than');

it('normalizes definitions for the variables a statement uses', function () {
    $defs = Variables::normalize([
        'since' => ['type' => 'date', 'default' => '-30 days', 'label' => ''],
        'gone' => ['type' => 'integer'],
        'status' => ['type' => 'select', 'options' => "paid, failed\nrefunded"],
    ], ['since', 'status', 'country']);

    expect(array_keys($defs))->toBe(['since', 'status', 'country'])
        ->and($defs['since'])->toBe(['type' => 'date', 'label' => 'Since', 'default' => '-30 days', 'options' => []])
        ->and($defs['status']['options'])->toBe(['paid', 'failed', 'refunded'])
        ->and($defs['country']['type'])->toBe('text');
});

it('falls back to defaults and lists what is missing', function () {
    $missing = [];
    $values = Variables::resolve(['a' => def('integer', '5'), 'b' => def('text'), 'c' => def('text')], ['c' => 'x'], $missing);

    expect($values)->toBe(['a' => 5, 'c' => 'x'])->and($missing)->toBe(['b']);
});

it('reports bad definitions', function () {
    expect(Variables::definitionErrors(['s' => def('select'), 'n' => def('integer', 'ten')]))
        ->toHaveKeys(['s', 'n']);
});
