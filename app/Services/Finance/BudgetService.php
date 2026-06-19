<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\GlAccount;
use App\Models\User;
use DomainException;

/**
 * Annual budget lifecycle: one budget per fiscal year, draft → approved.
 * A draft is editable; an approved budget is frozen until reverted to draft.
 */
class BudgetService
{
    public function __construct(private readonly FiscalCalendarService $calendar)
    {
    }

    /** Get-or-create the draft budget for a fiscal year (ensures the year exists). */
    public function forYear(int $year): Budget
    {
        $fiscalYear = $this->calendar->ensureYear($year);

        return Budget::firstOrCreate(
            ['fiscal_year_id' => $fiscalYear->id],
            ['status' => BudgetStatus::Draft->value],
        );
    }

    /** Upsert one account's annual budget. Only allowed while the budget is Draft. */
    public function setLine(Budget $budget, GlAccount $account, float $annualAmount): BudgetLine
    {
        if ($budget->status !== BudgetStatus::Draft) {
            throw new DomainException('Cannot edit an approved budget; revert it to draft first.');
        }

        return BudgetLine::updateOrCreate(
            ['budget_id' => $budget->id, 'gl_account_id' => $account->id],
            ['annual_amount' => round($annualAmount, 2)],
        );
    }

    public function approve(Budget $budget, User $by): Budget
    {
        if ($budget->status === BudgetStatus::Approved) {
            throw new DomainException('Budget is already approved.');
        }

        $budget->update([
            'status'      => BudgetStatus::Approved->value,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);

        return $budget->fresh();
    }

    public function revertToDraft(Budget $budget): Budget
    {
        $budget->update([
            'status'      => BudgetStatus::Draft->value,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $budget->fresh();
    }
}
