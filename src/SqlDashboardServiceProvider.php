<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard;

use AmjadIqbal\SqlDashboard\Console\PruneAuditCommand;
use AmjadIqbal\SqlDashboard\Console\RunChecksCommand;
use AmjadIqbal\SqlDashboard\Console\VerifyCommand;
use AmjadIqbal\SqlDashboard\Execution\ConnectionVerifier;
use AmjadIqbal\SqlDashboard\Execution\Runner;
use AmjadIqbal\SqlDashboard\Http\Authorize;
use AmjadIqbal\SqlDashboard\Http\Controllers\AuditController;
use AmjadIqbal\SqlDashboard\Http\Controllers\CheckController;
use AmjadIqbal\SqlDashboard\Http\Controllers\DashboardController;
use AmjadIqbal\SqlDashboard\Http\Controllers\HomeController;
use AmjadIqbal\SqlDashboard\Http\Controllers\QueryController;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class SqlDashboardServiceProvider extends ServiceProvider
{
    public const ABILITIES = ['viewSqlDashboard', 'runSqlDashboardQueries', 'editSqlDashboardQueries'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sql-dashboard.php', 'sql-dashboard');
        $this->app->singleton(ConnectionVerifier::class);
        $this->app->singleton(Runner::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sql-dashboard');

        if (config('sql-dashboard.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/sql-dashboard.php' => config_path('sql-dashboard.php')], 'sql-dashboard-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'sql-dashboard-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/sql-dashboard')], 'sql-dashboard-views');
            $this->commands([RunChecksCommand::class, PruneAuditCommand::class, VerifyCommand::class]);
        }

        // Local only until the app defines its own gates (app providers boot after this one, so theirs win).
        // editSqlDashboardQueries also needs a signed-in user; the middleware enforces that whatever the gate says.
        foreach (self::ABILITIES as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user = null): bool => $this->app->environment('local'));
            }
        }

        $this->registerRoutes();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('sql-dashboard.schedule')) {
                $schedule->command('sql-dashboard:run-checks')->everyMinute()->withoutOverlapping()->name('sql-dashboard:run-checks');
                $schedule->command('sql-dashboard:prune-audit')->daily()->name('sql-dashboard:prune-audit');
            }
        });

        AboutCommand::add('SQL Dashboard', fn (): array => [
            'Version' => self::version(),
            'Path' => '/'.trim((string) config('sql-dashboard.path'), '/'),
            'Connection' => config('sql-dashboard.connection') ?: 'not set (SQL_DASHBOARD_CONNECTION)',
            'Support' => 'https://amjad.com.pk/contact?utm_source=artisan&utm_medium=about&utm_campaign=laravel-sql-dashboard',
        ]);
    }

    private function registerRoutes(): void
    {
        $middleware = array_values(array_filter((array) config('sql-dashboard.middleware', ['web']), 'is_string'));
        $view = Authorize::class.':viewSqlDashboard';
        $run = Authorize::class.':runSqlDashboardQueries';
        $edit = Authorize::class.':editSqlDashboardQueries';

        Route::group([
            'prefix' => trim((string) config('sql-dashboard.path', 'sql-dashboard'), '/'),
            'domain' => config('sql-dashboard.domain'),
            'middleware' => [...$middleware, $view, SubstituteBindings::class],
            'as' => 'sql-dashboard.',
        ], function () use ($run, $edit): void {
            Route::get('/', HomeController::class)->name('home');

            Route::get('queries/create', [QueryController::class, 'create'])->middleware($edit)->name('queries.create');
            Route::post('queries', [QueryController::class, 'store'])->middleware($edit)->name('queries.store');
            Route::get('queries/{query}', [QueryController::class, 'show'])->whereNumber('query')->name('queries.show');
            Route::get('queries/{query}/export', [QueryController::class, 'export'])->whereNumber('query')->middleware($run)->name('queries.export');
            Route::get('queries/{query}/edit', [QueryController::class, 'edit'])->whereNumber('query')->middleware($edit)->name('queries.edit');
            Route::put('queries/{query}', [QueryController::class, 'update'])->whereNumber('query')->middleware($edit)->name('queries.update');
            Route::delete('queries/{query}', [QueryController::class, 'destroy'])->whereNumber('query')->middleware($edit)->name('queries.destroy');

            Route::get('dashboards/create', [DashboardController::class, 'create'])->middleware($edit)->name('dashboards.create');
            Route::post('dashboards', [DashboardController::class, 'store'])->middleware($edit)->name('dashboards.store');
            Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->whereNumber('dashboard')->name('dashboards.show');
            Route::get('dashboards/{dashboard}/edit', [DashboardController::class, 'edit'])->whereNumber('dashboard')->middleware($edit)->name('dashboards.edit');
            Route::put('dashboards/{dashboard}', [DashboardController::class, 'update'])->whereNumber('dashboard')->middleware($edit)->name('dashboards.update');
            Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->whereNumber('dashboard')->middleware($edit)->name('dashboards.destroy');

            Route::get('checks', [CheckController::class, 'index'])->middleware($edit)->name('checks.index');
            Route::get('checks/create', [CheckController::class, 'create'])->middleware($edit)->name('checks.create');
            Route::post('checks', [CheckController::class, 'store'])->middleware($edit)->name('checks.store');
            Route::get('checks/{check}/edit', [CheckController::class, 'edit'])->whereNumber('check')->middleware($edit)->name('checks.edit');
            Route::put('checks/{check}', [CheckController::class, 'update'])->whereNumber('check')->middleware($edit)->name('checks.update');
            Route::delete('checks/{check}', [CheckController::class, 'destroy'])->whereNumber('check')->middleware($edit)->name('checks.destroy');
            Route::post('checks/{check}/run', [CheckController::class, 'run'])->whereNumber('check')->middleware([$edit, $run])->name('checks.run');

            Route::get('audit', AuditController::class)->middleware($edit)->name('audit');
        });
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('amjadiqbal/laravel-sql-dashboard') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
