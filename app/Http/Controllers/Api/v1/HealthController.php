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

        return response()->json([
            'service'   => config('app.name', 'CIHRMS'),
            'version'   => config('app.version', 'unknown'),
            'env'       => config('app.env'),
            'time'      => now()->toIso8601String(),
            'database'  => $dbOk ? 'ok' : 'unreachable',
            'api'       => ['version' => 'v1'],
        ], $dbOk ? 200 : 503);
    }
}
