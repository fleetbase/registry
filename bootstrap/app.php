<?php

use App\Commerce\Stripe;
use App\Http\Middleware\EnsurePackageLicence;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTokenScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\StatefulSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
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
            'staff' => EnsureStaff::class,
            'stateful' => StatefulSession::class,
        ]);

        // The website's cookie session must start before `auth:token,web` resolves the caller.
        $middleware->prependToPriorityList(EncryptCookies::class, StatefulSession::class);

        // Website pages render with Inertia. The console version and theme cookies are written
        // by the page's script, so they are not encrypted.
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->encryptCookies(except: [HandleInertiaRequests::CONSOLE_COOKIE]);
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

        // Stripe refused or could not be reached: a bad gateway (see Stripe::failure).
        $exceptions->render(function (ApiErrorException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);

                return response()->error(Stripe::failure($e), 502);
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

        // Website pages: errors render as the Inertia error page (not in debug mode for 500s, so
        // the stack trace stays visible locally). An expired CSRF token sends the visitor back.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) use ($isApi) {
            if ($isApi($request) || $request->is('up')) {
                return $response;
            }

            $status = $response->getStatusCode();
            if ($status === 419) {
                return back()->with('flash', ['type' => 'error', 'message' => 'The page expired. Please try again.']);
            }

            if (in_array($status, [403, 404], true) || (in_array($status, [500, 503], true) && ! app()->hasDebugModeEnabled())) {
                return Inertia::render('Error', [
                    'status' => $status,
                    // A 403 shows its reason (abort(403, '…')), not the framework's generic one.
                    'message' => $status === 403 && ! in_array($e->getMessage(), ['', 'This action is unauthorized.'], true) ? $e->getMessage() : null,
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
