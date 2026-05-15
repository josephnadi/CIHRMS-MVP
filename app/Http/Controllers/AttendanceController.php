<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Http\Requests\Attendance\AssignShiftRequest;
use App\Http\Requests\Attendance\ClockSelfRequest;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Requests\Attendance\StoreShiftRequest;
use App\Http\Requests\Attendance\UpdateShiftRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceSummaryResource;
use App\Http\Resources\ShiftResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSummary;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
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

    public function shiftsIndex(): \Inertia\Response
    {
        abort_unless($this->authorizeShiftManage(), 403);

        return \Inertia\Inertia::render('Attendance/Shifts', [
            'shifts' => ShiftResource::collection(
                Shift::with('department:id,name')->latest()->paginate(20)
            ),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'code']),
            'employees'   => Employee::with('user:id,name')->active()->orderBy('id')->get(['id', 'user_id', 'employee_no', 'position']),
            'assignments' => ShiftAssignment::with(['shift:id,name,code', 'employee:id,user_id,employee_no'])
                ->where(function ($q) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', today());
                })
                ->latest('effective_from')
                ->limit(50)
                ->get(),
        ]);
    }

    public function storeShift(StoreShiftRequest $request)
    {
        $data = $request->validated();
        // Default working_days if not supplied
        if (! isset($data['working_days']) || ! $data['working_days']) {
            $data['working_days'] = ['mon','tue','wed','thu','fri'];
        }
        $shift = Shift::create($data);
        return back()->with('success', "Shift {$shift->code} created");
    }

    public function updateShift(UpdateShiftRequest $request, Shift $shift)
    {
        $shift->update($request->validated());
        return back()->with('success', "Shift {$shift->code} updated");
    }

    public function destroyShift(Shift $shift)
    {
        abort_unless($this->authorizeShiftManage(), 403);
        $shift->delete();
        return back()->with('success', 'Shift archived');
    }

    public function assignShift(AssignShiftRequest $request)
    {
        ShiftAssignment::create($request->validated());
        return back()->with('success', 'Shift assigned');
    }

    private function authorizeShiftManage(): bool
    {
        return request()->user()?->hasPermission('attendance.shift_manage') ?? false;
    }
}
