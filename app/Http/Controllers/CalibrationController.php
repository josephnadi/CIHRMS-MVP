<?php

namespace App\Http\Controllers;

use App\Http\Requests\Performance\AdjustRatingRequest;
use App\Http\Requests\Performance\StoreCalibrationRequest;
use App\Http\Resources\CalibrationSessionResource;
use App\Models\CalibrationSession;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Services\Performance\CalibrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalibrationController extends Controller
{
    public function __construct(private readonly CalibrationService $calibration) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CalibrationSession::class);

        $sessions = CalibrationSession::query()
            ->with(['cycle', 'department', 'facilitator'])
            ->latest()
            ->paginate(20);

        return Inertia::render('Performance/Calibration/Index', [
            'sessions'     => CalibrationSessionResource::collection($sessions),
            'cycles'       => ReviewCycle::orderByDesc('starts_at')->get(['id', 'name']),
            'activeModule' => 'performance-calibration',
        ]);
    }

    public function show(CalibrationSession $session): Response
    {
        $this->authorize('view', $session);

        $session->load(['cycle', 'department', 'facilitator', 'applier', 'adjustments.review.employee.user']);

        // Reviews in scope: same cycle, same department (if scoped), all manager-type reviews with a rating.
        $reviews = Review::query()
            ->where('cycle_id', $session->cycle_id)
            ->where('type', 'manager')
            ->whereNotNull('overall_rating')
            ->with(['employee.user', 'employee.department'])
            ->when($session->department_id, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->get();

        return Inertia::render('Performance/Calibration/Show', [
            'session'      => new CalibrationSessionResource($session),
            'reviews'      => $reviews->map(fn ($r) => [
                'id'             => $r->id,
                'employee'       => [
                    'id'   => $r->employee?->id,
                    'name' => $r->employee?->user?->name,
                    'no'   => $r->employee?->employee_no,
                ],
                'overall_rating' => (float) $r->overall_rating,
            ]),
            'actual_distribution' => $this->calibration->actualDistribution($session),
            'activeModule' => 'performance-calibration',
        ]);
    }

    public function store(StoreCalibrationRequest $request): RedirectResponse
    {
        $this->authorize('facilitate', CalibrationSession::class);

        $cycle = ReviewCycle::findOrFail($request->validated('cycle_id'));

        $session = $this->calibration->open(
            cycle:               $cycle,
            departmentId:        $request->validated('department_id'),
            facilitator:         $request->user(),
            targetDistribution:  $request->validated('target_distribution'),
        );

        return redirect()->route('performance.calibration.show', $session)
            ->with('success', 'Calibration session opened.');
    }

    public function adjust(AdjustRatingRequest $request, CalibrationSession $session): RedirectResponse
    {
        $this->authorize('facilitate', CalibrationSession::class);

        $review = Review::findOrFail($request->validated('review_id'));

        try {
            $this->calibration->recordAdjustment(
                session:          $session,
                review:           $review,
                adjustedRating:   (float) $request->validated('adjusted_rating'),
                reason:           $request->validated('reason'),
                adjuster:         $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Adjustment recorded.');
    }

    public function lock(Request $request, CalibrationSession $session): RedirectResponse
    {
        $this->authorize('facilitate', CalibrationSession::class);

        try {
            $this->calibration->lock($session, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Session locked. A separate user with apply rights can now finalise.');
    }

    public function apply(Request $request, CalibrationSession $session): RedirectResponse
    {
        $this->authorize('apply', $session);

        try {
            $this->calibration->apply($session, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Adjustments applied to all reviews in the session.');
    }
}
