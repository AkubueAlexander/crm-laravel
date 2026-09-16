<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    // Deliberately no `web:` routes file — API-only per 0.0. Sanctum's own
    // service provider registers /sanctum/csrf-cookie for us.
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 14.3: every exception maps to a consistent { message, errors, code }
        // JSON shape — no Blade/Livewire-flashed errors anywhere in this app.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(function (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'code' => 'validation_failed',
            ], 422);
        });

        $exceptions->render(function (HttpExceptionInterface $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'An error occurred.',
                'errors' => [],
                'code' => 'http_'.$e->getStatusCode(),
            ], $e->getStatusCode());
        });
    })->create();
