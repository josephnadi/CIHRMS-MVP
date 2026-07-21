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
        $txId    = (string) (data_get($payload, 'Data.TransactionId') ?? '');
        if ($txId === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'missing TransactionId'], 200);
        }

        // Composite key (TransactionId + Status): Hubtel may send more than one
        // callback per transfer under the same TransactionId (e.g. an interim
        // status followed by a final one). Keying dedup on TransactionId alone
        // would silently drop the later callback, potentially leaving a row
        // stuck un-settled. Keying on TransactionId+Status lets each distinct
        // status reported for a transfer be processed once, while an exact
        // repeat (same TransactionId, same Status) still dedupes as before. The
        // processor's terminal-state guard (Settled/Reversed) is what prevents
        // a stale/duplicate status from ever undoing a settled row.
        $eventId = $txId.':'.strtolower((string) data_get($payload, 'Data.Status'));

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
