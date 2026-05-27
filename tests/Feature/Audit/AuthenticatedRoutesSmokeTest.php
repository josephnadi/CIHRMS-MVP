<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Smoke test: hit every parameter-less authenticated GET route as super_admin
 * and assert no 500. This is the runtime-equivalent of the static-analysis V2
 * audit — catches prop-shape mismatches, controller exceptions, missing view
 * partials, and any other failure mode that only surfaces when a route
 * actually fires its closure.
 *
 * Parameter-bound routes (`/foo/{bar}`) are skipped — they need real model
 * fixtures and are covered by their own feature tests.
 *
 * If a real-but-tolerable failure mode surfaces (e.g. a route requires
 * 2FA-fresh and 302s to /two-factor/refresh), it's NOT a 500 — those are
 * allowed. We only fail on 5xx.
 */

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

test('every parameter-less authenticated GET route returns non-5xx for super_admin', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    // Mark 2FA as confirmed + fresh so routes gated by `2fa:fresh` work.
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(\App\Services\Auth\TwoFactorService::class)->markFresh($admin);

    $failures = [];
    $tested   = 0;
    $skipped  = 0;

    foreach (RouteFacade::getRoutes() as $route) {
        /** @var Route $route */
        $methods    = $route->methods();
        $middleware = $route->gatherMiddleware();
        $uri        = $route->uri();

        // Only authenticated GETs
        if (! in_array('GET', $methods, true)) {
            continue;
        }
        if (! in_array('auth', $middleware, true) && ! in_array('web', $middleware, true)) {
            continue;
        }
        // Parameter-bound routes need fixtures; out of scope for smoke test
        if (str_contains($uri, '{')) {
            $skipped++;
            continue;
        }
        // Skip API routes (separate auth model)
        if (str_starts_with($uri, 'api/')) {
            $skipped++;
            continue;
        }
        // Skip routes that are mounted under any explicit non-auth bucket
        // (sso/, careers/ public surface, etc.) — those are tested elsewhere
        if (str_starts_with($uri, 'auth/sso')) {
            $skipped++;
            continue;
        }
        // Skip the storage route (returns binary files; not a smoke target)
        if (str_starts_with($uri, 'storage/') || $uri === 'sanctum/csrf-cookie') {
            $skipped++;
            continue;
        }

        $url = '/' . ltrim($uri, '/');
        $response = $this->actingAs($admin)->get($url);
        $status = $response->status();

        $tested++;

        if ($status >= 500) {
            // Surface enough context to diagnose: status + route name + first
            // 200 chars of the response body (which usually contains the
            // exception class + message in dev mode).
            $bodyExcerpt = mb_substr((string) $response->getContent(), 0, 200);
            $failures[] = sprintf(
                '[%d] %s (%s) — %s',
                $status,
                $url,
                $route->getName() ?? 'unnamed',
                preg_replace('/\s+/', ' ', $bodyExcerpt),
            );
        }
    }

    // Always log how much we actually exercised so a green test isn't
    // mistakenly silent.
    expect($tested)->toBeGreaterThan(30, "Expected to smoke-test >30 routes; only hit {$tested}. Something narrowed the filter too aggressively.");

    if ($failures !== []) {
        throw new \RuntimeException(
            "Smoke test found {$tested} routes; " . count($failures) . " returned 5xx:\n"
            . implode("\n", $failures)
        );
    }
});
