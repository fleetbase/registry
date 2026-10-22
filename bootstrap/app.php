<?php

use App\Http\Middleware\EnsurePackageLicence;
use App\Http\Middleware\EnsureTokenScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'scope' => EnsureTokenScope::class,
            'licence' => EnsurePackageLicence::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isPackage = fn (Request $request) => $request->is('npm', 'npm/*', 'composer', 'composer/*');
        $isApi = fn (Request $request) => $request->is('v1/*') || $request->is('v1') || $isPackage($request) || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request));

        // Fleetbase-style error envelope: { "errors": ["..."] }
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->error($e->validator->errors()->all(), 422, ['fields' => $e->errors()]);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->error('Unauthenticated.', 401);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi, $isPackage) {
            if ($isPackage($request)) {
                return response()->packageError($e instanceof NotFoundHttpException && $e->getMessage() !== '' ? $e->getMessage() : 'Not found.', 404);
            }

            if ($isApi($request)) {
                return response()->error('Resource not found.', 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi, $isPackage) {
            if ($isPackage($request)) {
                $message = $e->getMessage() ?: (Response::$statusTexts[$e->getStatusCode()] ?? 'Request failed.');

                return response()->packageError($message, $e->getStatusCode(), $e->getHeaders());
            }

            if ($isApi($request)) {
                $message = $e->getMessage() ?: (Response::$statusTexts[$e->getStatusCode()] ?? 'Request failed.');

                return response()->error($message, $e->getStatusCode())->withHeaders($e->getHeaders());
            }
        });
    })->create();
