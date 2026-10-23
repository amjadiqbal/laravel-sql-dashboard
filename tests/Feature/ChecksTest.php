<?php

use AmjadIqbal\SqlDashboard\Audit\Actor;
use AmjadIqbal\SqlDashboard\Checks\CheckAlert;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Models\Audit;
use AmjadIqbal\SqlDashboard\Models\Check;
use AmjadIqbal\SqlDashboard\Models\Query;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function checkOn(string $sql, array $attributes = [], array $variables = []): Check
{
    $query = Query::query()->create(['name' => 'Q', 'statement' => $sql, 'chart' => 'auto', 'shared' => true, 'variables' => $variables]);

    return Check::query()->create(['query_id' => $query->id, 'name' => 'Failed orders', 'interval' => 'hourly', 'condition' => 'rows', 'mail_to' => 'ops@example.com', ...$attributes]);
}

it('alerts by mail when a check starts failing, stays quiet while it keeps failing, and alerts when it passes again', function () {
    Mail::fake();
    $check = checkOn("SELECT id, total FROM orders WHERE status = 'failed' AND id <= {upto}", [], ['upto' => ['type' => 'integer', 'label' => 'Up to', 'default' => '7', 'options' => []]]);

    $this->artisan('sql-dashboard:run-checks')->expectsOutputToContain('FAILING')->assertSuccessful();
    Mail::assertSent(CheckAlert::class, fn (CheckAlert $m) => $m->hasTo('ops@example.com') && $m->check->state === 'failing' && $m->result->rowCount() === 2);
    expect($check->fresh()->state)->toBe('failing')->and($check->fresh()->last_message)->toBe('2 rows returned.');

    Mail::fake();
    $this->artisan('sql-dashboard:run-checks', ['--force' => true]);
    Mail::assertNothingSent();

    $check->savedQuery->update(['statement' => "SELECT id FROM orders WHERE status = 'nope'"]);
    $this->artisan('sql-dashboard:run-checks', ['--force' => true]);
    Mail::assertSent(CheckAlert::class, fn (CheckAlert $m) => $m->check->state === 'passing');

    expect(Audit::query()->where('source', 'check')->where('user_label', 'scheduler')->count())->toBe(3);
});

it('renders the alert mail with the failing rows', function () {
    $check = checkOn("SELECT id, status FROM orders WHERE status = 'failed' ORDER BY id");
    $result = app(Runner::class)->run($check->savedQuery->statement, [], [], Actor::system('t'), 'check');
    $check->forceFill(['state' => 'failing', 'last_message' => '30 rows returned.']);

    $html = (new CheckAlert($check, 'passing', $result))->render();

    expect($html)->toContain('Failed orders')->toContain('30 rows returned.')->toContain('and 20 more.')
        ->and((new CheckAlert($check, 'passing', $result))->envelope()->subject)->toBe('Check failing: Failed orders');
});

it('compares a threshold on the first row', function (string $op, string $value, string $state) {
    Mail::fake();
    $check = checkOn('SELECT count(*) AS failed FROM orders WHERE status = \'failed\'', ['condition' => 'threshold', 'threshold_column' => 'failed', 'operator' => $op, 'threshold_value' => $value]);

    $this->artisan('sql-dashboard:run-checks');

    expect($check->fresh()->state)->toBe($state);
})->with([
    ['>', '25', 'failing'],
    ['>', '30', 'passing'],
    ['>=', '30', 'failing'],
    ['<', '10', 'passing'],
    ['=', '30', 'failing'],
    ['!=', '30', 'passing'],
]);

it('reports an error state for a missing threshold column and alerts on it', function () {
    Mail::fake();
    $check = checkOn('SELECT 1 AS x', ['condition' => 'threshold', 'threshold_column' => 'nope', 'operator' => '>', 'threshold_value' => '0']);

    $this->artisan('sql-dashboard:run-checks');

    expect($check->fresh()->state)->toBe('error')->and($check->fresh()->last_message)->toContain('no column named nope');
    Mail::assertSent(CheckAlert::class);
});

it('reports a refused check query as an error', function () {
    Mail::fake();
    $check = checkOn('DELETE FROM orders');

    $this->artisan('sql-dashboard:run-checks');

    expect($check->fresh()->state)->toBe('error')->and($check->fresh()->last_message)->toContain('Only SELECT');
});

it('posts to the Slack webhook from the environment only', function () {
    Mail::fake();
    Http::fake();
    config(['sql-dashboard.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/T/B/x']);
    checkOn("SELECT id FROM orders WHERE status = 'failed' AND id < 12", ['slack' => true, 'mail_to' => null]);

    $this->artisan('sql-dashboard:run-checks');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.com/services/T/B/x'
        && str_contains($request['text'], 'Check failing: Failed orders')
        && str_contains($request['text'], '```'));
});

it('runs each check at its own interval', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-23 10:00:00');
    $hourly = checkOn('SELECT 1 WHERE 1 = 0');
    $daily = checkOn('SELECT 1 WHERE 1 = 0', ['interval' => 'daily', 'name' => 'Daily']);

    $this->artisan('sql-dashboard:run-checks')->expectsOutputToContain('Ran 2 checks');
    Carbon::setTestNow('2026-10-23 10:30:00');
    $this->artisan('sql-dashboard:run-checks')->expectsOutputToContain('No checks were due.');
    Carbon::setTestNow('2026-10-23 11:00:00');
    $this->artisan('sql-dashboard:run-checks')->expectsOutputToContain('Ran 1 check,');

    expect($hourly->fresh()->last_run_at->format('H:i'))->toBe('11:00')->and($daily->fresh()->last_run_at->format('H:i'))->toBe('10:00');
    Carbon::setTestNow();
});

it('only mails allowed domains and needs variable defaults', function () {
    $this->allowAll();
    config(['sql-dashboard.alerts.mail_domains' => ['example.com']]);
    $q = Query::query()->create(['name' => 'Q', 'statement' => 'SELECT {x}', 'chart' => 'auto', 'shared' => true, 'variables' => ['x' => ['type' => 'text', 'label' => 'X', 'default' => null, 'options' => []]]]);
    $ok = Query::query()->create(['name' => 'Ok', 'statement' => 'SELECT 1', 'chart' => 'auto', 'shared' => true, 'variables' => []]);

    $this->actingAs($this->user())->post('/sql-dashboard/checks', ['query_id' => $ok->id, 'name' => 'c', 'interval' => 'hourly', 'condition' => 'rows', 'mail_to' => 'me@evil.test'])
        ->assertSessionHasErrors('mail_to');
    $this->post('/sql-dashboard/checks', ['query_id' => $q->id, 'name' => 'c', 'interval' => 'hourly', 'condition' => 'rows'])
        ->assertSessionHasErrors('query_id');
    $this->post('/sql-dashboard/checks', ['query_id' => $ok->id, 'name' => 'c', 'interval' => 'hourly', 'condition' => 'rows', 'mail_to' => 'ops@example.com'])
        ->assertRedirect('/sql-dashboard/checks');

    expect(Check::query()->sole()->owner_id)->toBe('1');
});

it('runs a check from the browser', function () {
    Mail::fake();
    $this->allowAll();
    $check = checkOn("SELECT id FROM orders WHERE status = 'failed'");

    $this->actingAs($this->user())->post("/sql-dashboard/checks/{$check->id}/run")->assertRedirect('/sql-dashboard/checks');
    $this->get('/sql-dashboard/checks')->assertOk()->assertSee('failing')->assertSee('30 rows returned.');

    expect(Audit::query()->sole()->user_label)->toBe('Ada');
});

it('prunes old audit entries', function () {
    Audit::query()->create(['source' => 'web', 'statement' => 'SELECT 1', 'status' => 'ok', 'created_at' => now()->subDays(100)]);
    Audit::query()->create(['source' => 'web', 'statement' => 'SELECT 2', 'status' => 'ok', 'created_at' => now()->subDays(10)]);

    $this->artisan('sql-dashboard:prune-audit')->expectsOutputToContain('Deleted 1 audit log entry')->assertSuccessful();
    expect(Audit::query()->pluck('statement')->all())->toBe(['SELECT 2']);
});
