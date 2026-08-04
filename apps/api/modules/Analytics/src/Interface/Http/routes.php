<?php

declare(strict_types=1);

use EruoFood\Analytics\Interface\Http\Controller\DashboardController;
use EruoFood\Analytics\Interface\Http\Controller\ReportController;
use EruoFood\Analytics\Interface\Http\Controller\ScheduledReportController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Analytics routes — Analytics, Business Intelligence & Reporting
| (mounted under /api/v1 by the module provider). All paths are under
| "analytics"; company-wide dashboards & reports are admin-only, while a
| vendor/restaurant owner can read their own scoped dashboard.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/analytics')->middleware('auth.jwt')->group(function (): void {
    // ---- Scoped (vendor/restaurant owner) ----
    Route::get('dashboards/vendor', [DashboardController::class, 'scoped'])->defaults('type', 'vendor');
    Route::get('dashboards/restaurant', [DashboardController::class, 'scoped'])->defaults('type', 'restaurant');

    // ---- Admin (RBAC) ----
    Route::middleware('role:admin')->group(function (): void {
        Route::get('dashboards/{type}', [DashboardController::class, 'show'])
            ->where('type', 'executive|operations|finance|admin');
        Route::get('kpis', [DashboardController::class, 'kpis']);

        Route::get('reports', [ReportController::class, 'recent']);
        Route::get('reports/catalogue', [ReportController::class, 'catalogue']);
        Route::post('reports', [ReportController::class, 'store']);
        Route::get('reports/{id}', [ReportController::class, 'show']);
        Route::get('reports/{id}/export', [ReportController::class, 'export']);

        Route::get('scheduled-reports', [ScheduledReportController::class, 'index']);
        Route::post('scheduled-reports', [ScheduledReportController::class, 'store']);
        Route::post('scheduled-reports/run-due', [ScheduledReportController::class, 'run']);
        Route::post('scheduled-reports/{id}/deactivate', [ScheduledReportController::class, 'deactivate']);
    });
});
