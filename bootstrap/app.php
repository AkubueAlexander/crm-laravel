<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
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

    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
        ]);

        $middleware->appendToPriorityList(
            after: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            append: ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
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
