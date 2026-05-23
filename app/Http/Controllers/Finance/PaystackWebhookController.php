<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaystackWebhook;
use App\Models\PaystackWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $paystackEventId = (string) (data_get($payload, 'data.id') ?? '');
        if ($paystackEventId === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'missing data.id'], 200);
        }

        // Check-then-create so a replayed webhook doesn't trigger a UNIQUE-constraint
        // violation that — on Postgres — aborts the whole request transaction
        // (SQLite was forgiving; Postgres returns SQLSTATE[25P02] on subsequent
        // queries). A tiny race window between SELECT and INSERT is still
        // protected by the UNIQUE on paystack_event_id; if it fires we log and
        // return 200, which is the same outcome Paystack expects.
        $existing = PaystackWebhookEvent::where('paystack_event_id', $paystackEventId)->first();
        if ($existing === null) {
            try {
                $event = PaystackWebhookEvent::create([
                    'paystack_event_id'  => $paystackEventId,
                    'event_type'         => (string) data_get($payload, 'event'),
                    'paystack_reference' => (string) data_get($payload, 'data.reference'),
                    'payload'            => $payload,
                    'signature'          => (string) $request->header('X-Paystack-Signature'),
                ]);
                ProcessPaystackWebhook::dispatch($event->id);
            } catch (Throwable $e) {
                Log::info('Paystack webhook insert race (safely ignored)', [
                    'paystack_event_id' => $paystackEventId,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['status' => 'received'], 200);
    }
}
