<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OnboardingCaseResource;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $service)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OnboardingCase::class);

        $cases = OnboardingCase::query()
            ->with(['employee.user', 'initiator'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Onboarding/Index', [
            'cases'        => OnboardingCaseResource::collection($cases),
            'filters'      => $request->only(['status']),
            'activeModule' => 'onboarding',
        ]);
    }

    public function show(OnboardingCase $case): Response
    {
        $this->authorize('view', $case);
        $case->load(['employee.user', 'initiator', 'completer', 'tasks.completer']);

        return Inertia::render('Onboarding/Show', [
            'case'         => (new OnboardingCaseResource($case))->resolve(request()),
            'activeModule' => 'onboarding',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('initiate', OnboardingCase::class);
        $data = $request->validate([
            'employee_id'            => ['required', 'integer', 'exists:employees,id'],
            'target_completion_date' => ['nullable', 'date'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $case = $this->service->initiate($employee, $request->user(), null, $data['target_completion_date'] ?? null);

        return redirect()->route('onboarding.show', $case->id)->with('success', "Onboarding opened for {$case->reference}.");
    }

    public function updateTask(Request $request, OnboardingCase $case, OnboardingTask $task): RedirectResponse
    {
        $this->authorize('complete', $case);
        $data = $request->validate([
            'action' => ['required', 'in:complete,skip'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            if ($data['action'] === 'skip') {
                $this->service->skipTask($task, $request->user(), $data['reason'] ?? 'Skipped');
            } else {
                $this->service->completeTask($task, $request->user(), $data['reason'] ?? null);
            }
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Task updated.');
    }

    public function complete(Request $request, OnboardingCase $case): RedirectResponse
    {
        $this->authorize('complete', $case);
        try {
            $this->service->complete($case, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Onboarding {$case->reference} completed.");
    }

    public function cancel(Request $request, OnboardingCase $case): RedirectResponse
    {
        $this->authorize('manage', $case);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($case, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Onboarding {$case->reference} cancelled.");
    }
}
