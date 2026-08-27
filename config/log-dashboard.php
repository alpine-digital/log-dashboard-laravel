<?php

return [
    /*
     * The dashboard only mounts its routes when enabled. Enabled by default
     * only in local/development; anywhere else it is opt-in via
     * LOG_DASHBOARD_ENABLED=true. The service provider additionally hard-blocks
     * production/prod, so logs can never be exposed there.
     */
    'enabled' => env('LOG_DASHBOARD_ENABLED', in_array(env('APP_ENV'), ['local', 'development'], true)),

    /*
     * URL prefix the dashboard is served under, e.g. /log-dashboard.
     */
    'route_prefix' => env('LOG_DASHBOARD_ROUTE_PREFIX', 'log-dashboard'),

    /*
     * Directory the .log files are read from. Defaults to the host project's
     * own storage/logs.
     */
    'path' => env('LOG_DASHBOARD_PATH', storage_path('logs')),
];
