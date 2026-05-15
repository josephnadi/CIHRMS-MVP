<?php

namespace App\Services\Whistleblower;

use App\Enums\InvestigationActionType;
use App\Enums\WhistleblowerSeverity;
use App\Enums\WhistleblowerStatus;
use App\Events\WhistleblowerCaseClosed;
use App\Models\User;
use App\Models\WhistleblowerAction;
use App\Models\WhistleblowerMessage;
use App\Models\WhistleblowerReport;
use Illuminate\Support\Facades\DB;

/**
 * Investigator-side service. Every state-changing call writes a
 * `whistleblower_actions` row so the case has a tamper-evident timeline,
 * useful for CHRAJ / Auditor-General hand-offs.
 */
class WhistleblowerInvestigationService
{
    public function triage(
        WhistleblowerReport $report,
        User $investigator,
        WhistleblowerSeverity $severity,
        ?User $assignTo = null,
        ?string $notes = null,
    ): WhistleblowerReport {
        if ($report->status !== WhistleblowerStatus::Submitted) {
            throw new \DomainException("Report {$report->case_number} has already been triaged.");
        }

        return DB::transaction(function () use ($report, $investigator, $severity, $assignTo, $notes) {
            $report->update([
                'severity'                 => $severity->value,
                'status'                   => WhistleblowerStatus::Triaged->value,
                'assigned_investigator_id' => $assignTo?->id ?? $investigator->id,
                'triaged_at'               => now(),
                'triaged_by'               => $investigator->id,
            ]);

            $this->logAction($report, $investigator, InvestigationActionType::StatusChange, $notes, [
                'severity' => $severity->value,
                'from'     => WhistleblowerStatus::Submitted->value,
                'to'       => WhistleblowerStatus::Triaged->value,
            ]);

            return $report->fresh();
        });
    }

    public function logAction(
        WhistleblowerReport $report,
        User $investigator,
        InvestigationActionType $type,
        ?string $notes = null,
        array $meta = [],
    ): WhistleblowerAction {
        return WhistleblowerAction::create([
            'report_id'       => $report->id,
            'investigator_id' => $investigator->id,
            'action_type'     => $type->value,
            'notes'           => $notes,
            'meta'            => $meta,
            'occurred_at'     => now(),
        ]);
    }

    public function changeStatus(
        WhistleblowerReport $report,
        User $investigator,
        WhistleblowerStatus $newStatus,
        ?string $closureSummary = null,
    ): WhistleblowerReport {
        $previous = $report->status;

        if ($previous === $newStatus) return $report;

        if (! $previous->isOpen() && $newStatus->isOpen()) {
            throw new \DomainException('Cannot re-open a closed case via status change. Open a new related case.');
        }

        return DB::transaction(function () use ($report, $investigator, $newStatus, $previous, $closureSummary) {
            $updates = ['status' => $newStatus->value];
            if (! $newStatus->isOpen()) {
                $updates['closed_at']       = now();
                $updates['closed_by']       = $investigator->id;
                if ($closureSummary) $updates['closure_summary'] = $closureSummary;
            }
            $report->update($updates);

            $this->logAction($report, $investigator, InvestigationActionType::StatusChange, $closureSummary, [
                'from' => $previous->value,
                'to'   => $newStatus->value,
            ]);

            if (! $newStatus->isOpen()) {
                event(new WhistleblowerCaseClosed($report));
            }

            return $report->fresh();
        });
    }

    public function postMessageToSubmitter(
        WhistleblowerReport $report,
        User $investigator,
        string $body,
    ): WhistleblowerMessage {
        return DB::transaction(function () use ($report, $investigator, $body) {
            $message = WhistleblowerMessage::create([
                'report_id'  => $report->id,
                'direction'  => 'outbound',
                'body'       => $body,
                'posted_by'  => $investigator->id,
                'posted_at'  => now(),
            ]);

            $this->logAction($report, $investigator, InvestigationActionType::MessageSent, null, [
                'message_id' => $message->id,
            ]);

            return $message;
        });
    }

    public function assignInvestigator(WhistleblowerReport $report, User $assigner, User $assignee): WhistleblowerReport
    {
        $report->update(['assigned_investigator_id' => $assignee->id]);

        $this->logAction($report, $assigner, InvestigationActionType::StatusChange, "Reassigned to investigator id {$assignee->id}", [
            'reassigned_to' => $assignee->id,
        ]);

        return $report->fresh();
    }
}
