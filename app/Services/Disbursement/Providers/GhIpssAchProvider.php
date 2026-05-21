<?php

declare(strict_types=1);

namespace App\Services\Disbursement\Providers;

use App\Enums\DisbursementChannel;
use App\Models\Disbursement;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;

/**
 * GhIPSS ACH (bank-transfer) disbursement provider.
 *
 * Unlike the mobile-money providers, GhIPSS is a **bulk file** rail — the
 * institution uploads a single GhIP/EFT CSV to the sponsor bank's portal,
 * and settlement happens overnight. There is no per-row REST endpoint.
 *
 * So `send()` here doesn't make a network call; it just stages the row by
 * assigning it a deterministic batch token (`GHIPSS-{run}-{disbursement}`)
 * and marking it Sent. The bytes that actually move money live in the file
 * produced by `App\Services\Disbursement\GhIpssBatchFileBuilder` and shipped
 * via the `disbursement:ghipss-export` command.
 *
 * `refreshStatus()` is a no-op — the bank settlement signal comes back as a
 * statement-reconciliation upload (out of scope for v1), not a polled API.
 */
class GhIpssAchProvider implements DisbursementProvider
{
    public function __construct(
        /** Sponsor bank's sort code on the GhIPSS network (5-digit). */
        private readonly string $sponsorSortCode,
        /** Originator name printed in the beneficiary's bank statement narration. */
        private readonly string $originatorName,
    ) {}

    public function channel(): string
    {
        return DisbursementChannel::GhipssAch->value;
    }

    public function send(Disbursement $d): DisbursementResult
    {
        if (empty($d->beneficiary_account)) {
            return DisbursementResult::failed('GhIPSS: beneficiary bank account is missing.');
        }

        // Deterministic — the same disbursement always maps to the same token,
        // which keeps the batch file reproducible if the export is re-run.
        $reference = sprintf('GHIPSS-%d-%d', $d->payroll_run_id, $d->id);

        return DisbursementResult::sent($reference, [
            'staged_for_batch'  => true,
            'sponsor_sort_code' => $this->sponsorSortCode,
            'originator_name'   => $this->originatorName,
        ]);
    }

    public function refreshStatus(Disbursement $d): DisbursementResult
    {
        // GhIPSS doesn't expose a per-row status endpoint. The reconciliation
        // command reads bank statements and flips rows to Settled / Failed by
        // matching on provider_reference. Until that runs, the status returned
        // here is whatever's already persisted.
        return new DisbursementResult(
            status: $d->status ?? \App\Enums\DisbursementStatus::Sent,
            providerReference: $d->provider_reference,
        );
    }
}
