<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessHubtelWebhook;
use App\Models\HubtelWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class HubtelWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $eventId = (string) (data_get($payload, 'Data.TransactionId') ?? '');
        if ($eventId === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'missing TransactionId'], 200);
        }

        // Check-then-create so a replayed webhook doesn't trigger a UNIQUE-constraint
        // violation that aborts the whole request transaction — mirrors
        // PaystackWebhookController's handling of the same race.
        $existing = HubtelWebhookEvent::where('hubtel_event_id', $eventId)->first();
        if ($existing === null) {
            try {
                $event = HubtelWebhookEvent::create([
                    'hubtel_event_id'  => $eventId,
                    'client_reference' => (string) data_get($payload, 'Data.ClientReference'),
                    'status_text'      => (string) data_get($payload, 'Data.Status'),
                    'payload'          => $payload,
                    'signature'        => (string) $request->header('X-Hubtel-Signature'),
                ]);
                ProcessHubtelWebhook::dispatch($event->id);
            } catch (Throwable $e) {
                Log::info('Hubtel webhook insert race (safely ignored)', ['id' => $eventId, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['status' => 'received'], 200);
    }
}
