<?php

namespace App\Http\Middleware;

use App\Jobs\WriteAuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditTrail
{
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        '_token',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->user()) {
            return $response;
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $payload = collect($request->except(self::SENSITIVE_FIELDS))
            ->map(fn (mixed $value): mixed => is_string($value) && mb_strlen($value) > 500
                ? mb_substr($value, 0, 500).'...'
                : $value
            )
            ->all();

        WriteAuditLog::dispatch([
            'user_id'    => $request->user()->id,
            'action'     => $request->route()?->getName() ?? 'unnamed-action',
            'route_name' => $request->route()?->getName(),
            'method'     => $request->method(),
            'path'       => '/'.$request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload'    => $payload,
        ]);

        return $response;
    }
}
