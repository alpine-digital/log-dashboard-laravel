<?php

namespace AlpineDigital\LogDashboard;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LogDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-dashboard.php', 'log-dashboard');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/log-dashboard.php' => config_path('log-dashboard.php'),
        ], 'log-dashboard-config');

        if (! $this->dashboardShouldMount()) {
            return;
        }

        // Deliberately no 'web' middleware group: this read-only dev viewer
        // needs no session/CSRF, and avoiding it means the package works even
        // in a project without an APP_KEY and doesn't 419 on its POST calls.
        Route::group([
            'prefix' => config('log-dashboard.route_prefix', 'log-dashboard'),
            'as' => 'log-dashboard.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * The dashboard exposes log contents over HTTP, so it must never mount in a
     * production-like environment — regardless of the config flag.
     */
    private function dashboardShouldMount(): bool
    {
        if (in_array(strtolower((string) $this->app->environment()), ['production', 'prod'], true)) {
            return false;
        }

        return (bool) config('log-dashboard.enabled', false);
    }
}
