<?php

namespace App\Services\Performance;

use App\Enums\PerformanceContractStatus;
use App\Models\Employee;
use App\Models\PerformanceContract;
use App\Models\ReviewCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Performance Contract lifecycle:
 *
 *   draft → pending_signature → active → (achieved | missed)
 *
 * Both employee AND supervisor must sign before the contract activates;
 * if either signature is missing at cycle close, the contract is invalid
 * for that cycle and the employee defaults to general-cycle review only.
 *
 * Weighted achievement is computed deterministically from the KPI array:
 *   sum(weight_i × score_i) / sum(weight_i)   — yields 0–100.
 */
class PerformanceContractService
{
    public const ACHIEVEMENT_THRESHOLD = 60.0;   // ≥60% = achieved; below = missed

    public function draft(
        ReviewCycle $cycle,
        Employee $employee,
        ?Employee $supervisor,
        array $kpis,
        User $actor,
    ): PerformanceContract {
        $this->validateKpis($kpis);

        return DB::transaction(fn () => PerformanceContract::updateOrCreate(
            ['cycle_id' => $cycle->id, 'employee_id' => $employee->id],
            [
                'supervisor_id' => $supervisor?->id,
                'status'        => PerformanceContractStatus::Draft->value,
                'kpis'          => $this->normaliseKpis($kpis),
                'drafted_by'    => $actor->id,
            ],
        ));
    }

    public function sendForSignature(PerformanceContract $contract): PerformanceContract
    {
        if ($contract->status !== PerformanceContractStatus::Draft) {
            throw new \DomainException('Only drafts can be sent for signature.');
        }
        if (! $contract->kpis || count($contract->kpis) === 0) {
            throw new \DomainException('Cannot send a contract with zero KPIs.');
        }

        $contract->update(['status' => PerformanceContractStatus::PendingSign->value]);
        return $contract->fresh();
    }

    public function sign(PerformanceContract $contract, User $signer): PerformanceContract
    {
        if ($contract->status !== PerformanceContractStatus::PendingSign) {
            throw new \DomainException('Contract is not pending signature.');
        }

        $employee   = $contract->employee;
        $supervisor = $contract->supervisor;

        return DB::transaction(function () use ($contract, $signer, $employee, $supervisor) {
            $updates = [];
            if ($employee && $signer->id === $employee->user_id && ! $contract->employee_signed_at) {
                $updates['employee_signed_at'] = now();
            }
            if ($supervisor && $signer->id === $supervisor->user_id && ! $contract->supervisor_signed_at) {
                $updates['supervisor_signed_at'] = now();
            }

            if (empty($updates)) {
                throw new \DomainException('Signer is neither the employee nor the supervisor on this contract.');
            }

            $contract->update($updates);

            // Auto-activate once both signatures present.
            $fresh = $contract->fresh();
            if ($fresh->isFullySigned()) {
                $fresh->update([
                    'status'       => PerformanceContractStatus::Active->value,
                    'finalised_at' => now(),
                    'finalised_by' => $signer->id,
                ]);
            }

            return $fresh;
        });
    }

    /**
     * End-of-cycle evaluation. Walks each KPI's actual/target, computes a
     * 0–100 score per KPI (clipped), then a weighted overall.
     */
    public function evaluate(PerformanceContract $contract, array $actuals, User $actor): PerformanceContract
    {
        if ($contract->status !== PerformanceContractStatus::Active) {
            throw new \DomainException('Only active contracts can be evaluated.');
        }

        $kpis = $contract->kpis ?? [];
        $totalWeight = 0.0;
        $weightedScore = 0.0;

        foreach ($kpis as $i => $kpi) {
            $id      = $kpi['id'] ?? null;
            $actual  = $id !== null && isset($actuals[$id]) ? (float) $actuals[$id] : ((float) ($kpi['actual'] ?? 0));
            $target  = (float) ($kpi['target'] ?? 0);
            $weight  = (float) ($kpi['weight'] ?? 0);

            $score   = $target > 0 ? min(100.0, max(0.0, ($actual / $target) * 100.0)) : 0.0;

            $kpis[$i]['actual'] = $actual;
            $kpis[$i]['score']  = round($score, 2);

            $weightedScore += $score * $weight;
            $totalWeight   += $weight;
        }

        $overall = $totalWeight > 0 ? round($weightedScore / $totalWeight, 2) : 0.0;

        $contract->update([
            'kpis'                 => $kpis,
            'weighted_achievement' => $overall,
            'status'               => $overall >= self::ACHIEVEMENT_THRESHOLD
                ? PerformanceContractStatus::Achieved->value
                : PerformanceContractStatus::Missed->value,
            'finalised_by'         => $actor->id,
            'finalised_at'         => now(),
        ]);

        return $contract->fresh();
    }

    private function validateKpis(array $kpis): void
    {
        if (count($kpis) === 0) {
            throw new \DomainException('At least one KPI is required.');
        }
        $totalWeight = 0.0;
        foreach ($kpis as $k) {
            if (! isset($k['name']) || trim($k['name']) === '') {
                throw new \DomainException('Each KPI must have a name.');
            }
            $totalWeight += (float) ($k['weight'] ?? 0);
        }
        if (abs($totalWeight - 100.0) > 0.01) {
            throw new \DomainException("KPI weights must sum to 100. Got {$totalWeight}.");
        }
    }

    private function normaliseKpis(array $kpis): array
    {
        return collect($kpis)->values()->map(fn ($k, $i) => [
            'id'     => $k['id']     ?? 'kpi-' . ($i + 1),
            'name'   => (string) $k['name'],
            'weight' => (float)  ($k['weight'] ?? 0),
            'target' => (float)  ($k['target'] ?? 0),
            'unit'   => $k['unit']   ?? null,
            'scorecard' => $k['scorecard'] ?? null,   // 'financial' | 'customer' | 'process' | 'learning'
            'actual' => $k['actual'] ?? null,
            'score'  => $k['score']  ?? null,
        ])->all();
    }
}
