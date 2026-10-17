<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureAccountUsable;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetApiCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetCurrentCompany::class,
            EnsureAccountUsable::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->api(prepend: [SetApiCompany::class]);
        // The tenant must be known before route-model binding runs its (tenant-scoped) queries.
        $middleware->prependToPriorityList(before: Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: SetCurrentCompany::class);
        $middleware->alias([
            'perm' => RequirePermission::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport([BusinessRuleException::class]);
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'field' => $e->field, 'details' => $e->details], 422);
            }

            return back()->withErrors([$e->field ?? 'business' => $e->getMessage()])->with('error', $e->getMessage())->withInput();
        });
        $exceptions->render(function (Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'validation_failed', 'errors' => $e->errors()], 422);
            }

            return null;
        });
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $code = match ($e->getStatusCode()) { 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 429 => 'too_many_requests', default => 'http_error' };

                return response()->json(['message' => $e->getMessage() ?: Symfony\Component\HttpFoundation\Response::$statusTexts[$e->getStatusCode()] ?? 'Error', 'code' => $code], $e->getStatusCode());
            }

            return null;
        });
        $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Sign in first.', 'code' => 'unauthenticated'], 401);
            }

            return null;
        });
        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json(['message' => $e->getMessage() ?: 'You do not have permission to do this.', 'code' => 'forbidden'], 403);
            }

            return null;
        });
    })->create();
