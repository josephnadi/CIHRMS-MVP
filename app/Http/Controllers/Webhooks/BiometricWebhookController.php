<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Attendance\BiometricIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accepts vendor-neutral biometric clock-in/out events.
 *
 * Authenticated by `webhook.signature:biometric` middleware (HMAC-SHA256 over
 * "{X-Biometric-Timestamp}.{body}", keyed by the per-device shared_secret).
 *
 * Payload:
 * {
 *   "device_code": "DEV-ACCRA-MAIN-01",
 *   "events": [
 *     { "employee_no": "CIHRM-0002", "direction": "in",  "event_at": "2026-05-26T08:03:21+00:00" }
 *   ]
 * }
 */
class BiometricWebhookController extends Controller
{
    public function __construct(private readonly BiometricIngestionService $ingestion) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_code'             => ['required', 'string', 'max:64'],
            'events'                  => ['required', 'array', 'min:1', 'max:500'],
            'events.*.employee_no'    => ['required', 'string', 'max:64'],
            'events.*.direction'      => ['required', 'in:in,out'],
            'events.*.event_at'       => ['required', 'date'],
            'events.*.geo_lat'        => ['nullable', 'numeric'],
            'events.*.geo_lng'        => ['nullable', 'numeric'],
            'events.*.score'          => ['nullable', 'string'],
        ]);

        $result = $this->ingestion->ingest($data);

        return response()->json($result, 200);
    }
}
