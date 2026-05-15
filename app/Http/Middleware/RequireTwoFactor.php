<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enrolment + challenge gate.
 *
 *   - `required` mode: roles flagged with `two_factor_required` must enroll
 *     before any non-2FA route resolves. Hits `/two-factor/enroll`.
 *   - `fresh:N` mode: a sensitive action must be preceded by a successful
 *     2FA challenge within N seconds. Hits `/two-factor/challenge`.
 */
class RequireTwoFactor
{
    public function __construct(private readonly TwoFactorService $totp) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();
        if (! $user) return $next($request);

        // Bypass for the 2FA routes themselves and the logout endpoint.
        if ($request->is('two-factor/*') || $request->is('logout')) {
            return $next($request);
        }

        if ($mode === 'required' && $user->two_factor_required && ! $user->two_factor_confirmed_at) {
            return redirect()->route('two-factor.enroll');
        }

        if ($mode === 'fresh') {
            if (! $user->two_factor_confirmed_at) {
                return redirect()->route('two-factor.enroll')
                    ->with('error', 'You must enrol in two-factor authentication before performing this action.');
            }
            if (! $this->totp->isFresh($user)) {
                return redirect()->route('two-factor.challenge', ['intended' => $request->fullUrl()]);
            }
        }

        return $next($request);
    }
}
