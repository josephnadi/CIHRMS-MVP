<?php

namespace App\Http\Middleware;

use App\Jobs\WriteAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
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

        // Explicit `web` guard resolution. Bare `$request->user()` defaults
        // to whichever guard is named on the route's `auth:` middleware —
        // on member-portal routes (member guard) we don't audit because
        // audit_logs.user_id is FK-constrained to the `users` table.
        $user = $request->user('web');
        if (! $user) {
            return $response;
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $payload = $this->sanitize($request->except(self::SENSITIVE_FIELDS));

        // dispatchAfterResponse runs the job in the same PHP process AFTER
        // the response is flushed — no queue worker required. Previously
        // this used ::dispatch(), which queued to the `audit` queue and
        // silently dropped every event in environments without a worker.
        WriteAuditLog::dispatchAfterResponse([
            'user_id'    => $user->id,
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

    /**
     * Recursively replace UploadedFile instances with serializable descriptors
     * and truncate long strings. Queued jobs can't serialize UploadedFile.
     */
    private function sanitize(mixed $value): mixed
    {
        if ($value instanceof SymfonyUploadedFile) {
            return [
                '__file'   => true,
                'name'     => $value instanceof UploadedFile ? $value->getClientOriginalName() : $value->getFilename(),
                'mime'     => $value->getClientMimeType(),
                'size'     => $value->getSize(),
            ];
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->sanitize($v), $value);
        }

        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500).'...';
        }

        return $value;
    }
}
