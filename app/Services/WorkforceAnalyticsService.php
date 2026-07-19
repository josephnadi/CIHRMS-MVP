<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\OffboardingCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WorkforceAnalyticsService
{
    /**
     * @return array{kpis: array, series: array, meta: array}
     */
    public function metrics(?int $departmentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'kpis'   => $this->kpis($departmentId, $from, $to),
            'series' => [], // filled in Task 3
            'meta'   => ['turnover_caveat' => false], // filled in Task 3
        ];
    }

    private function kpis(?int $deptId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $headcount = (int) $this->scopedActive($deptId)->count();

        // Half-open range (>= from, < to+1 day) rather than whereBetween: Laravel
        // always serializes date-cast columns for storage using the grammar's
        // full datetime format (Y-m-d H:i:s), so on SQLite (TEXT affinity, no
        // native DATE type) a value dated exactly `$to` is stored as
        // "…00:00:00" and sorts AFTER a date-only upper bound in a between
        // clause, silently dropping the boundary day. Comparing "< to+1 day"
        // is boundary-safe on both SQLite and Postgres.
        $newHires = (int) $this->scopedAll($deptId)
            ->where('hire_date', '>=', $from->toDateString())
            ->where('hire_date', '<', $to->addDay()->toDateString())
            ->count();

        $leavers = (int) OffboardingCase::query()
            ->where('effective_termination_date', '>=', $from->toDateString())
            ->where('effective_termination_date', '<', $to->addDay()->toDateString())
            ->when($deptId, fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('department_id', $deptId)
            ))
            ->count();

        $days = max(1, $from->diffInDays($to) + 1);
        $turnover = $headcount > 0
            ? round(($leavers / $headcount) * (365 / $days) * 100, 1)
            : 0.0;

        $avgTenure = $this->avgTenure($deptId, $to);

        return [
            'headcount'       => $headcount,
            'new_hires'       => $newHires,
            'leavers'         => $leavers,
            'turnover_rate'   => $turnover,
            'avg_tenure'      => $avgTenure,
            'headcount_delta' => $newHires - $leavers,
        ];
    }

    private function avgTenure(?int $deptId, CarbonImmutable $to): float
    {
        $dates = $this->scopedActive($deptId)
            ->whereNotNull('hire_date')
            ->pluck('hire_date');

        if ($dates->isEmpty()) {
            return 0.0;
        }

        $years = $dates->map(fn ($d) => CarbonImmutable::parse($d)->floatDiffInYears($to));

        return round((float) $years->avg(), 1);
    }

    private function scopedActive(?int $deptId): Builder
    {
        return $this->scopedAll($deptId)->where('status', EmployeeStatus::Active->value);
    }

    private function scopedAll(?int $deptId): Builder
    {
        return Employee::query()->when($deptId, fn (Builder $q) => $q->where('department_id', $deptId));
    }
}
