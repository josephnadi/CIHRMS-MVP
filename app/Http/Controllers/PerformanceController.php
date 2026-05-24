<?php

namespace App\Http\Controllers;

use App\Http\Requests\Performance\StoreCheckinRequest;
use App\Http\Requests\Performance\StoreCycleRequest;
use App\Http\Requests\Performance\StoreGoalRequest;
use App\Http\Requests\Performance\StoreReviewRequest;
use App\Http\Requests\Performance\UpdateGoalRequest;
use App\Http\Resources\GoalResource;
use App\Http\Resources\ReviewCycleResource;
use App\Http\Resources\ReviewResource;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Services\PerformanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function index(): Response
    {
        return Inertia::render('Performance/Index', [
            'analytics'    => $this->performance->snapshot(),
            'activeModule' => 'performance',
        ]);
    }

    // ── Goals ────────────────────────────────────────────────────────────

    public function goals(Request $request): Response
    {
        return Inertia::render('Performance/Goals', [
            'goals'        => GoalResource::collection($this->performance->listGoals($request)),
            'cycles'       => ReviewCycleResource::collection($this->performance->listCycles()),
            'employees'    => Employee::with('user:id,name')
                ->whereHas('user')
                ->get(['id', 'user_id', 'employee_no'])
                ->map(fn ($e) => [
                    'id'    => $e->id,
                    'label' => ($e->user?->name ?? 'Employee').' · '.$e->employee_no,
                ])
                ->values(),
            'filters'      => $request->only(['employee_id', 'cycle_id', 'status', 'search']),
            'activeModule' => 'performance',
        ]);
    }

    public function storeGoal(StoreGoalRequest $request): RedirectResponse
    {
        $this->performance->createGoal($request->validated(), $request->user()->id);
        return back()->with('success', 'Goal created.');
    }

    public function updateGoal(UpdateGoalRequest $request, Goal $goal): RedirectResponse
    {
        // Ownership gate (audit-v2 tier-3 supplement, item 27):
        // route is gated by `performance.view` for self-service so without this
        // check any viewer could PATCH any other employee's goal. Managers
        // (performance.manage) may update any goal; everyone else only their own.
        abort_unless(
            $request->user()->hasPermission('performance.manage')
                || $goal->employee?->user_id === $request->user()->id,
            403,
        );

        $this->performance->updateGoal($goal, $request->validated());
        return back()->with('success', 'Goal updated.');
    }

    public function destroyGoal(Goal $goal, Request $request): RedirectResponse
    {
        abort_unless(
            $request->user()->hasPermission('performance.manage')
                || $request->user()->employee?->id === $goal->employee_id,
            403,
        );
        $goal->delete();
        return back()->with('success', 'Goal removed.');
    }

    public function storeCheckin(StoreCheckinRequest $request, Goal $goal): RedirectResponse
    {
        // Ownership gate (audit-v2 tier-3 supplement, item 27):
        // route is gated by `performance.view` for self-service so without this
        // check any viewer could post a check-in on any other employee's goal.
        // Managers (performance.manage) may check in on any goal; everyone else
        // only on their own.
        abort_unless(
            $request->user()->hasPermission('performance.manage')
                || $goal->employee?->user_id === $request->user()->id,
            403,
        );

        $this->performance->recordCheckin($goal, $request->validated(), $request->user()->id);
        return back()->with('success', 'Check-in recorded.');
    }

    // ── Reviews ──────────────────────────────────────────────────────────

    public function reviews(Request $request): Response
    {
        return Inertia::render('Performance/Reviews', [
            'reviews'      => ReviewResource::collection($this->performance->listReviews($request)),
            'cycles'       => ReviewCycleResource::collection($this->performance->listCycles()),
            'activeCycle'  => $this->performance->activeCycle()?->only(['id', 'name']),
            'filters'      => $request->only(['cycle_id', 'employee_id', 'reviewer_id', 'type', 'status']),
            'activeModule' => 'performance',
        ]);
    }

    public function storeReview(StoreReviewRequest $request): RedirectResponse
    {
        $this->performance->createReview($request->validated());
        return back()->with('success', 'Review draft saved.');
    }

    public function submitReview(Review $review, Request $request): RedirectResponse
    {
        abort_unless($review->reviewer_id === $request->user()->id || $request->user()->hasPermission('performance.manage'), 403);
        $this->performance->submitReview($review);
        return back()->with('success', 'Review submitted.');
    }

    public function acknowledgeReview(Review $review, Request $request): RedirectResponse
    {
        abort_unless($review->employee?->user_id === $request->user()->id, 403);
        $this->performance->acknowledgeReview($review);
        return back()->with('success', 'Review acknowledged.');
    }

    // ── Cycles ───────────────────────────────────────────────────────────

    public function storeCycle(StoreCycleRequest $request): RedirectResponse
    {
        $this->performance->createCycle($request->validated(), $request->user()->id);
        return back()->with('success', 'Review cycle created.');
    }

    public function closeCycle(ReviewCycle $cycle, Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('performance.manage'), 403);
        $this->performance->closeCycle($cycle);
        return back()->with('success', 'Cycle closed.');
    }

    // ── 9-Box matrix ─────────────────────────────────────────────────────

    public function nineBox(Request $request): Response
    {
        return Inertia::render('Performance/NineBox', [
            'matrix'       => $this->performance->nineBoxMatrix($request->integer('cycle_id') ?: null),
            'cycles'       => ReviewCycleResource::collection($this->performance->listCycles()),
            'activeModule' => 'performance',
        ]);
    }
}
