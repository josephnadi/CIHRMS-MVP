<?php

namespace App\Listeners;

use App\Events\PayslipGenerated;
use App\Integrations\IntegrationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Mirror every newly-generated payslip PDF into the configured cloud storage
 * (OneDrive by default, Google Drive when INT_FILES_DRIVER=google).
 *
 * Gated behind feature flag `mirror_documents_to_cloud` so it can ship safely
 * before any tenant has connected a provider.
 */
class UploadPayslipToCloud implements ShouldQueue
{
    public string $queue = 'integrations';

    public function __construct(protected IntegrationManager $integrations) {}

    public function handle(PayslipGenerated $event): void
    {
        if (! config('integrations.feature_flags.mirror_documents_to_cloud')) {
            return;
        }

        if (! $this->integrations->isAvailable('files')) {
            return;
        }

        try {
            $files = $this->integrations->for('files');
            // Folder per employee per year — keeps OneDrive/Drive browsable.
            $employeeNo = $event->payment->employee->employee_no ?? "emp-{$event->payment->employee_id}";
            $year       = $event->payment->paid_at?->format('Y') ?? now()->format('Y');
            $remotePath = "CIHRMS/Payslips/{$year}/{$employeeNo}/{$event->filename}";

            $files->upload($remotePath, base64_decode($event->pdfBase64), 'application/pdf');
        } catch (\Throwable $e) {
            // Failure is logged into integration_events by AbstractDriver::track() automatically.
            Log::warning('[integrations] payslip mirror failed', [
                'payment_id' => $event->payment->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
