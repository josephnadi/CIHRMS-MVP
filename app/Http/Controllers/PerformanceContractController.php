<?php

namespace App\Http\Controllers;

use App\Http\Requests\Performance\EvaluateContractRequest;
use App\Http\Requests\Performance\StoreContractRequest;
use App\Http\Resources\PerformanceContractResource;
use App\Models\Employee;
use App\Models\PerformanceContract;
use App\Models\ReviewCycle;
use App\Services\Performance\PerformanceContractService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceContractController extends Controller
{
    public function __construct(private readonly PerformanceContractService $contracts) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PerformanceContract::class);

        $contracts = PerformanceContract::query()
            ->with(['cycle', 'employee.user', 'employee.department', 'supervisor.user'])
            ->when($request->cycle_id, fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Performance/Contracts/Index', [
            'contracts'    => PerformanceContractResource::collection($contracts),
            'cycles'       => ReviewCycle::orderByDesc('starts_at')->get(['id', 'name', 'status']),
            'filters'      => $request->only(['cycle_id', 'status']),
            'activeModule' => 'performance-contracts',
        ]);
    }

    public function show(PerformanceContract $contract): Response
    {
        $this->authorize('view', $contract);

        $contract->load(['cycle', 'employee.user', 'employee.department', 'supervisor.user', 'drafter']);

        return Inertia::render('Performance/Contracts/Show', [
            'contract'     => new PerformanceContractResource($contract),
            'activeModule' => 'performance-contracts',
        ]);
    }

    public function store(StoreContractRequest $request): RedirectResponse
    {
        $cycle      = ReviewCycle::findOrFail($request->validated('cycle_id'));
        $employee   = Employee::findOrFail($request->validated('employee_id'));
        $supervisor = $request->validated('supervisor_id')
            ? Employee::find($request->validated('supervisor_id'))
            : null;

        try {
            $contract = $this->contracts->draft(
                cycle:      $cycle,
                employee:   $employee,
                supervisor: $supervisor,
                kpis:       $request->validated('kpis'),
                actor:      $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('performance.contracts.show', $contract)
            ->with('success', "Performance contract drafted for {$employee->user?->name}.");
    }

    public function send(Request $request, PerformanceContract $contract): RedirectResponse
    {
        $this->authorize('draft', PerformanceContract::class);

        try {
            $this->contracts->sendForSignature($contract);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Contract sent for signature.');
    }

    public function sign(Request $request, PerformanceContract $contract): RedirectResponse
    {
        $this->authorize('sign', $contract);

        try {
            $this->contracts->sign($contract, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Signed.');
    }

    public function evaluate(EvaluateContractRequest $request, PerformanceContract $contract): RedirectResponse
    {
        $this->authorize('evaluate', $contract);

        try {
            $this->contracts->evaluate($contract, $request->validated('actuals'), $request->user());
            if ($request->validated('end_year_note')) {
                $contract->update(['end_year_note' => $request->validated('end_year_note')]);
            }
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Evaluation recorded. Status: {$contract->fresh()->status?->label()}.");
    }
}
