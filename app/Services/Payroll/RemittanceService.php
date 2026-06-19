<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRate;
use App\Models\StatutoryReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Statutory remittance posture: when a return is due (period end + the
 * effective remittance-deadline days, default 14), whether it has been filed,
 * and the write path that records a filing. There is no status column — state
 * is derived from submitted_at and the computed due date.
 */
class RemittanceService
{
    private const DEFAULT_DEADLINE_DAYS = 14;

    public function deadlineDays(CarbonInterface $periodEnd): int
    {
        try {
            return (int) StatutoryRate::lookup(StatutoryRate::REMITTANCE_DEADLINE_DAYS, $periodEnd);
        } catch (\Throwable $e) {
            return self::DEFAULT_DEADLINE_DAYS;
        }
    }

    public function dueDate(StatutoryReturn $return): ?CarbonImmutable
    {
        $return->loadMissing('run');
        $periodEnd = $return->run?->period_end;
        if ($periodEnd === null) {
            return null;
        }

        $end = CarbonImmutable::parse($periodEnd instanceof \DateTimeInterface ? $periodEnd->format('Y-m-d') : (string) $periodEnd);

        return $end->addDays($this->deadlineDays($end));
    }

    public function isOverdue(StatutoryReturn $return, ?CarbonInterface $now = null): bool
    {
        if ($return->submitted_at !== null) {
            return false;
        }
        $due = $this->dueDate($return);
        if ($due === null) {
            return false;
        }

        return ($now ?? CarbonImmutable::now())->greaterThan($due);
    }

    public function status(StatutoryReturn $return, ?CarbonInterface $now = null): string
    {
        if ($return->submitted_at !== null) {
            return 'submitted';
        }

        return $this->isOverdue($return, $now) ? 'overdue' : 'pending';
    }

    public function markSubmitted(StatutoryReturn $return, User $by, string $reference, ?CarbonInterface $submittedAt = null): StatutoryReturn
    {
        if ($return->submitted_at !== null) {
            throw new DomainException('This return has already been recorded as filed.');
        }

        $return->update([
            'submitted_at'         => $submittedAt ?? CarbonImmutable::now(),
            'submitted_by'         => $by->id,
            'submission_reference' => $reference,
        ]);

        return $return->fresh();
    }

    /** @return array{generated:int, submitted:int, overdue:int} */
    public function posture(?CarbonInterface $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $total     = StatutoryReturn::count();
        $submitted = StatutoryReturn::query()->whereNotNull('submitted_at')->count();

        // Overdue: unsubmitted, and now is past period_end + default deadline days.
        // (A single current deadline value is sufficient for this aggregate nag.)
        $cutoff = $now->subDays(self::DEFAULT_DEADLINE_DAYS)->toDateString();
        $overdue = StatutoryReturn::query()
            ->whereNull('statutory_returns.submitted_at')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'statutory_returns.payroll_run_id')
            ->whereNotNull('payroll_runs.period_end')
            ->where('payroll_runs.period_end', '<', $cutoff)
            ->count();

        return [
            'generated' => $total - $submitted,
            'submitted' => $submitted,
            'overdue'   => $overdue,
        ];
    }
}
