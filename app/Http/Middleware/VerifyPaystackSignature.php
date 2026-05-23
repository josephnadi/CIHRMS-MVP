<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Paystack webhook signatures via HMAC-SHA512.
 *
 * Paystack signs each webhook with the merchant's webhook secret and sends
 * the result in the `X-Paystack-Signature` header. We compute the same
 * HMAC over the raw request body and compare with `hash_equals` (constant-
 * time) to prevent timing attacks.
 *
 * Reads RAW body bytes via Request::getContent() — must happen BEFORE
 * Laravel parses JSON, so this middleware should be applied at the route
 * level (not after web middleware that mutates the request).
 */
class VerifyPaystackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret    = (string) config('services.paystack.webhook_secret');
        $signature = (string) $request->header('X-Paystack-Signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $computed = hash_hmac('sha512', $request->getContent(), $secret);

        if (! hash_equals($computed, $signature)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }
}
