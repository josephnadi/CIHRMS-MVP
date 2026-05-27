<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        // Intentionally minimal — does NOT leak `app.env`, build SHA, package
        // versions, or any operational topology that helps an attacker shape
        // their next probe. M4 audit fix.
        return response()->json([
            'service' => config('app.name', 'CIHRMS'),
            'status'  => $dbOk ? 'ok' : 'degraded',
            'time'    => now()->toIso8601String(),
        ], $dbOk ? 200 : 503);
    }
}
