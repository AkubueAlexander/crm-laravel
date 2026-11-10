<?php


use App\Http\Controllers\PublicForms\LeadFormPageController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['api', 'throttle:30,1'])
                ->group(base_path('routes/unsubscribe.php'));

            Route::middleware(['throttle:120,1'])
                ->get('f/{uuid}', LeadFormPageController::class)
                ->whereUuid('uuid');

            Route::middleware(['api', 'throttle:600,1'])
                ->group(base_path('routes/engagement.php'));
        },

    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->prepend(AssignRequestId::class);

        $middleware->statefulApi();


        $middleware->validateCsrfTokens(except: ['api/public/*']);

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

        $exceptions->render(function (AuthenticationException $e) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'errors' => [],
                'code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (HttpExceptionInterface $e) {
            $message = $e->getMessage() ?: 'An error occurred.';


            if ($e->getPrevious() instanceof ModelNotFoundException) {
                $message = 'Resource not found.';
            }

            return response()->json([
                'message' => $message,
                'errors' => [],
                'code' => 'http_'.$e->getStatusCode(),
            ], $e->getStatusCode(), $e->getHeaders());
        });


        $exceptions->render(function (\Throwable $e) {
            if (config('app.debug')) {
                return null;
            }

            return response()->json([
                'message' => 'Server error.',
                'errors' => [],
                'code' => 'server_error',
            ], 500);
        });
    })->create();
