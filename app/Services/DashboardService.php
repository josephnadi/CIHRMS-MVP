<?php

namespace App\Services;

use App\Enums\DisbursementStatus;
use App\Enums\LeaveStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayrollRunStatus;
use App\Models\AnalyticsEvent;
use App\Models\Complaint;
use App\Models\Department;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DbExpr;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DashboardService
{
    private const STATS_TTL = 60;

    public function getStats(User $user): array
    {
        $cacheKey = "dashboard_stats_{$user->id}_{$user->role?->value}";

        return Cache::remember($cacheKey, self::STATS_TTL, function () {
            return [
                'employees'       => Employee::count(),
                'pendingLeave'    => LeaveRequest::pending()->count(),
                'openTickets'     => Ticket::open()->count(),
                'openComplaints'  => Complaint::open()->count(),
                'openJobs'        => JobPosting::open()->count(),
                'pendingPayments' => Payment::pending()->count(),
            ];
        });
    }

    public function getRecentEvents(int $limit = 12): Collection
    {
        return AnalyticsEvent::with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getEmployees(int $limit = 20): Collection
    {
        return Employee::with(['department:id,name', 'user:id,name,email'])
            ->active()
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getTickets(int $limit = 20): Collection
    {
        return Ticket::with('employee:id,employee_no,position')
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getHeadcountByDept(): array
    {
        return Department::withCount(['employees' => fn ($q) => $q->active()])
            ->orderByDesc('employees_count')
            ->limit(6)
            ->get()
            ->map(fn ($d) => ['label' => $d->name, 'value' => $d->employees_count])
            ->toArray();
    }

    public function getLeaveByMonth(int $year): array
    {
        return LeaveRequest::selectRaw(DbExpr::month('start_date') . ' as month, COUNT(*) as total')
            ->whereYear('start_date', $year)
            ->approved()
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();
    }

    public function getTicketTrend(): array
    {
        return Ticket::selectRaw(DbExpr::week('created_at') . ' as week, COUNT(*) as total')
            ->where('created_at', '>=', now()->subWeeks(12))
            ->groupBy('week')
            ->pluck('total', 'week')
            ->toArray();
    }

    private const METRIC_EVENT_TYPES = [
        'employees'        => ['employee.created'],
        'open_tickets'     => ['ticket.created'],
        'pending_leave'    => ['leave.requested'],
        'pending_payments' => ['payment.created'],
        'payslips_paid'    => ['payment.paid', 'payslip.generated'],
        'applicants'       => ['recruitment.applicant.created'],
    ];

    public function timeSeries(string $metric, int $days = 30): array
    {
        if (! isset(self::METRIC_EVENT_TYPES[$metric])) {
            throw new InvalidArgumentException("Unsupported metric: {$metric}");
        }

        return Cache::remember(
            "dashboard.timeseries.{$metric}.{$days}",
            self::STATS_TTL,
            fn () => $this->buildSeries($metric, $days)
        );
    }

    private function buildSeries(string $metric, int $days): array
    {
        $eventTypes = self::METRIC_EVENT_TYPES[$metric];
        $from = Carbon::today()->subDays($days - 1);

        // DATE() is supported by SQLite, MySQL, and PostgreSQL — no driver split needed.
        $rows = AnalyticsEvent::query()
            ->selectRaw('DATE(created_at) as event_date, COUNT(*) as total')
            ->whereIn('event', $eventTypes)
            ->where('created_at', '>=', $from)
            ->groupBy('event_date')
            ->pluck('total', 'event_date');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $date, 'value' => (int) ($rows[$date] ?? 0)];
        }

        return $series;
    }

    private const EVENT_PRESENTATION = [
        'employee.created'              => ['icon' => 'person_add',    'color' => '#316bf3'],
        'leave.requested'               => ['icon' => 'calendar_today','color' => '#d97706'],
        'leave.status_updated'          => ['icon' => 'check_circle',  'color' => '#059669'],
        'ticket.created'                => ['icon' => 'support_agent', 'color' => '#dc2626'],
        'payment.created'               => ['icon' => 'payments',      'color' => '#059669'],
        'payment.paid'                  => ['icon' => 'payments',      'color' => '#059669'],
        'payslip.generated'             => ['icon' => 'receipt_long',  'color' => '#0f766e'],
        'recruitment.applicant.created' => ['icon' => 'person_search', 'color' => '#7c3aed'],
    ];

    public function getRecentActivityFeed(int $limit = 12): array
    {
        return AnalyticsEvent::with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (AnalyticsEvent $e) {
                $preset = self::EVENT_PRESENTATION[$e->event] ?? ['icon' => 'history', 'color' => '#64748b'];
                return [
                    'text'  => $this->describeEvent($e),
                    'icon'  => $preset['icon'],
                    'color' => $preset['color'],
                    'time'  => $e->created_at?->diffForHumans() ?? '',
                ];
            })
            ->all();
    }

    /**
     * Finance-officer-tailored snapshot. Real money posture: pending payroll
     * runs, what's queued to disburse, settlement health, statutory returns
     * still to file. Cached for 60s because a finance officer hits refresh
     * a lot and these are SUM() queries.
     */
    public function getFinanceSnapshot(): array
    {
        return Cache::remember('dashboard.finance', self::STATS_TTL, function () {
            $now = now();

            // Payroll runs by status — what's waiting on me?
            $runs = PayrollRun::query()
                ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(net_total), 0) as net')
                ->groupBy('status')
                ->get()
                ->keyBy(fn ($r) => is_object($r->status) ? $r->status->value : (string) $r->status);

            $byStatus = fn (string $s) => [
                'count' => (int) ($runs[$s]->total ?? 0),
                'net'   => (float) ($runs[$s]->net ?? 0),
            ];

            // Disbursement queue health
            $disburseStats = Disbursement::query()
                ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(net_to_recipient), 0) as amount')
                ->groupBy('status')
                ->get()
                ->keyBy(fn ($r) => is_object($r->status) ? $r->status->value : (string) $r->status);

            $disburse = fn (string $s) => [
                'count'  => (int) ($disburseStats[$s]->total ?? 0),
                'amount' => (float) ($disburseStats[$s]->amount ?? 0),
            ];

            return [
                'payroll' => [
                    'draft'      => $byStatus(PayrollRunStatus::Draft->value),
                    'calculated' => $byStatus(PayrollRunStatus::Calculated->value),
                    'approved'   => $byStatus(PayrollRunStatus::Approved->value),
                    'paid_ytd'   => $this->paidThisYear($now),
                ],
                'disbursement' => [
                    'pending'  => $disburse(DisbursementStatus::Pending->value),
                    'sent'     => $disburse(DisbursementStatus::Sent->value),
                    'settled'  => $disburse(DisbursementStatus::Settled->value),
                    'failed'   => $disburse(DisbursementStatus::Failed->value),
                ],
                'payments' => [
                    'pending_count'  => Payment::pending()->count(),
                    'pending_amount' => (float) Payment::pending()->sum('amount'),
                    'paid_30d'       => (float) Payment::paid()->where('paid_at', '>=', $now->copy()->subDays(30))->sum('amount'),
                ],
                'statutory' => $this->statutoryDuePosture($now),
                'recent_runs' => PayrollRun::query()
                    ->latest('period_end')
                    ->limit(5)
                    ->get(['id', 'reference', 'period_year', 'period_month', 'status', 'net_total', 'approved_at', 'paid_at'])
                    ->map(fn (PayrollRun $r) => [
                        'reference' => $r->reference,
                        'period'    => $r->periodLabel(),
                        'status'    => is_object($r->status) ? $r->status->value : (string) $r->status,
                        'net_total' => (float) $r->net_total,
                        'approved'  => (bool) $r->approved_at,
                        'paid'      => (bool) $r->paid_at,
                    ])->all(),
            ];
        });
    }

    /** Net total paid out year-to-date, used by the Finance hero. */
    private function paidThisYear(Carbon $now): array
    {
        $jan = $now->copy()->startOfYear();
        $total = (float) PayrollRun::query()
            ->where('status', PayrollRunStatus::Paid->value)
            ->where('paid_at', '>=', $jan)
            ->sum('net_total');

        return ['count' => (int) PayrollRun::query()
            ->where('status', PayrollRunStatus::Paid->value)
            ->where('paid_at', '>=', $jan)->count(),
                'net' => $total];
    }

    /**
     * Statutory returns (PAYE/SSNIT/NHIA/Tier-2) due posture. There's no
     * `status` column — state is derived: generated_at always set; submitted
     * if submitted_at present; overdue if generated >7 days ago and not yet
     * submitted (statutory deadlines are tighter, but 7d is a safe nag).
     */
    private function statutoryDuePosture(Carbon $now): array
    {
        $total      = StatutoryReturn::count();
        $submitted  = StatutoryReturn::query()->whereNotNull('submitted_at')->count();
        $generated  = $total - $submitted;
        $overdueCut = $now->copy()->subDays(7);
        $overdue    = StatutoryReturn::query()
            ->whereNull('submitted_at')
            ->where('generated_at', '<', $overdueCut)
            ->count();

        return [
            'generated' => $generated,
            'submitted' => $submitted,
            'overdue'   => $overdue,
        ];
    }

    /**
     * Manager-tailored snapshot — "what does my team need me to do?"
     * Scoped to direct reports (Employee.manager_id = manager.employee.id).
     */
    public function getManagerSnapshot(User $manager): array
    {
        return Cache::remember("dashboard.manager.{$manager->id}", self::STATS_TTL, function () use ($manager) {
            $myEmployee = $manager->employee;
            if (! $myEmployee) {
                return $this->emptyManagerSnapshot();
            }

            $teamIds = Employee::query()
                ->where('manager_id', $myEmployee->id)
                ->pluck('id');

            if ($teamIds->isEmpty()) {
                return $this->emptyManagerSnapshot();
            }

            $pendingLeave = LeaveRequest::query()
                ->whereIn('employee_id', $teamIds)
                ->where('status', LeaveStatus::Pending->value)
                ->with('employee:id,employee_no,position,user_id', 'employee.user:id,name')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (LeaveRequest $r) => [
                    'id'           => $r->id,
                    'employee'     => $r->employee?->user?->name ?? $r->employee?->employee_no,
                    'employee_no'  => $r->employee?->employee_no,
                    'position'     => $r->employee?->position,
                    'start_date'   => $r->start_date?->format('Y-m-d'),
                    'end_date'     => $r->end_date?->format('Y-m-d'),
                    'days'         => $r->start_date && $r->end_date
                        ? max(1, $r->start_date->diffInDays($r->end_date) + 1) : null,
                    'leave_type'   => $r->type instanceof \BackedEnum ? $r->type->value : (string) $r->type,
                ])->all();

            // tickets table has no `reference` / `subject` columns — the actual
            // schema (see 2026_05_12_081020_create_tickets_table) uses `id` + `title`.
            // SQLite tolerated the wrong column list when the result was empty;
            // Postgres rejects unknown columns upfront. Fixed to match real schema.
            $myTickets = Ticket::query()
                ->whereIn('employee_id', $teamIds)
                ->whereIn('status', ['open', 'in_progress'])
                ->latest()
                ->limit(8)
                ->get(['id', 'title', 'status', 'priority', 'created_at'])
                ->map(fn (Ticket $t) => [
                    'reference' => 'TKT-' . str_pad((string) $t->id, 6, '0', STR_PAD_LEFT),
                    'subject'   => $t->title,
                    'status'    => $t->status,
                    'priority'  => $t->priority,
                    'age_days'  => $t->created_at?->diffInDays(now()),
                ])->all();

            return [
                'team_size'      => $teamIds->count(),
                'team_active'    => Employee::query()->whereIn('id', $teamIds)->active()->count(),
                'pending_leave_count'  => LeaveRequest::query()->whereIn('employee_id', $teamIds)->where('status', LeaveStatus::Pending->value)->count(),
                'open_ticket_count'    => Ticket::query()->whereIn('employee_id', $teamIds)->whereIn('status', ['open', 'in_progress'])->count(),
                'pending_leave_list'   => $pendingLeave,
                'open_tickets_list'    => $myTickets,
            ];
        });
    }

    private function emptyManagerSnapshot(): array
    {
        return [
            'team_size' => 0, 'team_active' => 0,
            'pending_leave_count' => 0, 'open_ticket_count' => 0,
            'pending_leave_list'  => [], 'open_tickets_list' => [],
        ];
    }

    /**
     * Department-head snapshot — wider scope than a single line manager:
     * the whole department's headcount, leave calendar, tickets, complaints.
     */
    public function getDeptHeadSnapshot(User $head): array
    {
        return Cache::remember("dashboard.deptHead.{$head->id}", self::STATS_TTL, function () use ($head) {
            // Find the department this user heads. We default to the user's
            // own employee.department unless an explicit `heads_department_id`
            // column exists on users (it does not, but if added later this
            // method will pick it up automatically).
            $deptId = $head->heads_department_id ?? $head->employee?->department_id;

            if (! $deptId) {
                return ['dept' => null, 'headcount' => 0, 'on_leave_today' => 0,
                        'open_tickets' => 0, 'open_complaints' => 0, 'recent_leave' => []];
            }

            $dept = Department::find($deptId);
            $today = now()->toDateString();

            $employeeIds = Employee::query()->where('department_id', $deptId)->pluck('id');

            return [
                'dept'           => $dept ? ['id' => $dept->id, 'name' => $dept->name] : null,
                'headcount'      => $employeeIds->count(),
                'active'         => Employee::query()->where('department_id', $deptId)->active()->count(),
                'on_leave_today' => LeaveRequest::query()
                    ->whereIn('employee_id', $employeeIds)
                    ->where('status', LeaveStatus::Approved->value)
                    ->where('start_date', '<=', $today)
                    ->where('end_date',   '>=', $today)
                    ->count(),
                'open_tickets'    => Ticket::query()->whereIn('employee_id', $employeeIds)->whereIn('status', ['open', 'in_progress'])->count(),
                // complaints have no employee_id FK by design (anonymous-friendly,
                // submitted_by is a free string per Whistleblower Act). Count
                // org-wide open complaints; the manager dashboard surfaces the
                // figure as a general indicator, not a per-team metric.
                'open_complaints' => Complaint::open()->count(),
                'recent_leave'    => LeaveRequest::query()
                    ->whereIn('employee_id', $employeeIds)
                    ->with('employee:id,employee_no,user_id', 'employee.user:id,name')
                    ->latest()
                    ->limit(6)
                    ->get()
                    ->map(fn (LeaveRequest $r) => [
                        'employee' => $r->employee?->user?->name ?? $r->employee?->employee_no,
                        'status'   => is_object($r->status) ? $r->status->value : (string) $r->status,
                        'start'    => $r->start_date?->format('Y-m-d'),
                        'end'      => $r->end_date?->format('Y-m-d'),
                    ])->all(),
            ];
        });
    }

    private function describeEvent(AnalyticsEvent $e): string
    {
        $who = $e->user?->name ?? 'System';
        return match ($e->event) {
            'employee.created'              => "New hire onboarded — {$who}",
            'leave.requested'               => "Leave requested — {$who}",
            'leave.status_updated'          => "Leave decision — {$who}",
            'ticket.created'                => "Service ticket opened — {$who}",
            'payment.created'               => "Payment record created",
            'payment.paid'                  => "Payment marked paid",
            'payslip.generated'             => "Payslip generated",
            'recruitment.applicant.created' => "New applicant received",
            default                         => $e->event,
        };
    }
}
