<?php

namespace App\Services\Establishment;

use App\Enums\PositionStatus;
use App\Models\Employee;
use App\Models\EstablishmentCeiling;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Establishment-aware position service.
 *
 * Enforces approved-headcount ceilings on hire/fill. Records every
 * position change as a `position_assignments` row so transfers,
 * acting appointments, and promotions are auditable.
 */
class PositionService
{
    public function create(array $data): Position
    {
        return Position::create($data);
    }

    public function update(Position $position, array $data): Position
    {
        $position->update($data);
        return $position->fresh();
    }

    public function freeze(Position $position, string $reason): Position
    {
        $position->update([
            'status'         => PositionStatus::Frozen,
            'job_description'=> trim(($position->job_description ?? '') . "\n[FROZEN] " . $reason),
        ]);
        return $position;
    }

    /**
     * Assign an employee to a position. Enforces:
     *   - position is fillable (not frozen)
     *   - establishment ceiling for (department × grade × fiscal year) not exceeded,
     *     unless caller holds `establishment.exceed` permission
     */
    public function assign(Position $position, Employee $employee, User $actor, bool $isActing = false, ?string $reason = null): PositionAssignment
    {
        if (! $position->status->canBeFilled() && ! $actor->hasPermission('establishment.exceed')) {
            throw new \DomainException("Position {$position->code} is not fillable (status: {$position->status->value}).");
        }

        // First-pass ceiling check (cheap, no lock). Re-validated under lock
        // inside the transaction below to close the TOCTOU window where two
        // concurrent assigns both pass this gate.
        if ($position->grade_id && $position->department_id) {
            $fiscalYear = now()->year;
            $ceiling = EstablishmentCeiling::where('department_id', $position->department_id)
                ->where('grade_id', $position->grade_id)
                ->where('fiscal_year', $fiscalYear)
                ->first();

            if ($ceiling) {
                $filled = Position::where('department_id', $position->department_id)
                    ->where('grade_id', $position->grade_id)
                    ->where('status', PositionStatus::Filled->value)
                    ->count();

                if ($filled >= $ceiling->approved_headcount && ! $actor->hasPermission('establishment.exceed')) {
                    throw new \DomainException(
                        "Establishment ceiling of {$ceiling->approved_headcount} reached for this grade in this department for FY{$fiscalYear}."
                    );
                }
            }
        }

        return DB::transaction(function () use ($position, $employee, $actor, $isActing, $reason) {
            // Re-fetch the position under a row lock and re-validate the
            // ceiling inside the transaction. This guards against two
            // concurrent assigns that both passed the cheap pre-check above
            // and would otherwise overshoot the approved headcount by one.
            $locked = Position::whereKey($position->id)->lockForUpdate()->first();

            if ($locked && $locked->grade_id && $locked->department_id) {
                $fiscalYear = now()->year;
                $ceiling = EstablishmentCeiling::where('department_id', $locked->department_id)
                    ->where('grade_id', $locked->grade_id)
                    ->where('fiscal_year', $fiscalYear)
                    ->lockForUpdate()
                    ->first();

                if ($ceiling) {
                    $filled = Position::where('department_id', $locked->department_id)
                        ->where('grade_id', $locked->grade_id)
                        ->where('status', PositionStatus::Filled->value)
                        ->lockForUpdate()
                        ->count();

                    if ($filled >= $ceiling->approved_headcount && ! $actor->hasPermission('establishment.exceed')) {
                        throw new \DomainException(
                            "Establishment ceiling of {$ceiling->approved_headcount} reached for this grade in this department for FY{$fiscalYear}."
                        );
                    }
                }
            }

            // Close prior active assignment for this employee
            $employee->positionAssignments()
                ->whereNull('end_date')
                ->update(['end_date' => now()->toDateString()]);

            $assignment = PositionAssignment::create([
                'position_id'   => $position->id,
                'employee_id'   => $employee->id,
                'start_date'    => now()->toDateString(),
                'is_acting'     => $isActing,
                'step_at_start' => $employee->current_step ?? 1,
                'reason'        => $reason,
            ]);

            $position->update([
                'status' => $isActing ? PositionStatus::Acting : PositionStatus::Filled,
            ]);

            $employee->update([
                'current_position_id' => $position->id,
                'current_grade_id'    => $position->grade_id,
            ]);

            return $assignment;
        });
    }

    public function vacate(Position $position, ?string $reason = null): Position
    {
        return DB::transaction(function () use ($position, $reason) {
            // Close all active assignments for this position
            PositionAssignment::where('position_id', $position->id)
                ->whereNull('end_date')
                ->each(function (PositionAssignment $a) use ($reason) {
                    $a->update([
                        'end_date' => now()->toDateString(),
                        'reason'   => $reason,
                    ]);

                    // Clear the employee's current position pointer
                    Employee::where('current_position_id', $a->position_id)
                        ->where('id', $a->employee_id)
                        ->update(['current_position_id' => null]);
                });

            $position->update(['status' => PositionStatus::Vacant]);
            return $position->fresh();
        });
    }
}
