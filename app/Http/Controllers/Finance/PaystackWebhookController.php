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
            Log::info('Paystack webhook replay or insert error (safely ignored)', [
                'paystack_event_id' => $paystackEventId,
                'error'             => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'received'], 200);
    }
}
