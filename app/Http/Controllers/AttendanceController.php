<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Http\Requests\Attendance\AssignShiftRequest;
use App\Http\Requests\Attendance\ClockSelfRequest;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Requests\Attendance\ReviewCorrectionRequest;
use App\Http\Requests\Attendance\StoreCorrectionRequest;
use App\Http\Requests\Attendance\StoreShiftRequest;
use App\Http\Requests\Attendance\UpdateShiftRequest;
use App\Http\Resources\AttendanceCorrectionResource;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceSummaryResource;
use App\Http\Resources\ShiftResource;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSummary;
use App\Models\BiometricDevice;
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
            'on_leave_today' => AttendanceSummary::whereDate('summary_date', now())
                ->where('status', 'on_leave')->count(),
            'workforce_size' => Employee::where('status', 'active')->count(),
        ];

        // Daily presence trend for the month — drives the analytical LiveBars chart.
        // Single GROUP-BY query per status, then merge so the days line up.
        $dailyAgg = AttendanceSummary::between($start, $end)
            ->selectRaw('summary_date, status, COUNT(*) as c')
            ->groupBy('summary_date', 'status')
            ->get()
            ->groupBy(fn ($row) => substr((string) $row->summary_date, 0, 10));

        $dailyTrend = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $rows = $dailyAgg[$key] ?? collect();
            $dailyTrend[] = [
                'date'    => $key,
                'label'   => $cursor->format('j'),
                'present' => (int) ($rows->firstWhere('status', 'present')->c ?? 0),
                'late'    => (int) ($rows->firstWhere('status', 'late')->c    ?? 0),
                'absent'  => (int) ($rows->firstWhere('status', 'absent')->c  ?? 0),
                'on_leave'=> (int) ($rows->firstWhere('status', 'on_leave')->c?? 0),
                'is_weekend' => $cursor->isWeekend(),
            ];
            $cursor = $cursor->addDay();
        }

        // Status distribution for the month — feeds the composition donut.
        $statusDistribution = AttendanceSummary::between($start, $end)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return Inertia::render('Attendance/Index', [
            'summaries'          => AttendanceSummaryResource::collection($summaries),
            'stats'              => $stats,
            'dailyTrend'         => $dailyTrend,
            'statusDistribution' => $statusDistribution,
            'month'              => $month,
            'filters'            => $request->only(['department_id']),
            'activeModule'       => 'attendance',
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
        $validated = $request->validated();
        $source = AttendanceSource::WebKiosk;

        $nearestDeviceId = null;
        $lat = $validated['geo_lat'] ?? null;
        $lng = $validated['geo_lng'] ?? null;
        if ($lat !== null && $lng !== null) {
            $candidate = BiometricDevice::query()
                ->where('is_active', true)
                ->whereNotNull('geo_lat')
                ->whereNotNull('geo_lng')
                ->whereNotNull('geo_radius_m')
                ->whereBetween('geo_lat', [$lat - 0.5, $lat + 0.5])
                ->whereBetween('geo_lng', [$lng - 0.5, $lng + 0.5])
                ->first();
            $nearestDeviceId = $candidate?->id;
        }

        try {
            $this->service->record(
                employee:   $employee,
                eventAt:    now(),
                direction:  (string) $validated['direction'],
                source:     $source,
                deviceId:   $nearestDeviceId,
                geoLat:     $lat,
                geoLng:     $lng,
                recordedBy: $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Clocked {$validated['direction']} successfully");
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

    public function correctionsIndex(): \Inertia\Response
    {
        abort_unless(request()->user()?->hasPermission('attendance.approve'), 403);

        return \Inertia\Inertia::render('Attendance/Corrections', [
            'corrections' => AttendanceCorrectionResource::collection(
                AttendanceCorrection::with(['employee:id,employee_no,position', 'requester:id,name', 'reviewer:id,name'])
                    ->latest()
                    ->paginate(20)
            ),
        ]);
    }

    public function storeCorrection(StoreCorrectionRequest $request)
    {
        $employee = $request->user()->employee
            ?? throw new \LogicException('Authenticated user has no employee record.');

        $this->service->requestCorrection(
            $employee,
            $request->user(),
            $request->validated('requested_event_at'),
            $request->validated('requested_direction'),
            $request->validated('reason'),
            $request->validated('attendance_record_id'),
        );

        return back()->with('success', 'Correction request submitted.');
    }

    public function reviewCorrection(ReviewCorrectionRequest $request, AttendanceCorrection $correction)
    {
        if ($request->validated('decision') === 'approve') {
            $this->service->approveCorrection($correction, $request->user(), $request->validated('decision_notes'));
            return back()->with('success', 'Correction approved and applied.');
        }

        $this->service->rejectCorrection($correction, $request->user(), $request->validated('decision_notes') ?? 'Rejected');
        return back()->with('success', 'Correction rejected.');
    }

    private function authorizeShiftManage(): bool
    {
        return request()->user()?->hasPermission('attendance.shift_manage') ?? false;
    }
}
