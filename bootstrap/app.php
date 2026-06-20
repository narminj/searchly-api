<?php

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Role-based authorization gate (e.g. ->middleware('role:admin'))
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        // Unauthenticated web requests land on the login page
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Elasticsearch failures must reach API clients as clean JSON,
        // never as an HTML error page or a leaked exception message
        $exceptions->render(function (NoNodeAvailableException|ClientResponseException|ServerResponseException $e, Request $request) {
            if ($request->is('api/*')) {
                report($e);

                return response()->json(['message' => 'Search service unavailable.'], 503);
            }
        });
    })->create();
