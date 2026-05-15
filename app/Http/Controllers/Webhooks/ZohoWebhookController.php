<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\DTO\ContactDto;
use App\Models\Employee;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Zoho CRM webhook receiver.
 *
 * Configure Zoho's "Notify URL" workflow rule to POST contact events to
 * /webhooks/zoho. We diff incoming Contact data against the Employee row
 * with the matching external_crm_id and back-sync changed fields.
 *
 * Mapping is the inverse of ZohoCrmDriver::toZoho().
 */
class ZohoWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $integration = Integration::query()->where('provider', 'zoho_crm')->first();
        $payload     = $request->json()->all() ?: [];

        $event = $integration
            ? IntegrationEvent::create([
                'integration_id' => $integration->id,
                'direction'      => IntegrationEvent::DIRECTION_INBOUND,
                'event_type'     => 'zoho.contact.changed',
                'external_id'    => (string) data_get($payload, 'id'),
                'payload'        => $payload,
                'status'         => IntegrationEvent::STATUS_RECEIVED,
                'processed_at'   => now(),
            ])
            : null;

        try {
            $this->backSyncContact($payload);
        } catch (\Throwable $e) {
            Log::warning('[integrations] zoho back-sync failed', ['error' => $e->getMessage()]);
            $event?->markFailed($e->getMessage());

            return response()->json(['ok' => false, 'error' => 'sync_failed'], 200);
        }

        return response()->json(['ok' => true, 'event_id' => $event?->id], 200);
    }

    /**
     * Diff incoming Zoho Contact fields onto the Employee row.
     * Only fields that have actually changed are written, preserving the audit trail.
     */
    protected function backSyncContact(array $payload): void
    {
        $externalId = (string) data_get($payload, 'id');
        if ($externalId === '') {
            return;
        }

        $employee = Employee::query()
            ->with('user')
            ->where('external_crm_id', $externalId)
            ->first();
        if (! $employee) {
            return; // unknown contact — ignore (it might be sales-only)
        }

        $contact = new ContactDto(
            externalId: $externalId,
            firstName:  (string) data_get($payload, 'First_Name', ''),
            lastName:   data_get($payload, 'Last_Name'),
            email:      data_get($payload, 'Email'),
            phone:      data_get($payload, 'Phone'),
            jobTitle:   data_get($payload, 'Title'),
        );

        $changes = [];
        if ($contact->phone && $contact->phone !== $employee->phone) {
            $changes['phone'] = $contact->phone;
        }
        if ($contact->jobTitle && $contact->jobTitle !== $employee->position) {
            $changes['position'] = $contact->jobTitle;
        }

        if ($changes) {
            $employee->update($changes);
        }

        // Email lives on the User row, not Employee — guard against orphans.
        if ($employee->user && $contact->email && $contact->email !== $employee->user->email) {
            $employee->user->update(['email' => $contact->email]);
        }
    }
}
