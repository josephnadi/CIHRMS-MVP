<?php

namespace App\Services\Privacy;

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Events\DataSubjectRequestFulfilled;
use App\Events\DataSubjectRequestSubmitted;
use App\Models\DataSubjectRequest;
use App\Models\User;

/**
 * Lifecycle orchestrator for DPA 2012 (Act 843) data-subject requests.
 *
 *   submitted → acknowledged → in_review → fulfilled / partially_fulfilled / rejected
 *
 * The 30-day SLA (Act 843 §22) is enforced via `target_completion_date`;
 * the nightly cron transitions stale requests to `overdue` for DPO triage.
 */
class DataSubjectRequestService
{
    public const SLA_DAYS = 30;

    public function __construct(
        private readonly DataSubjectExportBuilder $exports,
        private readonly ErasureService $erasure,
    ) {}

    public function submit(
        User $subject,
        DataSubjectRequestType $type,
        string $statement,
        ?string $rectificationDetails = null,
        ?string $objectionPurpose = null,
    ): DataSubjectRequest {
        $now = now();

        $req = DataSubjectRequest::create([
            'reference'              => $this->nextReference(),
            'subject_user_id'        => $subject->id,
            'request_type'           => $type->value,
            'status'                 => DataSubjectRequestStatus::Submitted->value,
            'subject_statement'      => $statement,
            'rectification_details'  => $rectificationDetails,
            'objection_purpose'      => $objectionPurpose,
            'submitted_at'           => $now,
            'target_completion_date' => $now->copy()->addDays(self::SLA_DAYS)->toDateString(),
        ]);
        $req->appendAuditEntry('submitted', $subject->id);

        event(new DataSubjectRequestSubmitted($req));

        return $req;
    }

    public function acknowledge(DataSubjectRequest $req, User $dpo): DataSubjectRequest
    {
        if ($req->status !== DataSubjectRequestStatus::Submitted) {
            throw new \DomainException('Already acknowledged or terminal.');
        }
        $req->update([
            'status'          => DataSubjectRequestStatus::Acknowledged->value,
            'assigned_to'     => $dpo->id,
            'acknowledged_at' => now(),
        ]);
        $req->appendAuditEntry('acknowledged', $dpo->id);
        return $req->fresh();
    }

    public function fulfill(DataSubjectRequest $req, User $dpo, string $summary): DataSubjectRequest
    {
        if ($req->status->isTerminal()) {
            throw new \DomainException("Request {$req->reference} is already closed.");
        }

        $exportPath  = null;
        $exportHash  = null;
        $tombstones  = null;

        // Type-specific fulfilment work
        if ($req->request_type->producesExport()) {
            $bundle      = $this->exports->buildFor($req->subject, $req->reference);
            $exportPath  = $bundle['path'];
            $exportHash  = $bundle['sha256'];
            $req->appendAuditEntry('export_built', $dpo->id, ['sha256' => $exportHash]);
        }

        if ($req->request_type === DataSubjectRequestType::Erasure) {
            $tombstones = $this->erasure->erase($req->subject, $req->reference);
            $req->appendAuditEntry('erasure_executed', $dpo->id, ['summary' => $tombstones]);
        }

        $req->update([
            'status'              => DataSubjectRequestStatus::Fulfilled->value,
            'decided_by'          => $dpo->id,
            'completed_at'        => now(),
            'decision_summary'    => $summary,
            'export_path'         => $exportPath,
            'export_sha256'       => $exportHash,
            'export_generated_at' => $exportPath ? now() : null,
            'tombstone_log'       => $tombstones,
        ]);
        $req->appendAuditEntry('fulfilled', $dpo->id);

        event(new DataSubjectRequestFulfilled($req->fresh()));

        return $req->fresh();
    }

    /** Alias used by feature tests + earlier API consumers — same as fulfill(). */
    public function fulfilWithExport(DataSubjectRequest $req, User $dpo, string $summary): DataSubjectRequest
    {
        return $this->fulfill($req, $dpo, $summary);
    }

    public function reject(DataSubjectRequest $req, User $dpo, string $statutoryBasis, string $summary = ''): DataSubjectRequest
    {
        if ($req->status->isTerminal()) {
            throw new \DomainException("Request {$req->reference} is already closed.");
        }
        $req->update([
            'status'           => DataSubjectRequestStatus::Rejected->value,
            'decided_by'       => $dpo->id,
            'completed_at'     => now(),
            'decision_summary' => $summary,
            'rejection_basis'  => $statutoryBasis,
        ]);
        $req->appendAuditEntry('rejected', $dpo->id, ['basis' => $statutoryBasis]);
        return $req->fresh();
    }

    public function withdraw(DataSubjectRequest $req, \App\Models\User $caller): DataSubjectRequest
    {
        // Only the data subject themselves may withdraw — never the DPO, never
        // another user. The DPO's path is reject() with a statutory basis.
        if ((int) $req->subject_user_id !== (int) $caller->id) {
            throw new \DomainException('Only the subject may withdraw their own request.');
        }

        if ($req->status->isTerminal()) return $req;
        $req->update([
            'status'       => DataSubjectRequestStatus::Withdrawn->value,
            'completed_at' => now(),
        ]);
        $req->appendAuditEntry('withdrawn_by_subject', $caller->id);
        return $req->fresh();
    }

    /** Nightly job: flag stale open requests as overdue for DPO escalation. */
    public function markOverdueRequests(): int
    {
        return DataSubjectRequest::query()
            ->open()
            ->whereDate('target_completion_date', '<', now()->toDateString())
            ->where('status', '!=', DataSubjectRequestStatus::Overdue->value)
            ->update(['status' => DataSubjectRequestStatus::Overdue->value]);
    }

    private function nextReference(): string
    {
        $year  = now()->year;
        $count = DataSubjectRequest::whereYear('created_at', $year)->count() + 1;
        return sprintf('DSR-%04d-%05d', $year, $count);
    }

    /**
     * Public submission — for subjects who don't have a CIHRMS account.
     * Stays in `pending_verification` until they click the emailed magic
     * link. Until then it's invisible to the DPO queue, so spammers can't
     * flood the queue without owning the inbox.
     */
    public function submitPublic(
        string $email,
        string $fullName,
        DataSubjectRequestType $type,
        string $statement,
        ?string $rectificationDetails = null,
        ?string $objectionPurpose = null,
    ): DataSubjectRequest {
        $now   = now();
        $token = bin2hex(random_bytes(32)); // 64-char hex

        $req = DataSubjectRequest::create([
            'reference'              => $this->nextReference(),
            'subject_user_id'        => null,
            'subject_email'          => strtolower(trim($email)),
            'subject_full_name'      => trim($fullName),
            'verification_token'     => $token,
            'verified_at'            => null,
            'request_type'           => $type->value,
            'status'                 => DataSubjectRequestStatus::PendingVerification->value,
            'subject_statement'      => $statement,
            'rectification_details'  => $rectificationDetails,
            'objection_purpose'      => $objectionPurpose,
            'submitted_at'           => $now,
            // The 30-day SLA clock starts only AFTER verification — set the
            // target now from submission time, but the practical clock starts
            // when verify() runs and the status flips to Submitted.
            'target_completion_date' => $now->copy()->addDays(self::SLA_DAYS)->toDateString(),
        ]);
        $req->appendAuditEntry('public_submitted', null, [
            'email'     => $req->subject_email,
            'full_name' => $req->subject_full_name,
        ]);

        return $req;
    }

    /**
     * Verify a public submission via the emailed token. On success:
     *   - status moves PendingVerification → Submitted (joins the DPO queue)
     *   - verified_at is stamped
     *   - the 30-day SLA clock is reset from now (not submission time)
     *   - DataSubjectRequestSubmitted event fires for analytics + DPO ping
     */
    public function verifyPublic(string $token): ?DataSubjectRequest
    {
        $req = DataSubjectRequest::where('verification_token', $token)
            ->where('status', DataSubjectRequestStatus::PendingVerification->value)
            ->first();

        if (! $req) return null;

        $now = now();
        $req->update([
            'status'                 => DataSubjectRequestStatus::Submitted->value,
            'verified_at'            => $now,
            'submitted_at'           => $now, // reset SLA clock to verification moment
            'target_completion_date' => $now->copy()->addDays(self::SLA_DAYS)->toDateString(),
        ]);
        $req->appendAuditEntry('verified_by_token', null);

        event(new DataSubjectRequestSubmitted($req->fresh()));

        return $req->fresh();
    }
}
