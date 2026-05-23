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
            'apOutstanding'       => $this->apOutstanding(),
            'arOutstanding'       => $this->arOutstanding(),
            'agingBuckets'        => $this->agingBuckets(),
            'gatewayHealth'       => $this->gatewayHealth(),
            'reconciliationStats' => $this->reconciliationStats(),
            'pendingApprovals'    => $this->pendingApprovals(),
            'statutoryCompliance' => $this->statutoryCompliance(),
        ];
    }

    private function reconciliationStats(): array
    {
        $unreconciled = \App\Models\BankStatementLine::query()
            ->whereNull('reconciled_at')
            ->count();

        $oldest = \App\Models\BankStatementLine::query()
            ->whereNull('reconciled_at')
            ->min('transaction_date');

        return [
            'unreconciled_count'       => $unreconciled,
            'oldest_unreconciled_date' => $oldest,
        ];
    }

    private function gatewayHealth(): array
    {
        $purpose = config('services.paystack.receipt_bank_purpose', 'receipts');

        $hasReceiptsBank = OrgBankAccount::query()
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->exists();

        if (! $hasReceiptsBank) {
            return [
                'status'  => 'missing_bank',
                'message' => "No active org bank account with purpose '{$purpose}'. Paystack receipts will fail.",
            ];
        }

        return ['status' => 'ok', 'message' => null];
    }

    private function cashPosition(): float
    {
        // F2: live cash position. Sum gl_account_balances.balance for asset GL
        // accounts that are linked to active org_bank_accounts. Replaces the F1
        // static-proxy implementation.
        return (float) \App\Models\GlAccountBalance::query()
            ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
            ->join('org_bank_accounts', 'org_bank_accounts.gl_account_id', '=', 'gl_accounts.id')
            ->where('org_bank_accounts.is_active', true)
            ->where('gl_accounts.type', 'asset')
            ->sum('gl_account_balances.balance');
    }

    private function apOutstanding(): float
    {
        return (float) \App\Models\VendorInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum(\Illuminate\Support\Facades\DB::raw('total - amount_paid'));
    }

    private function arOutstanding(): float
    {
        return (float) \App\Models\ArInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum(\Illuminate\Support\Facades\DB::raw('total - amount_received'));
    }

    /**
     * Aging buckets for outstanding AR invoices, computed in PHP because
     * SQLite + Postgres date arithmetic diverges enough that a single SQL
     * expression isn't worth the maintenance cost vs. a single iteration
     * over a typically-small set. Buckets: current / 30 / 60 / 90+.
     *
     * @return array{current:float, 30:float, 60:float, 90_plus:float}
     */
    private function agingBuckets(): array
    {
        $today = \Carbon\CarbonImmutable::today();

        $rows = \App\Models\ArInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->get(['due_date', 'total', 'amount_received']);

        $buckets = ['current' => 0.0, '30' => 0.0, '60' => 0.0, '90_plus' => 0.0];

        foreach ($rows as $r) {
            $outstanding = (float) $r->total - (float) $r->amount_received;
            if ($outstanding <= 0.005) continue;

            if (! $r->due_date || $r->due_date->greaterThanOrEqualTo($today)) {
                $buckets['current'] += $outstanding;
            } else {
                $daysOverdue = (int) $today->diffInDays($r->due_date, true);
                if ($daysOverdue <= 30)     $buckets['30']      += $outstanding;
                elseif ($daysOverdue <= 60) $buckets['60']      += $outstanding;
                else                         $buckets['90_plus'] += $outstanding;
            }
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
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
            ->whereIn('status', $this->payrollPreApprovalStatuses())
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
            'payroll_runs' => PayrollRun::whereIn('status', $this->payrollPreApprovalStatuses())->count(),
            'loans'        => LoanAccount::whereIn('status', $this->loanPendingStatuses())->count(),
            'invoices'     => \App\Models\VendorInvoice::where('status', 'pending_approval')->count(),
            'payments'     => \App\Models\ApPayment::where('status', 'pending')->count(),
        ];
    }

    private function statutoryCompliance(): array
    {
        $latest = StatutoryReturn::query()
            ->with('run:id,period_end')
            ->get()
            ->sortByDesc(fn ($r) => optional($r->run)->period_end)
            ->unique('kind')
            ->values();

        return $latest
            ->map(fn (StatutoryReturn $r) => [
                'kind'       => $r->kind instanceof \BackedEnum ? $r->kind->value : (string) $r->kind,
                'period_end' => $r->run?->period_end?->format('Y-m-d'),
                // StatutoryReturn has no `status` column; derive from submitted_at
                'status'     => $r->submitted_at ? 'submitted' : 'pending',
            ])
            ->all();
    }

    /**
     * PayrollRunStatus cases that represent runs still being prepared or awaiting approval
     * (pre-approval stage). No 'Pending' case exists — the enum uses Draft / Calculating / Calculated.
     */
    private function payrollPreApprovalStatuses(): array
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
