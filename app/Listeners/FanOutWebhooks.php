<?php

namespace App\Listeners;

use App\Events\IdentityVerified;
use App\Events\LoanApproved;
use App\Events\OffboardingCompleted;
use App\Events\PayrollRunApproved;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Bridges domain events to external webhook subscribers. Each event maps to
 * a canonical event type ("payroll.run.approved", "loan.approved", etc.) and
 * an envelope-friendly payload.
 */
class FanOutWebhooks implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 1; // delivery itself retries; we don't want job retries amplifying

    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handlePayrollRunApproved(PayrollRunApproved $event): void
    {
        $r = $event->run;
        $this->dispatcher->fanOut('payroll.run.approved', [
            'run_id'       => $r->id,
            'reference'    => $r->reference,
            'period'       => $r->periodLabel(),
            'totals' => [
                'gross'        => (float) $r->gross_total,
                'net'          => (float) $r->net_total,
                'paye'         => (float) $r->paye_total,
                'ssnit_total'  => (float) $r->ssnit_tier1_employee_total + (float) $r->ssnit_tier1_employer_total,
            ],
            'lines_count'  => (int) $r->lines_count,
            'approved_at'  => optional($r->approved_at)->toIso8601String(),
        ]);
    }

    public function handleLoanApproved(LoanApproved $event): void
    {
        $l = $event->loan;
        $this->dispatcher->fanOut('loan.approved', [
            'reference'           => $l->reference,
            'employee_id'         => $l->employee_id,
            'principal'           => (float) $l->principal,
            'term_months'         => (int) $l->term_months,
            'monthly_installment' => (float) $l->monthly_installment,
        ]);
    }

    public function handleIdentityVerified(IdentityVerified $event): void
    {
        $v = $event->verification;
        $this->dispatcher->fanOut('identity.verified', [
            'verification_id' => $v->id,
            'employee_id'     => $v->employee_id,
            'provider'        => $v->provider?->value,
            'verified_at'     => optional($v->verified_at)->toIso8601String(),
        ]);
    }

    public function handleOffboardingCompleted(OffboardingCompleted $event): void
    {
        $c = $event->case;
        $this->dispatcher->fanOut('offboarding.completed', [
            'reference'              => $c->reference,
            'employee_id'            => $c->employee_id,
            'exit_type'              => $c->exit_type?->value,
            'effective_termination'  => optional($c->effective_termination_date)->toDateString(),
        ]);
    }
}
