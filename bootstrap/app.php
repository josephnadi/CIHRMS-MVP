<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // M2: member self-service portal routes. Loaded into the same `web`
        // middleware group as staff routes, so they get CSRF + session +
        // SecurityHeaders. The staff-only middleware (ForcePasswordChange,
        // RequireTwoFactor) resolve `$request->user()` against the default
        // `web` guard, which returns null for portal-authenticated members,
        // so they self-bypass without explicit configuration.
        then: function () {
            Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetUserLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\ForcePasswordChange::class,
            // Global gate: any authenticated user flagged `two_factor_required`
            // is bounced to enrolment until `two_factor_confirmed_at` is set.
            // The middleware self-bypasses on /two-factor/* and /logout so the
            // gate can never lock the user out of completing it.
            \App\Http\Middleware\RequireTwoFactor::class,
            // Default-deny security headers (X-Frame-Options DENY, nosniff,
            // Referrer-Policy, Permissions-Policy, HSTS on HTTPS). M2 audit fix.
            \App\Http\Middleware\SecurityHeaders::class,
            // Audit trail — captures every authenticated mutating request
            // (POST/PUT/PATCH/DELETE) on the `web` guard into the hash-chained
            // audit_logs table. Self-bypasses GETs and unauthenticated
            // requests. Lives globally so coverage is total, not per-group.
            \App\Http\Middleware\AuditTrail::class,
        ]);

        // Sanctum-authenticated API routes get the same audit coverage as web.
        $middleware->api(append: [
            \App\Http\Middleware\AuditTrail::class,
        ]);

        // CSRF cannot apply to the SAML ACS — the IdP POSTs the assertion
        // directly to our endpoint, so it has no way to include a Laravel
        // CSRF token. Signature verification (via onelogin/php-saml in
        // SamlSsoAdapter) is what protects this route instead.
        $middleware->validateCsrfTokens(except: [
            'auth/sso/*/callback',
        ]);

        $middleware->alias([
            'role'              => \App\Http\Middleware\EnsureRole::class,
            'permission'        => \App\Http\Middleware\EnsurePermission::class,
            'webhook.signature' => \App\Http\Middleware\VerifyWebhookSignature::class,
            'paystack.signature' => \App\Http\Middleware\VerifyPaystackSignature::class,
            '2fa'               => \App\Http\Middleware\RequireTwoFactor::class,
            'api.scope'         => \App\Http\Middleware\RequireApiScope::class,
        ]);

        $middleware->throttleApi('60,1');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for API routes on auth failures
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Return JSON for API routes on validation failures
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // Return JSON for API routes on HTTP exceptions (403, 404, etc.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: 'Error.'], $e->getStatusCode());
            }
        });

        // A service-layer DomainException is a business-rule guard (e.g. "cannot
        // archive an account with children", "only a calculated run can be
        // approved"), not a server fault. Surface it as a friendly message
        // instead of a raw 500: redirect back with a flash error on the web,
        // 422 JSON on the API. Controllers may still catch it locally for
        // field-specific errors; this is the safety net for the rest.
        $exceptions->render(function (\DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: 'Operation not allowed.'], 422);
            }

            return back()->with('error', $e->getMessage());
        });
    })->create();
