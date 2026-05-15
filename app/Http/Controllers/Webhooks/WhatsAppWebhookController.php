<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\LeaveRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Inbound WhatsApp Cloud webhook.
 *
 * Handles two flows:
 *   1. Meta GET handshake (`hub.challenge`).
 *   2. Inbound message → parse keyword → create draft Ticket / LeaveRequest.
 *
 * Recognised keywords (case-insensitive, first token):
 *   "LEAVE 3 SICK"            → leave draft, 3 days, sick
 *   "LEAVE 5 ANNUAL FROM 22"  → leave draft, 5 days, annual, starting day-of-month 22
 *   "TICKET printer broken"   → ticket draft, normal priority
 *   "URGENT printer broken"   → ticket draft, high priority
 *   anything else             → just logs the message; HR can pick it up later
 *
 * The phone number on the inbound message is matched against `users.whatsapp_phone`
 * to resolve the sender. Unknown senders' messages are still logged but produce no draft.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(protected IntegrationManager $integrations) {}

    public function handle(Request $request): Response|JsonResponse
    {
        // Meta GET verification handshake
        if ($request->isMethod('get')) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        $payload = $request->json()->all() ?: [];
        $integration = Integration::query()->where('provider', 'whatsapp_cloud')->first();

        $event = $integration
            ? IntegrationEvent::create([
                'integration_id' => $integration->id,
                'direction'      => IntegrationEvent::DIRECTION_INBOUND,
                'event_type'     => 'whatsapp.message_received',
                'payload'        => $payload,
                'status'         => IntegrationEvent::STATUS_RECEIVED,
                'processed_at'   => now(),
            ])
            : null;

        try {
            // status callbacks (delivered/read) come down the same webhook — ignore them
            if (data_get($payload, 'entry.0.changes.0.value.statuses')) {
                return response()->json(['ok' => true, 'note' => 'status update'], 200);
            }

            $driver = $this->integrations->driver('whatsapp_cloud', 'messaging');
            $message = $driver->parseInbound($payload);

            if (! $message || $message->body === '') {
                return response()->json(['ok' => true, 'note' => 'no actionable message'], 200);
            }

            $sender = $this->resolveSender($message->from);
            $this->routeKeyword($message->body, $sender, $event);

            return response()->json(['ok' => true, 'event_id' => $event?->id], 200);
        } catch (\Throwable $e) {
            $event?->markFailed($e->getMessage());
            return response()->json(['ok' => false], 200);
        }
    }

    protected function resolveSender(string $msisdn): ?User
    {
        // Stored as bare digits — strip any non-digits before lookup.
        $digits = preg_replace('/\D+/', '', $msisdn);
        return User::query()
            ->whereNotNull('whatsapp_phone')
            ->whereRaw("REPLACE(REPLACE(REPLACE(whatsapp_phone, '+', ''), '-', ''), ' ', '') = ?", [$digits])
            ->first();
    }

    protected function routeKeyword(string $body, ?User $sender, ?IntegrationEvent $event): void
    {
        $tokens = preg_split('/\s+/', trim($body));
        $first  = strtoupper((string) ($tokens[0] ?? ''));

        if ($first === 'LEAVE' && $sender?->employee) {
            $days = max(1, (int) ($tokens[1] ?? 1));
            $type = $this->parseLeaveType($tokens[2] ?? null);
            $start = $this->parseStartDate($body);

            LeaveRequest::create([
                'employee_id' => $sender->employee->id,
                'type'        => $type->value,
                'reason'      => "(via WhatsApp) {$body}",
                'start_date'  => $start->toDateString(),
                'end_date'    => $start->copy()->addDays($days - 1)->toDateString(),
                'status'      => LeaveStatus::Pending->value,
            ]);

            $event?->update(['event_type' => 'whatsapp.leave_drafted']);
            return;
        }

        if (in_array($first, ['TICKET', 'URGENT', 'HELP', 'SUPPORT'], true) && $sender?->employee) {
            $title = trim((string) substr($body, strlen($tokens[0] ?? '')));
            Ticket::create([
                'employee_id' => $sender->employee->id,
                'title'       => $title !== '' ? \Illuminate\Support\Str::limit($title, 100, '') : 'WhatsApp ticket',
                'description' => "(via WhatsApp) {$body}",
                'priority'    => $first === 'URGENT' ? TicketPriority::High->value : TicketPriority::Normal->value,
                'status'      => TicketStatus::Open->value,
            ]);

            $event?->update(['event_type' => 'whatsapp.ticket_drafted']);
            return;
        }
    }

    protected function parseLeaveType(?string $token): LeaveType
    {
        return match (strtolower((string) $token)) {
            'sick'                       => LeaveType::Sick,
            'maternity'                  => LeaveType::Maternity,
            'paternity'                  => LeaveType::Paternity,
            'unpaid'                     => LeaveType::Unpaid,
            'emergency', 'urgent'        => LeaveType::Emergency,
            'study', 'training'          => LeaveType::Study,
            default                      => LeaveType::Annual,
        };
    }

    /** "FROM 22" or "FROM 2026-06-01" → starting Carbon date. Defaults to today. */
    protected function parseStartDate(string $body): Carbon
    {
        if (preg_match('/\bfrom\s+(\d{4}-\d{2}-\d{2})\b/i', $body, $m)) {
            return Carbon::parse($m[1])->startOfDay();
        }
        if (preg_match('/\bfrom\s+(\d{1,2})\b/i', $body, $m)) {
            $day = max(1, min(28, (int) $m[1]));
            return Carbon::create(now()->year, now()->month, $day)->startOfDay();
        }
        return now()->startOfDay();
    }
}
