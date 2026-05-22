<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Http\Requests\Attendance\KioskClockRequest;
use App\Http\Requests\Attendance\KioskVerifyRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public attendance kiosk.
 *
 * Runs on a shared/dedicated device with no per-user auth. Employees identify
 * themselves with their Employee ID + name (loose match against their User
 * record). Future: face recognition replaces the name step via clockByFace().
 *
 * All events are recorded through AttendanceService with source=web_kiosk so
 * daily summaries, lateness, and overtime are computed identically to any
 * other source.
 */
class KioskController extends Controller
{
    public function __construct(private readonly AttendanceService $service) {}

    public function show(): Response
    {
        return Inertia::render('Kiosk/Index', [
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function verify(KioskVerifyRequest $request): JsonResponse
    {
        $employee = $this->matchEmployee(
            (string) $request->validated('employee_no'),
            (string) $request->validated('name'),
        );

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Employee ID or name did not match. Please try again.',
            ], 422);
        }

        return response()->json([
            'ok'       => true,
            'employee' => $this->presentEmployee($employee),
        ]);
    }

    public function clock(KioskClockRequest $request): JsonResponse
    {
        $employee = $this->matchEmployee(
            (string) $request->validated('employee_no'),
            (string) $request->validated('name'),
        );

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Employee ID or name did not match. Please try again.',
            ], 422);
        }

        try {
            $record = $this->service->record(
                employee:  $employee,
                eventAt:   now(),
                direction: (string) $request->validated('direction'),
                source:    AttendanceSource::WebKiosk,
            );
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'       => true,
            'employee' => $this->presentEmployee($employee),
            'record'   => [
                'direction' => $record->direction,
                'event_at'  => CarbonImmutable::instance($record->event_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Stub for the future face-recognition flow. The route exists today so the
     * frontend and clock-in hardware can wire against it without a redeploy;
     * implementation lands when the chosen face SDK is integrated.
     */
    public function clockByFace(Request $request): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => 'Face recognition is not yet available on this kiosk.',
        ], 501);
    }

    /**
     * Today's social-proof wall — last 8 kiosk punches today.
     *
     * Polled every ~15s by the kiosk page. Payload is deliberately minimal
     * (first name + direction + event_at) so anyone within sight of the device
     * can see it without leaking Staff IDs, full names, or positions.
     */
    public function recent(): JsonResponse
    {
        $rows = AttendanceRecord::query()
            ->where('source', AttendanceSource::WebKiosk->value)
            ->whereDate('event_at', now()->toDateString())
            ->latest('event_at')
            ->limit(8)
            ->with('employee.user:id,name')
            ->get();

        $payload = $rows->map(function (AttendanceRecord $r) {
            $fullName  = $r->employee?->user?->name ?? '—';
            $firstName = trim(strtok($fullName, ' ')) ?: '—';

            return [
                'first_name' => $firstName,
                'direction'  => $r->direction,
                'event_at'   => CarbonImmutable::instance($r->event_at)->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'recent'     => $payload,
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    private function matchEmployee(string $employeeNo, string $name): ?Employee
    {
        $employee = Employee::query()
            ->with('user:id,name')
            ->where('employee_no', trim($employeeNo))
            ->first();

        if (! $employee || ! $employee->user) {
            return null;
        }

        $typed = $this->normalize($name);
        $full  = $this->normalize($employee->user->name);

        // Loose match: typed string must appear inside the user's full name
        // (case/accent-insensitive). Two-char minimum to avoid trivial matches.
        if (strlen($typed) < 2) {
            return null;
        }

        return str_contains($full, $typed) ? $employee : null;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function presentEmployee(Employee $employee): array
    {
        $today    = now()->toDateString();
        $lastToday = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('event_at', $today)
            ->latest('event_at')
            ->first();

        return [
            'employee_no' => $employee->employee_no,
            'name'        => $employee->user?->name,
            'position'    => $employee->position,
            'avatar_url'  => $employee->avatar_url,
            'last_event'  => $lastToday ? [
                'direction' => $lastToday->direction,
                'event_at'  => CarbonImmutable::instance($lastToday->event_at)->toIso8601String(),
            ] : null,
            'suggested_direction' => $lastToday && $lastToday->direction === 'in' ? 'out' : 'in',
        ];
    }
}
