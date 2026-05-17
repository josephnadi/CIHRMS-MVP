<?php

namespace App\Http\Middleware;

use App\Services\I18n\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the resolved locale to App + Carbon for the rest of the request.
 * Registered as a global `web` middleware so every Inertia/Blade render and
 * every translation lookup honours the recipient's chosen language.
 */
class SetUserLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->resolveFromRequest($request);

        app()->setLocale($locale);
        // Carbon for diffForHumans / formatLocalized
        try { \Carbon\Carbon::setLocale($locale); } catch (\Throwable $e) { /* fall through */ }

        return $next($request);
    }
}
