<?php

use AmjadIqbal\SqlDashboard\Execution\ConnectionVerifier;
use AmjadIqbal\SqlDashboard\Models\Audit;
use AmjadIqbal\SqlDashboard\Models\Dashboard;
use AmjadIqbal\SqlDashboard\Models\Query;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;

function saved(array $attributes = []): Query
{
    return Query::query()->create([
        'name' => 'Orders by status',
        'statement' => 'SELECT status, count(*) AS n FROM orders GROUP BY status ORDER BY status',
        'chart' => 'auto',
        'shared' => true,
        'owner_id' => '1',
        'owner_label' => 'Ada',
        'variables' => [],
        ...$attributes,
    ]);
}

it('denies everything outside the local environment until the app defines the gates', function () {
    expect(app()->environment())->not->toBe('local');
    $q = saved();

    $this->get('/sql-dashboard')->assertForbidden();
    $this->actingAs($this->user())->get("/sql-dashboard/queries/{$q->id}")->assertForbidden();
    $this->actingAs($this->user())->get("/sql-dashboard/queries/{$q->id}/export")->assertForbidden();
    $this->actingAs($this->user())->post('/sql-dashboard/queries', ['name' => 'x', 'statement' => 'SELECT 1', 'chart' => 'auto'])->assertForbidden();
    expect(Audit::query()->count())->toBe(0);
});

it('allows the local environment by default, but never raw SQL from a guest', function () {
    app()['env'] = 'local';

    $this->get('/sql-dashboard')->assertOk()->assertSee('Queries');
    $this->withSession(['_token' => 't'])->post('/sql-dashboard/queries', ['_token' => 't', 'name' => 'x', 'statement' => 'SELECT 1', 'chart' => 'auto', 'intent' => 'run'])->assertForbidden();
    $this->get('/sql-dashboard/queries/create')->assertForbidden();
    expect(Audit::query()->count())->toBe(0);
});

it('keeps raw SQL from guests even when the app opens the edit gate to everyone', function () {
    $this->allowAll();

    $this->post('/sql-dashboard/queries', ['name' => 'x', 'statement' => 'SELECT 1', 'chart' => 'auto', 'intent' => 'run'])
        ->assertForbidden();
});

it('applies each gate on its own', function () {
    Gate::define('viewSqlDashboard', fn ($user = null) => true);
    Gate::define('runSqlDashboardQueries', fn ($user = null) => false);
    Gate::define('editSqlDashboardQueries', fn ($user = null) => false);
    $q = saved();

    $this->actingAs($this->user())->get("/sql-dashboard/queries/{$q->id}")->assertOk()->assertSee('You can see this query but not run it.')->assertDontSee('<table class="data">', false);
    $this->get("/sql-dashboard/queries/{$q->id}/export")->assertForbidden();
    $this->get("/sql-dashboard/queries/{$q->id}/edit")->assertForbidden();
    $this->get('/sql-dashboard/audit')->assertForbidden();
    $this->get('/sql-dashboard/checks')->assertForbidden();
    expect(Audit::query()->count())->toBe(0);
});

it('saves a query and runs it with a chart and a table', function () {
    $this->allowAll();
    $this->actingAs($this->user());

    $this->post('/sql-dashboard/queries', [
        'name' => 'Daily paid orders',
        'statement' => 'SELECT day, count(*) AS orders FROM orders WHERE status = {status} AND day >= {since} GROUP BY day ORDER BY day',
        'chart' => 'auto',
        'shared' => '1',
        'variables' => ['status' => ['type' => 'select', 'options' => 'paid, failed, refunded', 'default' => 'paid'], 'since' => ['type' => 'date', 'default' => '2026-09-20']],
        'intent' => 'save',
    ])->assertRedirect();

    $q = Query::query()->sole();
    expect($q->owner_id)->toBe('1')->and($q->variables['since']['type'])->toBe('date');

    $this->get("/sql-dashboard/queries/{$q->id}")
        ->assertOk()
        ->assertSee('<svg class="chart"', false)
        ->assertSee('2026-09-20')
        ->assertSee('rows ·', false);

    $this->get("/sql-dashboard/queries/{$q->id}?v[status]=failed&v[since]=2026-09-29")->assertOk()->assertSee('2026-09-30');
    $this->get("/sql-dashboard/queries/{$q->id}?v[status]=nope")->assertOk()->assertSee('must be one of');

    expect(Audit::query()->where('source', 'web')->where('status', 'ok')->count())->toBe(2);
});

it('shows a refusal in the editor and logs it', function () {
    $this->allowAll();

    $this->actingAs($this->user())->post('/sql-dashboard/queries', [
        'name' => 'Sneaky', 'statement' => 'WITH c AS (SELECT 1) DELETE FROM orders', 'chart' => 'auto', 'intent' => 'run',
    ])->assertOk()->assertSee('DELETE is not allowed');

    $this->post('/sql-dashboard/queries', ['name' => 'Sneaky', 'statement' => 'SELECT 1; DROP TABLE orders', 'chart' => 'auto', 'intent' => 'save'])
        ->assertOk()->assertSee('Only one statement');

    expect(Query::query()->count())->toBe(0);
});

it('runs unsaved SQL from the editor', function () {
    $this->allowAll();

    $this->actingAs($this->user())->post('/sql-dashboard/queries', [
        'name' => 'Try', 'statement' => 'SELECT country, count(*) AS pie FROM customers GROUP BY country', 'chart' => 'auto', 'intent' => 'run',
    ])->assertOk()->assertSee('<svg class="chart pie"', false)->assertSee('PK');

    expect(Audit::query()->sole()->source)->toBe('editor');
});

it('exports CSV with formulas neutralised', function () {
    $this->allowAll();
    $q = saved(['statement' => "SELECT id, '=1+1' AS evil FROM orders WHERE id <= 2 ORDER BY id"]);

    $response = $this->actingAs($this->user())->get("/sql-dashboard/queries/{$q->id}/export");

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->streamedContent())->toBe("\xEF\xBB\xBFid,evil\n1,'=1+1\n2,'=1+1\n")
        ->and(Audit::query()->sole()->source)->toBe('export');
});

it('hides private queries and dashboards from everyone but their owner', function () {
    $this->allowAll();
    $q = saved(['shared' => false]);
    $d = Dashboard::query()->create(['name' => 'Mine', 'shared' => false, 'owner_id' => '1']);

    $this->actingAs($this->user(2, 'Bob'))->get("/sql-dashboard/queries/{$q->id}")->assertNotFound();
    $this->get("/sql-dashboard/queries/{$q->id}/edit")->assertNotFound();
    $this->get("/sql-dashboard/queries/{$q->id}/export")->assertNotFound();
    $this->get("/sql-dashboard/dashboards/{$d->id}")->assertNotFound();
    $this->get('/sql-dashboard')->assertDontSee('Orders by status');

    $this->actingAs($this->user(1))->get("/sql-dashboard/queries/{$q->id}")->assertOk();
    $this->get('/sql-dashboard')->assertSee('Orders by status');
});

it('builds a dashboard of several queries sharing one variable form', function () {
    $this->allowAll();
    $a = saved();
    $b = saved([
        'name' => 'Revenue by day',
        'statement' => 'SELECT day, sum(total) AS revenue FROM orders WHERE day >= {since} GROUP BY day',
        'variables' => ['since' => ['type' => 'date', 'label' => 'Since', 'default' => '2026-09-25', 'options' => []]],
    ]);

    $this->actingAs($this->user())->post('/sql-dashboard/dashboards', ['name' => 'Sales', 'queries' => [$b->id, $a->id], 'shared' => '1'])->assertRedirect();
    $d = Dashboard::query()->sole();
    expect($d->queries->pluck('id')->all())->toBe([$b->id, $a->id]);

    $this->get("/sql-dashboard/dashboards/{$d->id}?v[since]=2026-09-29")
        ->assertOk()
        ->assertSee('Revenue by day')
        ->assertSee('Orders by status')
        ->assertSee('name="v[since]"', false)
        ->assertSee('2026-09-30');

    expect(Audit::query()->where('source', 'dashboard')->count())->toBe(2);
});

it('lists the audit log with filters', function () {
    $this->allowAll();
    $this->actingAs($this->user())->post('/sql-dashboard/queries', ['name' => 'x', 'statement' => 'DROP TABLE orders', 'chart' => 'auto', 'intent' => 'run']);
    $q = saved();
    $this->get("/sql-dashboard/queries/{$q->id}");

    $this->get('/sql-dashboard/audit')->assertOk()->assertSee('DROP TABLE orders')->assertSee('refused')->assertSee('Ada');
    $this->get('/sql-dashboard/audit?status=refused')->assertOk()->assertSee('DROP TABLE orders')->assertDontSee('GROUP BY status');
});

it('says how to set up the connection when there is none', function () {
    $this->allowAll();
    config(['sql-dashboard.connection' => null]);

    $this->get('/sql-dashboard')->assertOk()->assertSee('No read-only connection is set up');
});

it('registers the about entry and the schedule', function () {
    $this->artisan('about')->expectsOutputToContain('SQL Dashboard')->assertSuccessful();

    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);
    expect($events->filter(fn ($c) => str_contains((string) $c, 'sql-dashboard:run-checks'))->count())->toBe(1)
        ->and($events->filter(fn ($c) => str_contains((string) $c, 'sql-dashboard:prune-audit'))->count())->toBe(1);
});

it('verifies the connection from artisan', function () {
    $this->artisan('sql-dashboard:verify')->expectsOutputToContain('Ready: the connection is read-only')->assertSuccessful();

    config(['sql-dashboard.connection' => 'seed']);
    app(ConnectionVerifier::class)->forget();
    $this->artisan('sql-dashboard:verify')->expectsOutputToContain('Not ready')->assertFailed();
});
