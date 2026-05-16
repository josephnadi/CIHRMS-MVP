<?php

namespace App\Http\Controllers;

use App\Enums\PipStatus;
use App\Http\Requests\Performance\ClosePipRequest;
use App\Http\Requests\Performance\OpenPipRequest;
use App\Http\Resources\PipResource;
use App\Models\Employee;
use App\Models\PerformanceImprovementPlan;
use App\Models\Review;
use App\Services\Performance\PipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PipController extends Controller
{
    public function __construct(private readonly PipService $pips) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PerformanceImprovementPlan::class);

        $pips = PerformanceImprovementPlan::query()
            ->with(['employee.user', 'employee.department', 'mentor.user'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest('opened_on')
            ->paginate(20);

        $stats = [
            'open_total'      => PerformanceImprovementPlan::open()->count(),
            'succeeded_ytd'   => PerformanceImprovementPlan::where('status', 'succeeded')
                ->whereYear('actual_end_date', now()->year)->count(),
            'terminated_ytd'  => PerformanceImprovementPlan::where('status', 'failed_terminated')
                ->whereYear('actual_end_date', now()->year)->count(),
        ];

        return Inertia::render('Performance/Pips/Index', [
            'pips'         => PipResource::collection($pips),
            'stats'        => $stats,
            'filters'      => $request->only(['status']),
            'activeModule' => 'performance-pips',
        ]);
    }

    public function show(PerformanceImprovementPlan $pip): Response
    {
        $this->authorize('view', $pip);

        $pip->load(['employee.user', 'employee.department', 'mentor.user', 'opener', 'triggerReview']);

        return Inertia::render('Performance/Pips/Show', [
            'pip'          => new PipResource($pip),
            'activeModule' => 'performance-pips',
        ]);
    }

    public function store(OpenPipRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $mentor   = $request->validated('mentor_id')
            ? Employee::find($request->validated('mentor_id'))
            : null;
        $review = $request->validated('triggered_by_review_id')
            ? Review::find($request->validated('triggered_by_review_id'))
            : null;

        try {
            $pip = $this->pips->open(
                employee:       $employee,
                triggerReview:  $review,
                mentor:         $mentor,
                targetMetrics:  $request->validated('target_metrics'),
                opener:         $request->user(),
                durationDays:   $request->validated('duration_days'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('performance.pips.show', $pip)
            ->with('success', "PIP opened for {$employee->user?->name}.");
    }

    public function checkin(Request $request, PerformanceImprovementPlan $pip): RedirectResponse
    {
        $this->authorize('manage', PerformanceImprovementPlan::class);

        $data = $request->validate([
            'note'       => ['required', 'string', 'min:5', 'max:2000'],
            'met_target' => ['required', 'boolean'],
        ]);

        try {
            $this->pips->addCheckin($pip, $request->user(), $data['note'], (bool) $data['met_target']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Check-in recorded.');
    }

    public function extend(Request $request, PerformanceImprovementPlan $pip): RedirectResponse
    {
        $this->authorize('manage', PerformanceImprovementPlan::class);

        $data = $request->validate([
            'additional_days' => ['required', 'integer', 'min:14', 'max:90'],
            'reason'          => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            $this->pips->extend($pip, (int) $data['additional_days'], $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'PIP extended.');
    }

    public function close(ClosePipRequest $request, PerformanceImprovementPlan $pip): RedirectResponse
    {
        $this->authorize('manage', PerformanceImprovementPlan::class);

        try {
            $this->pips->close(
                pip:     $pip,
                outcome: PipStatus::from($request->validated('outcome')),
                actor:   $request->user(),
                summary: (string) $request->validated('summary'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "PIP closed: {$pip->fresh()->status?->label()}.");
    }
}
