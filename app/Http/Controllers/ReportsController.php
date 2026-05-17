<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\Ticket;
use App\Support\DbExpr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reports/Index', [
            'activeModule' => 'reports',
            'reportTypes'  => [
                ['key' => 'headcount', 'label' => 'Headcount Report',      'description' => 'All active staff with department and position details'],
                ['key' => 'leave',     'label' => 'Leave Summary',         'description' => 'Approved leave requests grouped by type and department'],
                ['key' => 'payroll',   'label' => 'Payroll Export',        'description' => 'Monthly payment records with amounts and status'],
                ['key' => 'tickets',   'label' => 'Ticket SLA Report',     'description' => 'Resolved tickets with SLA compliance metrics'],
                ['key' => 'turnover',  'label' => 'Staff Turnover Report', 'description' => 'Terminated employees with tenure analysis'],
            ],
            'previews'     => Cache::remember('reports_previews', 60, fn () => $this->previews()),
        ]);
    }

    private function previews(): array
    {
        return [
            'headcount' => [
                'metric'   => Employee::where('status', EmployeeStatus::Active->value)->count(),
                'metric_label' => 'Active staff',
                'series'   => Department::withCount(['employees' => fn ($q) => $q->where('status', EmployeeStatus::Active->value)])
                    ->orderByDesc('employees_count')
                    ->limit(6)
                    ->get()
                    ->map(fn ($d) => ['label' => $d->name, 'value' => $d->employees_count])
                    ->toArray(),
            ],

            'leave' => [
                'metric'       => LeaveRequest::where('status', LeaveStatus::Approved->value)
                    ->whereYear('start_date', now()->year)
                    ->count(),
                'metric_label' => 'Approved this year',
                'series'       => LeaveRequest::selectRaw('type as label, COUNT(*) as value')
                    ->where('status', LeaveStatus::Approved->value)
                    ->whereYear('start_date', now()->year)
                    ->groupBy('type')
                    ->orderByDesc('value')
                    ->get()
                    ->map(fn ($r) => ['label' => ucfirst(str_replace('_', ' ', $r->label)), 'value' => (int) $r->value])
                    ->toArray(),
            ],

            'payroll' => [
                'metric'       => round((float) Payment::where('status', PaymentStatus::Paid->value)
                    ->whereMonth('paid_at', now()->month)
                    ->whereYear('paid_at', now()->year)
                    ->sum('amount'), 2),
                'metric_label' => 'Paid this month (GHS)',
                'series'       => Payment::selectRaw(DbExpr::yearMonth('paid_at') . ' as label, SUM(amount) as value')
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '>=', now()->subMonths(5)->startOfMonth())
                    ->where('status', PaymentStatus::Paid->value)
                    ->groupBy('label')
                    ->orderBy('label')
                    ->get()
                    ->map(fn ($r) => ['label' => substr((string) $r->label, 5), 'value' => round((float) $r->value, 2)])
                    ->toArray(),
            ],

            'tickets' => [
                'metric'       => Ticket::whereIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])->count(),
                'metric_label' => 'Resolved tickets',
                'series'       => Ticket::selectRaw('priority as label, COUNT(*) as value')
                    ->whereIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
                    ->groupBy('priority')
                    ->orderByDesc('value')
                    ->get()
                    ->map(fn ($r) => ['label' => ucfirst($r->label), 'value' => (int) $r->value])
                    ->toArray(),
            ],

            'turnover' => [
                'metric'       => Employee::where('status', EmployeeStatus::Terminated->value)
                    ->where('updated_at', '>=', now()->subMonths(12))
                    ->count(),
                'metric_label' => 'Terminated (12mo)',
                'series'       => Employee::selectRaw(DbExpr::yearMonth('updated_at') . ' as label, COUNT(*) as value')
                    ->where('status', EmployeeStatus::Terminated->value)
                    ->where('updated_at', '>=', now()->subMonths(11)->startOfMonth())
                    ->groupBy('label')
                    ->orderBy('label')
                    ->get()
                    ->map(fn ($r) => ['label' => substr((string) $r->label, 5), 'value' => (int) $r->value])
                    ->toArray(),
            ],
        ];
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $type = $request->validate([
            'type' => 'required|in:headcount,leave,payroll,tickets,turnover',
        ])['type'];

        $exportClass = match ($type) {
            'headcount' => \App\Exports\HeadcountExport::class,
            'leave'     => \App\Exports\LeaveSummaryExport::class,
            'payroll'   => \App\Exports\PayrollExport::class,
            'tickets'   => \App\Exports\TicketSlaExport::class,
            'turnover'  => \App\Exports\TurnoverExport::class,
        };

        $args = match ($type) {
            'leave'   => [$request->input('year', now()->year)],
            'payroll' => [$request->input('month', now()->format('Y-m'))],
            default   => [],
        };

        $filename = "{$type}-report-" . now()->format('Ymd') . '.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(new $exportClass(...$args), $filename);
    }
}
