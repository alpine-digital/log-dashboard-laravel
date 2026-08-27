<?php

use AlpineDigital\LogDashboard\Http\LogDashboardController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/api/logs', [LogDashboardController::class, 'logs'])->name('logs');
Route::match(['get', 'post'], '/api/log-content', [LogDashboardController::class, 'logContent'])->name('log-content');

// Everything else serves the built SPA: a real dist file if the path maps to
// one, otherwise the index shell (so client-side routes like /logs work).
Route::get('/{path?}', [LogDashboardController::class, 'serve'])
    ->where('path', '.*')
    ->name('serve');
