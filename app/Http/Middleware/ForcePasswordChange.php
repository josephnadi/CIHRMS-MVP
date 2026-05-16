<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->password_must_change && ! $this->isAllowedRoute($request)) {
            if ($request->expectsJson()) {
                abort(403, 'Password change required before continuing.');
            }
            return redirect()
                ->route('profile.edit')
                ->withFragment('security')
                ->with('error', 'Please set a new password before continuing.');
        }

        return $next($request);
    }

    private function isAllowedRoute(Request $request): bool
    {
        $allowed = [
            'profile.edit',
            'profile.update',
            'profile.personal',
            'profile.password',
            'logout',
            'password.confirm',
        ];

        $name = $request->route()?->getName();
        return $name !== null && in_array($name, $allowed, true);
    }
}
