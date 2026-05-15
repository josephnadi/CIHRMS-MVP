<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BenefitEnrolmentStatus;
use App\Enums\ClaimStatus;
use App\Events\BenefitClaimDecided;
use App\Events\BenefitClaimSubmitted;
use App\Events\BenefitEnroled;
use App\Events\BenefitPlanCreated;
use App\Events\DependantAdded;
use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\Dependant;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BenefitsService
{
    public function createPlan(array $data, ?User $actor = null): BenefitPlan
    {
        $plan = BenefitPlan::create($data);
        BenefitPlanCreated::dispatch($plan, $actor);
        return $plan;
    }

    public function enrol(
        BenefitPlan $plan,
        Employee $employee,
        \DateTimeInterface $effectiveFrom,
        ?float $premium = null,
        ?User $actor = null,
    ): BenefitEnrolment {
        if (! $plan->is_active) {
            throw new DomainException("Plan {$plan->code} is not active.");
        }

        if ($premium === null) {
            $pct = (float) $plan->employee_contribution_percentage;
            $premium = round((float) $plan->monthly_cost * ($pct / 100), 2);
        }

        $enrolment = BenefitEnrolment::create([
            'plan_id'         => $plan->id,
            'employee_id'     => $employee->id,
            'enrolled_at'     => now()->toDateString(),
            'effective_from'  => CarbonImmutable::instance($effectiveFrom)->toDateString(),
            'status'          => BenefitEnrolmentStatus::Active,
            'monthly_premium' => $premium,
        ]);

        BenefitEnroled::dispatch($enrolment, $actor);
        return $enrolment;
    }

    public function addDependant(Employee $employee, array $data, ?User $actor = null): Dependant
    {
        $existingDependants = $employee->dependants()->count();
        $maxAcrossPlans = $employee->benefitEnrolments()
            ->where('status', BenefitEnrolmentStatus::Active->value)
            ->with('plan:id,max_dependants')
            ->get()
            ->map(fn ($e) => (int) ($e->plan->max_dependants ?? 0))
            ->max() ?? 0;

        if ($maxAcrossPlans > 0 && $existingDependants >= $maxAcrossPlans) {
            throw new DomainException("Dependant cap of {$maxAcrossPlans} reached for active plans.");
        }

        $dependant = Dependant::create(array_merge(['employee_id' => $employee->id], $data));
        DependantAdded::dispatch($dependant, $actor);
        return $dependant;
    }

    public function submitClaim(
        BenefitEnrolment $enrolment,
        array $data,
        ?User $actor = null,
    ): BenefitClaim {
        if ($enrolment->status !== BenefitEnrolmentStatus::Active) {
            throw new DomainException('Enrolment is not active.');
        }

        return DB::transaction(function () use ($enrolment, $data, $actor) {
            $claim = BenefitClaim::create([
                'enrolment_id'    => $enrolment->id,
                'claim_reference' => 'CLM-' . strtoupper(Str::random(8)),
                'amount'          => $data['amount'],
                'currency'        => $data['currency'] ?? 'GHS',
                'claim_date'      => $data['claim_date'] ?? now()->toDateString(),
                'description'     => $data['description'],
                'status'          => ClaimStatus::Submitted,
                'submitted_at'    => now(),
            ]);

            BenefitClaimSubmitted::dispatch($claim, $actor);
            return $claim;
        });
    }

    public function decideClaim(
        BenefitClaim $claim,
        ClaimStatus $newStatus,
        User $decider,
        ?string $notes = null,
    ): BenefitClaim {
        $this->guardTransition($claim->status, $newStatus);

        $claim->update([
            'status'         => $newStatus,
            'decision_at'    => now(),
            'decision_notes' => $notes,
            'decided_by'     => $decider->id,
        ]);

        BenefitClaimDecided::dispatch($claim->fresh(), $decider);
        return $claim->fresh();
    }

    public function providentFundView(Employee $employee): array
    {
        $providentEnrolments = $employee->benefitEnrolments()
            ->with('plan:id,name,type')
            ->whereHas('plan', fn ($q) => $q->where('type', 'provident_fund'))
            ->where('status', BenefitEnrolmentStatus::Active->value)
            ->get();

        return $providentEnrolments->map(function (BenefitEnrolment $e) {
            $monthsActive = max(0, CarbonImmutable::instance($e->effective_from)->diffInMonths(now()));
            return [
                'plan_id'           => $e->plan_id,
                'plan_name'         => $e->plan?->name,
                'monthly_premium'   => (float) $e->monthly_premium,
                'months_active'     => $monthsActive,
                'total_contributed' => round((float) $e->monthly_premium * $monthsActive, 2),
            ];
        })->all();
    }

    private function guardTransition(ClaimStatus $from, ClaimStatus $to): void
    {
        $allowed = [
            'submitted' => ['reviewing', 'approved', 'rejected'],
            'reviewing' => ['approved', 'rejected'],
            'approved'  => ['paid'],
            'rejected'  => [],
            'paid'      => [],
        ];

        if (! in_array($to->value, $allowed[$from->value] ?? [], true)) {
            throw new DomainException("Illegal transition: {$from->value} → {$to->value}");
        }
    }
}
