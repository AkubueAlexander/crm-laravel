<?php

use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
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
            Route::middleware(['api', 'auth:sanctum', 'resolve.tenant'])
                ->prefix('api/v1')
                ->group(base_path('routes/notifications.php'));

            // 9.6c-1: manual enrollment into an active automation (SPA, authenticated).
            Route::middleware(['api', 'auth:sanctum', 'resolve.tenant'])
                ->prefix('api/v1')
                ->group(function () {
                    Route::post('automations/{automation}/enroll', [\App\Http\Controllers\Api\Marketing\AutomationEnrollmentController::class, 'store'])
                        ->whereNumber('automation');
                });

            // 14.1: the SPA reports uncaught frontend errors (authenticated, throttled per user).
            Route::middleware(['api', 'auth:sanctum', 'resolve.tenant', 'throttle:20,1'])
                ->prefix('api/v1')
                ->group(function () {
                    Route::post('client-errors', [ClientErrorController::class, 'store']);
                });

            // 9.4c: public unsubscribe pages (the signed token is the credential: no session, no CSRF, throttled).
            Route::middleware(['api', 'throttle:30,1'])
                ->group(base_path('routes/unsubscribe.php'));

            // 9.8a-2: public lead-capture forms (unauthenticated; throttled per route; CAPTCHA + honeypot inside the action).
            Route::middleware(['api'])
                ->prefix('api/public')
                ->group(base_path('routes/lead_forms_public.php'));

            // 9.8c: public lead form page (serves the built standalone entry; frameable, no session, no CSRF).
            Route::middleware(['throttle:120,1'])
                ->get('f/{uuid}', \App\Http\Controllers\PublicForms\LeadFormPageController::class)
                ->whereUuid('uuid');

            // 9.5: public tracking pixel, click redirect and the signed provider webhook.
            Route::middleware(['api', 'throttle:600,1'])
                ->group(base_path('routes/engagement.php'));
        },

    )
    ->withMiddleware(function (Middleware $middleware) {
        // 14.1: correlation id on every request/response.
        $middleware->prepend(AssignRequestId::class);

        $middleware->statefulApi();

        // 9.8a-2: public lead forms carry no session and no credentials, so they take no CSRF token.
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

        // 14.3: every error leaves the API as { message, errors, code }.
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

            // Laravel converts ModelNotFoundException into a 404 whose message names the model class: do not leak it.
            if ($e->getPrevious() instanceof ModelNotFoundException) {
                $message = 'Resource not found.';
            }

            return response()->json([
                'message' => $message,
                'errors' => [],
                'code' => 'http_'.$e->getStatusCode(),
            ], $e->getStatusCode(), $e->getHeaders());
        });

        // Anything unhandled: a generic 500 in production. In debug, fall through to Laravel's detailed render.
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
