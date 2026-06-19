<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LoanRepaymentStatus;
use App\Models\ArInvoice;
use App\Models\GlAccountBalance;
use App\Models\LoanRepayment;
use App\Models\VendorInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Compares each subledger to its GL control account. A non-zero variance means
 * the subledger and the ledger have drifted apart — surfaced as a report and as
 * a pre-close check. Tolerance matches the JE balance tolerance (0.005).
 */
class SubledgerReconciliationService
{
    private const TOLERANCE = 0.005;

    /** @return array<int, array{subledger:string, gl_code:string, subledger_total:float, gl_balance:float, variance:float, in_balance:bool}> */
    public function reconcile(): array
    {
        return [
            $this->row('Accounts Payable',            '2100', $this->apOutstanding()),
            $this->row('Accounts Receivable',         '1200', $this->arOutstanding()),
            $this->row('Loans Receivable (principal)', '1300', $this->loanPrincipalOutstanding()),
        ];
    }

    public function hasVariance(): bool
    {
        foreach ($this->reconcile() as $row) {
            if (! $row['in_balance']) {
                return true;
            }
        }

        return false;
    }

    private function row(string $label, string $glCode, float $subledgerTotal): array
    {
        $glBalance = $this->glBalance($glCode);
        $variance  = round($subledgerTotal - $glBalance, 2);

        return [
            'subledger'       => $label,
            'gl_code'         => $glCode,
            'subledger_total' => round($subledgerTotal, 2),
            'gl_balance'      => round($glBalance, 2),
            'variance'        => $variance,
            'in_balance'      => abs($variance) < self::TOLERANCE,
        ];
    }

    private function glBalance(string $code): float
    {
        return (float) GlAccountBalance::query()
            ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
            ->where('gl_accounts.code', $code)
            ->value('gl_account_balances.balance');
    }

    private function apOutstanding(): float
    {
        return (float) VendorInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum(DB::raw('total - amount_paid'));
    }

    private function arOutstanding(): float
    {
        return (float) ArInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum(DB::raw('total - amount_received'));
    }

    private function loanPrincipalOutstanding(): float
    {
        return (float) LoanRepayment::query()
            ->whereNotIn('status', [LoanRepaymentStatus::Paid->value, LoanRepaymentStatus::Waived->value])
            ->sum('principal_portion');
    }
}
