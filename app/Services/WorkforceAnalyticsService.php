<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Support\DbExpr;
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
            'series' => [
                'headcount_trend'    => $this->headcountTrend($departmentId, $from, $to),
                'by_department'      => $this->headcountByDepartment($departmentId),
                'gender'             => $this->genderBreakdown($departmentId),
                'tenure_bands'       => $this->tenureBands($departmentId, $to),
                'age_bands'          => $this->ageBands($departmentId, $to),
                'span_of_control'    => $this->spanOfControl($departmentId),
                'cost_by_department' => $this->costByDepartment($departmentId),
            ],
            'meta' => ['turnover_caveat' => $this->turnoverCaveat($departmentId, $from, $to)],
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

    private function headcountTrend(?int $deptId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $expr = DbExpr::yearMonth('hire_date');
        // Half-open range (see kpis() comment): '>= from' and '< to+1 day'.
        $joiners = $this->scopedAll($deptId)
            ->where('hire_date', '>=', $from->toDateString())
            ->where('hire_date', '<', $to->addDay()->toDateString())
            ->selectRaw("$expr as ym, COUNT(*) as c")->groupBy('ym')->pluck('c', 'ym');

        $leaveExpr = DbExpr::yearMonth('effective_termination_date');
        $leavers = OffboardingCase::query()
            ->where('effective_termination_date', '>=', $from->toDateString())
            ->where('effective_termination_date', '<', $to->addDay()->toDateString())
            ->when($deptId, fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $deptId)))
            ->selectRaw("$leaveExpr as ym, COUNT(*) as c")->groupBy('ym')->pluck('c', 'ym');

        $out = [];
        $cursor = $from->startOfMonth();
        $end = $to->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $ym = $cursor->format('Y-m');
            $j = (int) ($joiners[$ym] ?? 0);
            $l = (int) ($leavers[$ym] ?? 0);
            $out[] = ['month' => $ym, 'joiners' => $j, 'leavers' => $l, 'net' => $j - $l];
            $cursor = $cursor->addMonth();
        }

        return $out;
    }

    private function headcountByDepartment(?int $deptId): array
    {
        return $this->scopedActive($deptId)
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.name as label, COUNT(*) as value')
            ->groupBy('departments.name')->orderByDesc('value')
            ->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->all();
    }

    private function genderBreakdown(?int $deptId): array
    {
        $rows = $this->scopedActive($deptId)
            ->selectRaw('gender, COUNT(*) as value')->groupBy('gender')->pluck('value', 'gender');

        $labelMap = ['female' => 'Female', 'male' => 'Male'];
        $out = [];
        foreach ($rows as $gender => $value) {
            $key = $gender ? strtolower((string) $gender) : null;
            $label = $key ? ($labelMap[$key] ?? ucfirst($key)) : 'Unspecified';
            $out[$label] = ($out[$label] ?? 0) + (int) $value;
        }

        return collect($out)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function tenureBands(?int $deptId, CarbonImmutable $to): array
    {
        $bands = ['<1y' => 0, '1-3y' => 0, '3-5y' => 0, '5y+' => 0];
        foreach ($this->scopedActive($deptId)->whereNotNull('hire_date')->pluck('hire_date') as $d) {
            $y = CarbonImmutable::parse($d)->floatDiffInYears($to);
            $key = $y < 1 ? '<1y' : ($y < 3 ? '1-3y' : ($y < 5 ? '3-5y' : '5y+'));
            $bands[$key]++;
        }

        return collect($bands)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function ageBands(?int $deptId, CarbonImmutable $to): array
    {
        $bands = ['<25' => 0, '25-34' => 0, '35-44' => 0, '45-54' => 0, '55+' => 0];
        foreach ($this->scopedActive($deptId)->whereNotNull('date_of_birth')->pluck('date_of_birth') as $d) {
            $age = CarbonImmutable::parse($d)->floatDiffInYears($to);
            $key = $age < 25 ? '<25' : ($age < 35 ? '25-34' : ($age < 45 ? '35-44' : ($age < 55 ? '45-54' : '55+')));
            $bands[$key]++;
        }

        return collect($bands)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function spanOfControl(?int $deptId): array
    {
        $counts = $this->scopedActive($deptId)
            ->whereNotNull('manager_id')
            ->selectRaw('manager_id, COUNT(*) as reports')
            ->groupBy('manager_id')->pluck('reports');

        $bands = ['1' => 0, '2-3' => 0, '4-6' => 0, '7+' => 0];
        foreach ($counts as $n) {
            $n = (int) $n;
            $key = $n === 1 ? '1' : ($n <= 3 ? '2-3' : ($n <= 6 ? '4-6' : '7+'));
            $bands[$key]++;
        }

        return collect($bands)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function costByDepartment(?int $deptId): array
    {
        return $this->scopedActive($deptId)
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.name as label, COALESCE(SUM(employees.salary), 0) as value')
            ->groupBy('departments.name')->orderByDesc('value')
            ->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => round((float) $r->value, 2)])->all();
    }

    private function turnoverCaveat(?int $deptId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->scopedAll($deptId)
            ->where('status', EmployeeStatus::Terminated->value)
            ->whereDoesntHave('offboardingCases', fn (Builder $q) => $q
                ->where('effective_termination_date', '>=', $from->toDateString())
                ->where('effective_termination_date', '<', $to->addDay()->toDateString()))
            ->exists();
    }
}
