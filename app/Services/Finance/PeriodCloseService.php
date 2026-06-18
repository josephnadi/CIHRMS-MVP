<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FiscalPeriodStatus;
use App\Exceptions\Finance\SubledgerVarianceException;
use App\Models\FiscalPeriod;
use App\Models\User;
use DomainException;

/**
 * Period lifecycle transitions. The posting guard (JournalPostingService)
 * enforces the consequences (no posting into Closed/Locked); this service
 * owns the legal state transitions + actor attribution. HTTP-level audit is
 * provided by the AuditTrail middleware on the POST endpoints.
 */
class PeriodCloseService
{
    public function __construct(private readonly SubledgerReconciliationService $reconciliation)
    {
    }

    public function close(FiscalPeriod $period, User $by, bool $acknowledgeVariance = false): FiscalPeriod
    {
        if ($period->status !== FiscalPeriodStatus::Open) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only an open period can be closed.");
        }

        if (! $acknowledgeVariance && $this->reconciliation->hasVariance()) {
            throw new SubledgerVarianceException(
                "Subledger does not tie to the general ledger. Review the reconciliation, then confirm to close with an override."
            );
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => $by->id,
        ]);

        return $period->fresh();
    }

    public function reopen(FiscalPeriod $period, User $by): FiscalPeriod
    {
        if ($period->status === FiscalPeriodStatus::Locked) {
            throw new DomainException("Period {$period->name} is locked and cannot be reopened.");
        }
        if ($period->status !== FiscalPeriodStatus::Closed) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only a closed period can be reopened.");
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Open->value,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $period->fresh();
    }

    public function lock(FiscalPeriod $period, User $by): FiscalPeriod
    {
        if ($period->status !== FiscalPeriodStatus::Closed) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only a closed period can be locked.");
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Locked->value,
            'locked_at' => now(),
            'locked_by' => $by->id,
        ]);

        return $period->fresh();
    }
}
