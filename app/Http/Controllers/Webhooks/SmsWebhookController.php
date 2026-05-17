<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InboundSms;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound SMS dispatcher — handles two webhook flavours:
 *
 *   - "delivery_receipt" — the provider tells us a previously-sent SMS
 *     reached the handset. We flip Sent → Delivered.
 *
 *   - "inbound_message"  — a staff member texted the short code. We persist
 *     it and (optionally, future work) parse intent for SMS-driven flows
 *     like "PAYSLIP GH-HR-001 1234" returning a payslip summary.
 */
class SmsWebhookController extends Controller
{
    public function __construct(private readonly SmsDispatcher $dispatcher) {}

    public function handle(Request $request): Response
    {
        $type = $request->input('type', 'inbound_message');

        if ($type === 'delivery_receipt') {
            $messageId = (string) $request->input('messageId', '');
            if ($messageId !== '') {
                $this->dispatcher->markDelivered($messageId);
            }
            return response('OK', 200);
        }

        $data = $request->validate([
            'from'      => ['required', 'string', 'max:32'],
            'to'        => ['nullable', 'string', 'max:32'],
            'body'      => ['required', 'string', 'max:1600'],
            'messageId' => ['nullable', 'string', 'max:64'],
            'provider'  => ['nullable', 'string', 'max:32'],
        ]);

        InboundSms::create([
            'from_phone'          => $data['from'],
            'to_shortcode'        => $data['to'] ?? '',
            'body'                => $data['body'],
            'provider'            => $data['provider'] ?? 'hubtel',
            'provider_message_id' => $data['messageId'] ?? null,
            'parsed_intent'       => $this->parseIntent($data['body']),
            'received_at'         => now(),
        ]);

        return response('OK', 200);
    }

    /**
     * Very simple keyword parser. Inbound shape examples:
     *   "PAYSLIP"        → PAYSLIP
     *   "LEAVE"          → LEAVE
     *   "STOP"           → OPTOUT
     *   "TRACK XXXX-..." → TRACK
     */
    private function parseIntent(string $body): ?string
    {
        $first = strtoupper(trim(explode(' ', trim($body))[0] ?? ''));
        return match ($first) {
            'PAYSLIP'        => 'PAYSLIP',
            'LEAVE'          => 'LEAVE',
            'CLOCKIN', 'IN'  => 'CLOCK_IN',
            'CLOCKOUT','OUT' => 'CLOCK_OUT',
            'TRACK'          => 'TRACK',
            'STOP', 'UNSUB'  => 'OPTOUT',
            default          => null,
        };
    }
}
