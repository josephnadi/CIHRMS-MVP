<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Iterates every registered named route and verifies its controller class +
 * method exists. Cheap guard against stale `name('foo.bar')` references after
 * a refactor that renames or removes a controller action — a class of bug
 * that otherwise only surfaces in production when a user clicks the dead link.
 *
 * Closure-based routes (`Route::get('/x', fn () => …)`) are skipped: there is
 * no class/method pair to verify and the closure was inlined at boot.
 */
it('resolves every named route to an existing controller method', function () {
    $broken = [];

    /** @var Route $route */
    foreach (RouteFacade::getRoutes() as $route) {
        // Skip routes without a `name(...)` — we only care about named handles
        // that other code can reference and silently break.
        $name = $route->getName();
        if (! $name) continue;

        // Skip framework-generated names like 'generated::abc123' (Laravel
        // assigns these to unnamed routes for internal bookkeeping).
        if (str_starts_with($name, 'generated::')) continue;

        $action = $route->getAction();
        $uses = $action['uses'] ?? null;

        // Closure routes have a Closure in 'uses' — nothing to verify.
        if (! is_string($uses)) continue;

        // 'uses' is "App\Http\Controllers\Foo@bar" or "App\Http\Controllers\Foo"
        if (str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses, 2);
        } else {
            $class  = $uses;
            $method = '__invoke';
        }

        if (! class_exists($class)) {
            $broken[] = "{$name}: class {$class} not found";
            continue;
        }

        if (! method_exists($class, $method)) {
            $broken[] = "{$name}: {$class}::{$method}() not found";
        }
    }

    expect($broken)
        ->toBe([], "Broken route → controller bindings:\n  - " . implode("\n  - ", $broken));
});
