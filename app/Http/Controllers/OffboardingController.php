<?php

namespace App\Http\Controllers;

use App\Http\Requests\Offboarding\CalculateSettlementRequest;
use App\Http\Requests\Offboarding\ClearItemRequest;
use App\Http\Requests\Offboarding\InitiateOffboardingRequest;
use App\Http\Resources\ClearanceItemResource;
use App\Http\Resources\FinalSettlementResource;
use App\Http\Resources\OffboardingCaseResource;
use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\OffboardingCase;
use App\Services\Offboarding\OffboardingService;
use App\Enums\ExitType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OffboardingController extends Controller
{
    public function __construct(private readonly OffboardingService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OffboardingCase::class);

        $cases = OffboardingCase::query()
            ->with(['employee.user', 'employee.department', 'settlement', 'initiator'])
            ->when($request->status,    fn ($q, $v) => $q->where('status', $v))
            ->when($request->exit_type, fn ($q, $v) => $q->where('exit_type', $v))
            ->when($request->q, function ($q, $v) {
                $q->where(fn ($qq) => $qq->where('reference', 'like', "%{$v}%")
                    ->orWhereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$v}%")));
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'in_progress'      => OffboardingCase::where('status', 'in_progress')->count(),
            'awaiting_settle'  => OffboardingCase::where('status', 'awaiting_settlement')->count(),
            'completed_ytd'    => OffboardingCase::whereYear('completed_at', now()->year)->count(),
            'settlement_total' => (float) FinalSettlement::where('status', 'approved')
                ->whereYear('approved_at', now()->year)
                ->sum('net_payable'),
        ];

        return Inertia::render('Offboarding/Index', [
            'cases'        => OffboardingCaseResource::collection($cases),
            'stats'        => $stats,
            'filters'      => $request->only(['status', 'exit_type', 'q']),
            'activeModule' => 'offboarding',
        ]);
    }

    public function show(OffboardingCase $case): Response
    {
        $this->authorize('view', $case);

        $case->load([
            'employee.user', 'employee.department', 'initiator', 'completer',
            'clearanceItems.department', 'clearanceItems.responsibleUser', 'clearanceItems.clearer',
            'settlement.approver',
        ]);

        $clearance = $case->clearanceItems
            ->groupBy(fn ($i) => $i->area?->value)
            ->map(fn ($items) => ClearanceItemResource::collection($items->values()));

        return Inertia::render('Offboarding/Show', [
            'case'         => new OffboardingCaseResource($case),
            'clearance'    => $clearance,
            'settlement'   => $case->settlement ? new FinalSettlementResource($case->settlement) : null,
            'activeModule' => 'offboarding',
        ]);
    }

    public function store(InitiateOffboardingRequest $request): RedirectResponse
    {
        $this->authorize('initiate', OffboardingCase::class);

        $employee = Employee::findOrFail($request->validated('employee_id'));

        try {
            $case = $this->service->initiate(
                employee:          $employee,
                exitType:          ExitType::from($request->validated('exit_type')),
                noticeReceivedOn:  (string) $request->validated('notice_received_on'),
                lastWorkingDay:    (string) $request->validated('last_working_day'),
                initiator:         $request->user(),
                reason:            $request->validated('reason'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('offboarding.show', $case)
            ->with('success', "Off-boarding case {$case->reference} initiated.");
    }

    public function clearItem(ClearItemRequest $request, OffboardingCase $case, ClearanceItem $item): RedirectResponse
    {
        $this->authorize('clear', $case);
        abort_unless($item->offboarding_case_id === $case->id, 404);

        try {
            if ($request->validated('action') === 'clear') {
                $this->service->clearItem($item, $request->user(), $request->validated('notes'));
                $msg = "Item '{$item->label}' marked cleared.";
            } else {
                $this->service->waiveItem($item, $request->user(), (string) $request->validated('notes'));
                $msg = "Item '{$item->label}' waived.";
            }
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $msg);
    }

    public function calculateSettlement(CalculateSettlementRequest $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('calculateSettlement', $case);

        try {
            $this->service->calculateSettlement(
                case:      $case,
                actor:     $request->user(),
                overrides: array_filter($request->validated(), fn ($v) => $v !== null && $v !== ''),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Final settlement calculated for {$case->reference}.");
    }

    public function approveSettlement(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('approveSettlement', $case);

        $settlement = $case->settlement;
        if (! $settlement) {
            return back()->with('error', 'No calculated settlement exists for this case.');
        }

        try {
            $this->service->approveSettlement($settlement, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Settlement for {$case->reference} approved.");
    }

    public function paySettlement(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('paySettlement', $case);

        $settlement = $case->settlement;
        if (! $settlement) {
            return back()->with('error', 'No settlement exists for this case.');
        }

        try {
            $this->service->paySettlement($settlement, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Settlement for {$case->reference} marked paid.");
    }

    public function reverseSettlement(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('reverseSettlement', $case);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $settlement = $case->settlement;
        if (! $settlement) {
            return back()->with('error', 'No settlement exists for this case.');
        }

        try {
            $this->service->reverseSettlement($settlement, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Settlement for {$case->reference} reversed.");
    }

    public function complete(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('complete', $case);

        try {
            $this->service->complete($case, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Case {$case->reference} completed; employee status set to Terminated.");
    }

    public function cancel(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('complete', $case);
        $reason = $request->validate(['reason' => 'required|string|max:1000'])['reason'];

        $this->service->cancel($case, $request->user(), $reason);

        return back()->with('success', "Case {$case->reference} cancelled.");
    }
}
