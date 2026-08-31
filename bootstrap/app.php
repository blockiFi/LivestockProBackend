<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['api', 'auth:sanctum', 'platform.admin'])
                ->prefix('api/admin')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'farm.role' => \App\Http\Middleware\EnsureFarmRole::class,
            'farm.member' => \App\Http\Middleware\CheckFarmMembership::class,
            'farm.subscribed' => \App\Http\Middleware\EnsureFarmSubscription::class,
            'farm.ai' => \App\Http\Middleware\EnsureAiEntitlement::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'platform.role' => \App\Http\Middleware\EnsurePlatformAdminRole::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('farm-tasks:generate')->hourly();
        $schedule->command('farm-tasks:mark-overdue')->everyFiveMinutes();
        $schedule->command('notifications:generate-reminders')->hourly();
        $schedule->command('notifications:process-reminders')->everyMinute();
        $schedule->command('notifications:escalate-overdue')->everyFiveMinutes();
        $schedule->command('notifications:retry-emails')->everyFiveMinutes();
        $schedule->command('notifications:dispatch-farm-alerts')->hourly();
        $schedule->command('notifications:dispatch-equipment-alerts')->hourly();
        $schedule->command('notifications:prune')->dailyAt('02:30');
        $schedule->command('subscriptions:check-status')->dailyAt('03:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
