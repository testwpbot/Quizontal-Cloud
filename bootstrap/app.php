<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Keep the domain TLD price table inside FOSSBilling in sync with the
        // upstream Porkbun catalog (daily; harmless when domains are unused).
        $schedule->command('fossbilling:sync-domains')->dailyAt('03:10')->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
