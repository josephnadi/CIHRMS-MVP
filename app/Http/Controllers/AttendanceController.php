<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Http\Requests\Attendance\ClockSelfRequest;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceSummaryResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $month = $request->query('month', now()->format('Y-m'));
        [$year, $monthN] = array_map('intval', explode('-', $month));

        $start = CarbonImmutable::create($year, $monthN, 1)->startOfMonth();
        $end   = $start->endOfMonth();

        $summaries = AttendanceSummary::with('employee.user')
            ->between($start, $end)
            ->when($request->department_id, function ($q, $v) {
                $q->whereHas('employee', fn ($e) => $e->where('department_id', $v));
            })
            ->orderBy('summary_date')
            ->paginate(50)
            ->withQueryString();

        $stats = [
            'present_today' => AttendanceSummary::whereDate('summary_date', now())
                ->where('status', 'present')->count(),
            'absent_today'  => AttendanceSummary::whereDate('summary_date', now())
                ->where('status', 'absent')->count(),
            'late_today'    => AttendanceSummary::whereDate('summary_date', now())
                ->where('status', 'late')->count(),
            'month_avg_hours' => round((float) AttendanceSummary::between($start, $end)
                ->where('hours_worked', '>', 0)
                ->avg('hours_worked'), 2),
        ];

        return Inertia::render('Attendance/Index', [
            'summaries'    => AttendanceSummaryResource::collection($summaries),
            'stats'        => $stats,
            'month'        => $month,
            'filters'      => $request->only(['department_id']),
            'activeModule' => 'attendance',
        ]);
    }

    public function myAttendance(Request $request): Response
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this user.');

        $month = $request->query('month', now()->format('Y-m'));
        [$year, $monthN] = array_map('intval', explode('-', $month));

        $start = CarbonImmutable::create($year, $monthN, 1)->startOfMonth();
        $end   = $start->endOfMonth();

        // Ensure the period is materialised
        $summary = $this->service->aggregatePeriod($employee, $start, $end);

        $days = AttendanceSummary::where('employee_id', $employee->id)
            ->between($start, $end)
            ->orderBy('summary_date')
            ->get();

        return Inertia::render('Attendance/MyAttendance', [
            'period'   => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => $month],
            'summary'  => $summary,
            'days'     => AttendanceSummaryResource::collection($days),
        ]);
    }

    public function clockSelf(ClockSelfRequest $request): RedirectResponse
    {
        $employee = $request->user()->employee;

        $this->service->record(
            employee:   $employee,
            eventAt:    now(),
            direction:  (string) $request->validated('direction'),
            source:     AttendanceSource::WebKiosk,
            geoLat:     $request->validated('geo_lat'),
            geoLng:     $request->validated('geo_lng'),
            recordedBy: $request->user(),
        );

        return back()->with('success', 'Clock-' . $request->validated('direction') . ' recorded.');
    }

    public function manualEntry(ManualAttendanceRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $this->service->record(
            employee:   $employee,
            eventAt:    (string) $request->validated('event_at'),
            direction:  (string) $request->validated('direction'),
            source:     AttendanceSource::Manual,
            recordedBy: $request->user(),
            reason:     (string) $request->validated('reason'),
        );

        return back()->with('success', 'Manual attendance recorded.');
    }
}
