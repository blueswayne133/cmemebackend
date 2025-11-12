<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register alias for admin middleware
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'auth.admin' => \App\Http\Middleware\AuthenticateAdmin::class,
        ]);

        // Add admin middleware group
        $middleware->group('admin', [
            'auth:admin',
            'admin',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Illuminate\Console\Scheduling\Schedule $schedule) {
        // Process expired P2P trades every minute
        $schedule->command('p2p:process-expired')->everyMinute();
        
        // Optional: You can also run it less frequently if preferred
        // $schedule->command('p2p:process-expired')->everyFiveMinutes();
        // $schedule->command('p2p:process-expired')->hourly();
    })
    ->create();