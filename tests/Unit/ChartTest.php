<?php

use AmjadIqbal\SqlDashboard\Charts\Chart;
use AmjadIqbal\SqlDashboard\Charts\SvgChart;
use AmjadIqbal\SqlDashboard\Execution\Result;

it('picks a line chart for a date and numbers, sorted by date', function () {
    $chart = Chart::pick(new Result(['day', 'orders', 'revenue'], [['2026-09-02', 3, '9.5'], ['2026-09-01', 2, '4.00']]));

    expect($chart->type)->toBe('line')
        ->and($chart->labels)->toBe(['2026-09-01', '2026-09-02'])
        ->and($chart->series)->toBe([['name' => 'orders', 'values' => [2.0, 3.0]], ['name' => 'revenue', 'values' => [4.0, 9.5]]]);
});

it('pivots date, label, number into one line per label', function () {
    $chart = Chart::pick(new Result(['day', 'status', 'n'], [['2026-09-01', 'paid', 2], ['2026-09-01', 'failed', 1], ['2026-09-02', 'paid', 5]]));

    expect($chart->type)->toBe('line')
        ->and(array_column($chart->series, 'name'))->toBe(['paid', 'failed'])
        ->and($chart->series[1]['values'])->toBe([1.0, null]);
});

it('picks a bar chart for labels and numbers, and a pie when the last column is named pie', function () {
    expect(Chart::pick(new Result(['country', 'customers'], [['PK', 4], ['GB', 2]]))->type)->toBe('bar')
        ->and(Chart::pick(new Result(['country', 'pie'], [['PK', 4], ['GB', 2]]))->type)->toBe('pie');
});

it('follows the query setting when the shape fits, and says why when it does not', function () {
    $result = new Result(['country', 'customers'], [['PK', 4], ['GB', 2]]);
    expect(Chart::pick($result, 'pie')->type)->toBe('pie')
        ->and(Chart::pick($result, 'line')->type)->toBe('line')
        ->and(Chart::pick($result, 'table'))->toBeNull();

    $why = null;
    expect(Chart::pick(new Result(['a', 'b', 'c'], [['x', 1, 2]]), 'pie', $why))->toBeNull()
        ->and($why)->toContain('pie chart needs one column')
        ->and(Chart::pick(new Result(['name', 'email'], [['a', 'b']]), 'bar', $why))->toBeNull()
        ->and($why)->toContain('No chart');
});

it('has no chart for text-only or empty results in auto mode', function () {
    $why = 'x';
    expect(Chart::pick(new Result(['name', 'email'], [['a', 'b']]), 'auto', $why))->toBeNull()->and($why)->toBeNull()
        ->and(Chart::pick(new Result(['n'], [[1]])))->toBeNull()
        ->and(Chart::pick(new Result(['a', 'b'], [])))->toBeNull();
});

it('tells column kinds apart', function () {
    expect(Chart::kind([1, '2.5', null]))->toBe('number')
        ->and(Chart::kind(['2026-09-01', '2026-09-02 10:00:00']))->toBe('date')
        ->and(Chart::kind(['007', '8']))->toBe('text')
        ->and(Chart::kind(['PK', 3]))->toBe('text')
        ->and(Chart::kind([null]))->toBe('text');
});

it('renders SVG with escaped labels and a tooltip per point', function (string $type) {
    $svg = SvgChart::render(new Chart($type, ['<script>alert(1)</script>', 'b'], [['name' => 'n"x', 'values' => [3.0, 5.0]]]));

    expect($svg)->toContain('<svg')
        ->not->toContain('<script>alert')
        ->toContain('&lt;script&gt;')
        ->toContain('<title>');
})->with(['line', 'bar', 'pie']);
