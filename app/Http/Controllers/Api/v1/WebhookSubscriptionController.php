<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        return response()->json([
            'data' => WebhookSubscription::orderBy('name')->get()->map(fn ($s) => [
                'id'                   => $s->id,
                'name'                 => $s->name,
                'target_url'           => $s->target_url,
                'event_types'          => $s->event_types,
                'is_active'            => (bool) $s->is_active,
                'consecutive_failures' => (int) $s->consecutive_failures,
                'last_success_at'      => optional($s->last_success_at)->toIso8601String(),
                'last_failure_at'      => optional($s->last_failure_at)->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'target_url'   => ['required', 'url', 'max:1000'],
            'event_types'  => ['required', 'array', 'min:1'],
            'event_types.*'=> ['string', 'regex:/^[a-z][a-z0-9_.\*]+$/'],
        ]);

        // Generate a 32-byte random signing secret. Returned ONCE in the response.
        $plaintextSecret = bin2hex(random_bytes(32));

        $sub = WebhookSubscription::create([
            'name'           => $data['name'],
            'target_url'     => $data['target_url'],
            'signing_secret' => $plaintextSecret,        // encrypted at rest by model cast
            'event_types'    => $data['event_types'],
            'is_active'      => true,
            'created_by'     => $request->user()->id,
        ]);

        return response()->json([
            'data'              => [
                'id'          => $sub->id,
                'name'        => $sub->name,
                'target_url'  => $sub->target_url,
                'event_types' => $sub->event_types,
            ],
            'signing_secret'    => $plaintextSecret,     // shown ONCE — store it client-side
            'verification_note' => 'POSTs include X-CIHRMS-Signature header: sha256=hex(hmac_sha256(body, signing_secret)).',
        ], 201);
    }

    public function destroy(Request $request, WebhookSubscription $subscription): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.manage'), 403);
        $subscription->delete();
        return response()->json(['deleted' => true]);
    }
}
