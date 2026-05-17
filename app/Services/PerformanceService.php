<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\GoalStatus;
use App\Enums\LeaveStatus;
use App\Enums\ReviewCycleStatus;
use App\Enums\ReviewStatus;
use App\Enums\ReviewType;
use App\Enums\TicketStatus;
use App\Events\GoalCreated;
use App\Events\ReviewSubmitted;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\GoalCheckin;
use App\Models\LeaveRequest;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\Ticket;
use App\Support\DbExpr;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PerformanceService
{
    private const TTL = 60;

    public function snapshot(): array
    {
        return Cache::remember('performance_snapshot', self::TTL, fn () => [
            'kpis'             => $this->kpis(),
            'headcountByDept'  => $this->headcountByDept(),
            'hiresByMonth'     => $this->hiresByMonth(),
            'leaveByMonth'     => $this->leaveByMonth(),
            'ticketTrend'      => $this->ticketTrend(),
            'tenureBuckets'    => $this->tenureBuckets(),
            'deptEfficiency'   => $this->deptEfficiency(),
            'topPerformers'    => $this->topPerformers(),
            'leaveTypeSplit'   => $this->leaveTypeSplit(),
            'avgResolveHours'  => $this->avgResolveHours(),
        ]);
    }

    private function kpis(): array
    {
        $totalActive   = Employee::where('status', EmployeeStatus::Active->value)->count();
        $totalAll      = Employee::count();
        $terminated90d = Employee::where('status', EmployeeStatus::Terminated->value)
            ->where('updated_at', '>=', now()->subDays(90))
            ->count();
        $newHires90d   = Employee::where('hire_date', '>=', now()->subDays(90))->count();
        $onLeaveNow    = Employee::where('status', EmployeeStatus::OnLeave->value)->count();

        $retention = $totalAll > 0
            ? round(($totalActive / max($totalAll, 1)) * 100, 1)
            : 0;

        $turnover = $totalActive > 0
            ? round(($terminated90d / $totalActive) * 100, 1)
            : 0;

        return [
            'active'        => $totalActive,
            'on_leave'      => $onLeaveNow,
            'new_hires_90d' => $newHires90d,
            'terminated_90d'=> $terminated90d,
            'retention_pct' => $retention,
            'turnover_pct'  => $turnover,
        ];
    }

    private function headcountByDept(): array
    {
        return Department::withCount(['employees' => fn ($q) => $q->where('status', EmployeeStatus::Active->value)])
            ->orderByDesc('employees_count')
            ->get()
            ->map(fn ($d) => [
                'label' => $d->name,
                'code'  => $d->code,
                'value' => $d->employees_count,
            ])
            ->toArray();
    }

    private function hiresByMonth(): array
    {
        $start = now()->subMonths(11)->startOfMonth();

        $raw = Employee::selectRaw(DbExpr::yearMonth('hire_date') . ' as period, COUNT(*) as total')
            ->where('hire_date', '>=', $start)
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        return $this->fillMonths($start, 12, $raw);
    }

    private function leaveByMonth(): array
    {
        $start = now()->subMonths(11)->startOfMonth();

        $raw = LeaveRequest::selectRaw(DbExpr::yearMonth('start_date') . ' as period, COUNT(*) as total')
            ->where('start_date', '>=', $start)
            ->where('status', LeaveStatus::Approved->value)
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        return $this->fillMonths($start, 12, $raw);
    }

    private function ticketTrend(): array
    {
        $start = now()->subMonths(11)->startOfMonth();

        $raw = Ticket::selectRaw(DbExpr::yearMonth('created_at') . ' as period, COUNT(*) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        return $this->fillMonths($start, 12, $raw);
    }

    private function tenureBuckets(): array
    {
        $now = CarbonImmutable::now();
        $buckets = [
            '< 1 year'   => 0,
            '1-3 years'  => 0,
            '3-5 years'  => 0,
            '5-10 years' => 0,
            '10+ years'  => 0,
        ];

        Employee::where('status', EmployeeStatus::Active->value)
            ->whereNotNull('hire_date')
            ->select('hire_date')
            ->chunk(500, function ($chunk) use (&$buckets, $now) {
                foreach ($chunk as $emp) {
                    $years = $emp->hire_date->diffInYears($now);
                    if ($years < 1)       $buckets['< 1 year']++;
                    elseif ($years < 3)   $buckets['1-3 years']++;
                    elseif ($years < 5)   $buckets['3-5 years']++;
                    elseif ($years < 10)  $buckets['5-10 years']++;
                    else                  $buckets['10+ years']++;
                }
            });

        return collect($buckets)
            ->map(fn ($v, $k) => ['label' => $k, 'value' => $v])
            ->values()
            ->toArray();
    }

    private function deptEfficiency(): array
    {
        return Department::with(['employees' => fn ($q) => $q->where('status', EmployeeStatus::Active->value)])
            ->get()
            ->map(function ($dept) {
                $employeeIds = $dept->employees->pluck('id');
                if ($employeeIds->isEmpty()) {
                    return null;
                }

                $totalTickets    = Ticket::whereIn('employee_id', $employeeIds)->count();
                $resolvedTickets = Ticket::whereIn('employee_id', $employeeIds)
                    ->whereIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
                    ->count();

                $resolutionRate = $totalTickets > 0
                    ? round(($resolvedTickets / $totalTickets) * 100, 1)
                    : 100.0;

                return [
                    'name'  => $dept->name,
                    'code'  => $dept->code,
                    'score' => $resolutionRate,
                    'staff' => $dept->employees->count(),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values()
            ->take(8)
            ->toArray();
    }

    private function topPerformers(): array
    {
        return Employee::with(['user:id,name', 'department:id,name'])
            ->where('status', EmployeeStatus::Active->value)
            ->withCount([
                'tickets as resolved_tickets' => fn ($q) => $q->whereIn('status', [
                    TicketStatus::Resolved->value, TicketStatus::Closed->value,
                ]),
            ])
            ->orderByDesc('resolved_tickets')
            ->limit(6)
            ->get()
            ->map(fn ($e) => [
                'id'           => $e->id,
                'name'         => $e->user?->name ?? '—',
                'position'     => $e->position,
                'department'   => $e->department?->name,
                'employee_no'  => $e->employee_no,
                'resolved'     => $e->resolved_tickets,
            ])
            ->toArray();
    }

    private function leaveTypeSplit(): array
    {
        $year = now()->year;

        return LeaveRequest::selectRaw('type, COUNT(*) as total')
            ->whereYear('start_date', $year)
            ->where('status', LeaveStatus::Approved->value)
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(function ($r) {
                $raw = \is_object($r->type) ? $r->type->value : (string) $r->type;
                return [
                    'label' => ucfirst(str_replace('_', ' ', $raw)),
                    'value' => (int) $r->total,
                ];
            })
            ->toArray();
    }

    private function avgResolveHours(): float
    {
        $expr = 'AVG(ABS(' . DbExpr::hoursBetween('created_at', 'resolved_at') . '))';

        $value = Ticket::whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(90))
            ->selectRaw("$expr as hours")
            ->value('hours');

        return $value ? round((float) $value, 1) : 0.0;
    }

    private function fillMonths(CarbonImmutable|\DateTimeInterface $start, int $count, array $values): array
    {
        $start = CarbonImmutable::instance($start);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $period = $start->addMonths($i);
            $key    = $period->format('Y-m');
            $result[] = [
                'label' => $period->format('M'),
                'period'=> $key,
                'value' => (int) ($values[$key] ?? 0),
            ];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Goals (Wave 13)
    // ─────────────────────────────────────────────────────────────────────

    public function listGoals(Request $request): LengthAwarePaginator
    {
        return Goal::with(['employee.user', 'cycle', 'checkins' => fn ($q) => $q->limit(1)])
            ->when($request->employee_id, fn ($q, $v) => $q->forEmployee((int) $v))
            ->when($request->cycle_id,    fn ($q, $v) => $q->forCycle((int) $v))
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->when($request->search,      fn ($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function findGoal(int $id): Goal
    {
        return Goal::with([
            'employee.user', 'employee.department',
            'cycle', 'parent', 'children.employee.user',
            'checkins.user',
        ])->findOrFail($id);
    }

    public function createGoal(array $data, ?int $createdBy = null): Goal
    {
        $goal = Goal::create([
            ...$data,
            'created_by' => $createdBy,
            'status'     => $data['status'] ?? GoalStatus::Active->value,
        ]);

        event(new GoalCreated($goal));

        return $goal->fresh(['employee.user', 'cycle']);
    }

    public function updateGoal(Goal $goal, array $data): Goal
    {
        $goal->update($data);

        if (($data['status'] ?? null) === GoalStatus::Completed->value && $goal->completed_at === null) {
            $goal->update(['completed_at' => now()]);
        }

        return $goal->fresh(['employee.user', 'cycle']);
    }

    public function recordCheckin(Goal $goal, array $data, int $userId): GoalCheckin
    {
        return DB::transaction(function () use ($goal, $data, $userId) {
            $checkin = $goal->checkins()->create([
                'user_id'       => $userId,
                'progress_pct'  => $data['progress_pct']  ?? null,
                'current_value' => $data['current_value'] ?? null,
                'narrative'     => $data['narrative']     ?? null,
                'mood'          => $data['mood']          ?? null,
                'recorded_at'   => now(),
            ]);

            // Mirror current_value back onto the goal so cards/lists show fresh progress.
            if (isset($data['current_value'])) {
                $goal->update(['current_value' => $data['current_value']]);
            }

            // Auto-flip status to AtRisk when amber/red mood is reported on an Active goal.
            if ($goal->status === GoalStatus::Active && in_array($data['mood'] ?? null, ['amber', 'red'], true)) {
                $goal->update(['status' => GoalStatus::AtRisk]);
            }

            return $checkin;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reviews (Wave 13)
    // ─────────────────────────────────────────────────────────────────────

    public function listReviews(Request $request): LengthAwarePaginator
    {
        return Review::with(['cycle', 'employee.user', 'reviewer'])
            ->when($request->cycle_id,    fn ($q, $v) => $q->forCycle((int) $v))
            ->when($request->employee_id, fn ($q, $v) => $q->forEmployee((int) $v))
            ->when($request->reviewer_id, fn ($q, $v) => $q->where('reviewer_id', (int) $v))
            ->when($request->type,        fn ($q, $v) => $q->where('type', $v))
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function createReview(array $data): Review
    {
        return Review::create([
            ...$data,
            'status' => $data['status'] ?? ReviewStatus::Draft->value,
        ])->fresh(['cycle', 'employee.user', 'reviewer']);
    }

    public function submitReview(Review $review): Review
    {
        $review->update([
            'status'       => ReviewStatus::Submitted,
            'submitted_at' => now(),
        ]);

        event(new ReviewSubmitted($review));

        return $review->fresh(['cycle', 'employee.user', 'reviewer']);
    }

    public function acknowledgeReview(Review $review): Review
    {
        $review->update([
            'status'          => ReviewStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);
        return $review;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cycles (Wave 13)
    // ─────────────────────────────────────────────────────────────────────

    public function listCycles(): Collection
    {
        return ReviewCycle::withCount(['reviews', 'goals'])
            ->orderByDesc('starts_at')
            ->limit(20)
            ->get();
    }

    public function activeCycle(): ?ReviewCycle
    {
        return ReviewCycle::active()->latest('starts_at')->first();
    }

    public function createCycle(array $data, ?int $openedBy = null): ReviewCycle
    {
        return ReviewCycle::create([
            ...$data,
            'opened_by' => $openedBy,
            'status'    => $data['status'] ?? ReviewCycleStatus::Draft->value,
        ]);
    }

    public function closeCycle(ReviewCycle $cycle): ReviewCycle
    {
        $cycle->update([
            'status'    => ReviewCycleStatus::Closed,
            'closed_at' => now(),
        ]);
        return $cycle;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 9-box matrix (Wave 13)
    //
    // Performance (X) × Potential (Y), each on a 1..5 scale, bucketed to
    // {Low: 1-2.33, Medium: 2.34-3.66, High: 3.67-5}. Returns a 9-cell grid.
    // ─────────────────────────────────────────────────────────────────────

    public function nineBoxMatrix(?int $cycleId = null): array
    {
        $cycle = $cycleId
            ? ReviewCycle::find($cycleId)
            : $this->activeCycle();

        $cells = $this->emptyMatrix();

        if (! $cycle) {
            return [
                'cycle' => null,
                'cells' => $cells,
                'total' => 0,
            ];
        }

        // Average each employee's submitted ratings within the cycle.
        $rows = Review::query()
            ->forCycle($cycle->id)
            ->submitted()
            ->whereNotNull('performance_rating')
            ->whereNotNull('potential_rating')
            ->select('employee_id')
            ->selectRaw('AVG(performance_rating) as avg_perf')
            ->selectRaw('AVG(potential_rating)   as avg_pot')
            ->groupBy('employee_id')
            ->with('employee.user', 'employee.department')
            ->get();

        $employees = Employee::with('user:id,name', 'department:id,name')
            ->whereIn('id', $rows->pluck('employee_id'))
            ->get()
            ->keyBy('id');

        foreach ($rows as $r) {
            $perfBucket = $this->bucket((float) $r->avg_perf);
            $potBucket  = $this->bucket((float) $r->avg_pot);
            $key = "{$potBucket}_{$perfBucket}";

            $emp = $employees->get($r->employee_id);
            if (! $emp) continue;

            $cells[$key]['count']++;
            $cells[$key]['employees'][] = [
                'id'         => $emp->id,
                'name'       => $emp->user?->name ?? "Employee #{$emp->id}",
                'position'   => $emp->position,
                'department' => $emp->department?->name,
                'avg_perf'   => round((float) $r->avg_perf, 2),
                'avg_pot'    => round((float) $r->avg_pot, 2),
            ];
        }

        return [
            'cycle' => [
                'id'   => $cycle->id,
                'name' => $cycle->name,
            ],
            'cells' => array_values($cells),
            'total' => $rows->count(),
        ];
    }

    /** Build a labelled empty 9-cell grid with stable ordering (top-left first). */
    protected function emptyMatrix(): array
    {
        $labels = [
            'high_low'    => 'Enigma',          'high_medium' => 'Growth Employee',  'high_high'   => 'Future Leader',
            'medium_low'  => 'Inconsistent',    'medium_medium' => 'Core Player',    'medium_high' => 'High Impact Performer',
            'low_low'     => 'Risk',            'low_medium'  => 'Effective',        'low_high'    => 'Trusted Professional',
        ];

        $cells = [];
        foreach ($labels as $key => $label) {
            [$pot, $perf] = explode('_', $key);
            $cells[$key] = [
                'key'          => $key,
                'label'        => $label,
                'potential'    => $pot,
                'performance'  => $perf,
                'count'        => 0,
                'employees'    => [],
            ];
        }
        return $cells;
    }

    protected function bucket(float $score): string
    {
        return match (true) {
            $score >= 3.67 => 'high',
            $score >= 2.34 => 'medium',
            default        => 'low',
        };
    }
}
