<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt of defensive HTTP response headers. Most browsers already enforce
 * sensible defaults; these headers turn the defaults into explicit
 * contracts so a misconfigured proxy or compromised dependency cannot
 * silently weaken them.
 *
 *   X-Frame-Options             — block all iframe embedding (clickjacking)
 *   X-Content-Type-Options      — disable MIME sniffing
 *   Referrer-Policy             — drop full URL on cross-origin navigation
 *   Permissions-Policy          — deny powerful APIs we never use
 *   Strict-Transport-Security   — pin clients to HTTPS for 1 year (prod only)
 *
 * Headers are NOT sent on responses that already set them (e.g., a future
 * Content-Security-Policy package); we only fill in the blanks.
 */
class SecurityHeaders
{
    /** @var array<string,string> */
    private const HEADERS = [
        'X-Frame-Options'        => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // HSTS only when we're actually serving over HTTPS, otherwise we
        // emit a header browsers ignore but linting tools flag as wasted.
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
