<?php

namespace App\Http\Middleware;

use App\Models\ApiTokenMetadata;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the incoming Sanctum token has the required ability (scope)
 * AND that the sidecar metadata permits the request:
 *   - token not revoked
 *   - token not expired
 *   - client IP inside `allowed_ip_cidrs` (if set)
 *
 * Usage in routes/api.php:
 *
 *   Route::middleware(['auth:sanctum', 'api.scope:payroll:read'])
 *        ->get('/payroll/runs', ...);
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Sanctum's built-in ability gate
        if (! $token->can($scope) && ! $token->can('*')) {
            return response()->json([
                'message' => 'Insufficient token scope.',
                'required' => $scope,
            ], 403);
        }

        // Sidecar checks (revocation, expiry, IP allowlist)
        $meta = ApiTokenMetadata::where('token_id', $token->id)->first();
        if ($meta) {
            if ($meta->isRevoked() || $meta->isExpired()) {
                return response()->json(['message' => 'Token revoked or expired.'], 401);
            }
            if (! empty($meta->allowed_ip_cidrs) && ! $this->ipAllowed($request->ip(), $meta->allowed_ip_cidrs)) {
                return response()->json(['message' => 'Source IP not permitted for this token.'], 403);
            }
        }

        return $next($request);
    }

    /** @param array<int, string> $cidrs */
    private function ipAllowed(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) return true;
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) return $ip === $cidr;

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;

        $mask = ((int) $bits === 0) ? 0 : (~0 << (32 - (int) $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
