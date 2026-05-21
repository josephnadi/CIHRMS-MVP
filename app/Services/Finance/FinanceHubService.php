<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LoanStatus;
use App\Enums\PayrollRunStatus;
use App\Models\LoanAccount;
use App\Models\OrgBankAccount;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class FinanceHubService
{
    public function summaryFor(User $user, int $ttlSeconds = 60): array
    {
        return Cache::remember(
            "finance.hub.summary.user.{$user->id}",
            $ttlSeconds,
            fn () => $this->build(),
        );
    }

    private function build(): array
    {
        return [
            'cashPosition'        => $this->cashPosition(),
            'bankAccounts'        => $this->bankAccountsSummary(),
            'nextPayroll'         => $this->nextPayroll(),
            'outstandingLoans'    => $this->outstandingLoans(),
            'pendingApprovals'    => $this->pendingApprovals(),
            'statutoryCompliance' => $this->statutoryCompliance(),
        ];
    }

    private function cashPosition(): float
    {
        return (float) OrgBankAccount::active()->sum('opening_balance');
    }

    private function bankAccountsSummary(): array
    {
        return OrgBankAccount::active()
            ->with('glAccount:id,code,name')
            ->orderBy('bank_name')
            ->get()
            ->map(fn (OrgBankAccount $b) => [
                'id'              => $b->id,
                'bank_name'       => $b->bank_name,
                'account_name'    => $b->account_name,
                'purpose'         => $b->purpose->label(),
                'opening_balance' => (float) $b->opening_balance,
                'gl_code'         => $b->glAccount?->code,
            ])
            ->all();
    }

    private function nextPayroll(): ?array
    {
        $run = PayrollRun::query()
            ->whereIn('status', $this->payrollPendingStatuses())
            ->orderBy('period_start')
            ->first();

        if (! $run) {
            return null;
        }

        return [
            'reference'         => $run->reference,
            'period_start'      => $run->period_start?->format('Y-m-d'),
            'period_end'        => $run->period_end?->format('Y-m-d'),
            'status'            => $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status,
            'participant_count' => $run->lines()->count(),
            // PayrollLine uses column `net` (not `net_pay`)
            'projected_net'     => (float) $run->lines()->sum('net'),
        ];
    }

    private function outstandingLoans(): array
    {
        $activeStatuses = $this->loanActiveStatuses();

        return [
            'count'         => LoanAccount::whereIn('status', $activeStatuses)->count(),
            // LoanAccount uses column `outstanding_balance` (not `balance`)
            'total_balance' => (float) LoanAccount::whereIn('status', $activeStatuses)->sum('outstanding_balance'),
        ];
    }

    private function pendingApprovals(): array
    {
        return [
            'payroll_runs' => PayrollRun::whereIn('status', $this->payrollPendingStatuses())->count(),
            'loans'        => LoanAccount::whereIn('status', $this->loanPendingStatuses())->count(),
        ];
    }

    private function statutoryCompliance(): array
    {
        $latest = StatutoryReturn::query()
            ->orderBy('kind')
            ->orderByDesc('period_end')
            ->get()
            ->unique('kind')
            ->values();

        return $latest
            ->map(fn (StatutoryReturn $r) => [
                'kind'       => $r->kind instanceof \BackedEnum ? $r->kind->value : (string) $r->kind,
                'period_end' => $r->period_end?->format('Y-m-d'),
                // StatutoryReturn has no `status` column; derive from submitted_at
                'status'     => $r->submitted_at ? 'submitted' : 'pending',
            ])
            ->all();
    }

    /**
     * PayrollRunStatus cases that represent runs still being prepared or awaiting approval.
     * No 'Pending' case exists — the enum uses Draft / Calculating / Calculated.
     */
    private function payrollPendingStatuses(): array
    {
        return [
            PayrollRunStatus::Draft->value,
            PayrollRunStatus::Calculating->value,
            PayrollRunStatus::Calculated->value,
        ];
    }

    /**
     * LoanStatus cases for loans that are currently active (repayment in progress).
     * No 'Active' case exists — the enum uses Disbursed / Repaying.
     */
    private function loanActiveStatuses(): array
    {
        return [
            LoanStatus::Disbursed->value,
            LoanStatus::Repaying->value,
        ];
    }

    private function loanPendingStatuses(): array
    {
        return [
            LoanStatus::PendingApproval->value,
        ];
    }
}
