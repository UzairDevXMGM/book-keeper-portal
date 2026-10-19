<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCompanySubscribed;
use App\Http\Middleware\EnsureCustomer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'subscribed' => EnsureCompanySubscribed::class,
            'customer' => EnsureCustomer::class,
            'admin' => EnsureAdmin::class,
        ]);

        // Stripe posts webhooks without a CSRF token; Cashier verifies the Stripe signature instead
        $middleware->validateCsrfTokens(except: ['stripe/*']);

        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
