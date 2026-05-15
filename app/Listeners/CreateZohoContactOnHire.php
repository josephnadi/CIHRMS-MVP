<?php

namespace App\Listeners;

use App\Events\ApplicantHired;
use App\Integrations\DTO\ContactDto;
use App\Integrations\IntegrationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Push the new hire to the configured CRM (Zoho by default) so sales / customer-success
 * can see them as a contact. Persists the returned external_id back onto the Employee row
 * for inbound webhook diff back-sync later.
 */
class CreateZohoContactOnHire implements ShouldQueue
{
    public string $queue = 'integrations';

    public function __construct(protected IntegrationManager $integrations) {}

    public function handle(ApplicantHired $event): void
    {
        if (! config('integrations.feature_flags.auto_sync_crm_on_hire')) {
            return;
        }

        if (! $this->integrations->isAvailable('crm')) {
            return;
        }

        $employee = $event->employee;
        if (! $employee) {
            return; // hired but no employee record yet — skip until promotion completes
        }

        try {
            $crm = $this->integrations->for('crm');
            $contact = ContactDto::fromEmployee($employee->loadMissing(['user', 'department']));
            $externalId = $crm->syncContact($contact);

            $employee->update(['external_crm_id' => $externalId]);
        } catch (\Throwable $e) {
            Log::warning('[integrations] CRM contact sync on hire failed', [
                'applicant_id' => $event->applicant->id,
                'employee_id'  => $employee->id ?? null,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
