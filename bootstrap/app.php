<?php

use App\Http\Middleware\EnsureClientPortalAccess;
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'feature' => EnsureFeatureAccess::class,
            'client' => EnsureClientPortalAccess::class,
        ]);
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('files:expire')->daily();
        $schedule->command('backup:remind')->dailyAt('09:00');
        $schedule->command('notifications:content-daily')->dailyAt('07:30');
        $schedule->command('notifications:shoot-reminders')->dailyAt('08:00');
        $schedule->command('notifications:deadline-reminders')->dailyAt('08:30');
        $schedule->command('notifications:contract-expiry')->dailyAt('09:00');
        $schedule->command('client:expiry-followup')->dailyAt('09:30');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
