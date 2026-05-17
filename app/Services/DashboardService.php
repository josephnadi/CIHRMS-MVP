<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Complaint;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\Payment;
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
