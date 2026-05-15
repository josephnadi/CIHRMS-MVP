<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\ApprovePayrollRunRequest;
use App\Http\Requests\Payroll\ReversePayrollRunRequest;
use App\Http\Requests\Payroll\StorePayrollRunRequest;
use App\Http\Resources\PayrollLineResource;
use App\Http\Resources\PayrollRunResource;
use App\Http\Resources\StatutoryReturnResource;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()
            ->with(['department', 'creator', 'approver'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->year,   fn ($q, $v) => $q->where('period_year', $v))
            ->latest('period_year')
            ->latest('period_month')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Payroll/Runs/Index', [
            'runs'         => PayrollRunResource::collection($runs),
            'filters'      => $request->only(['status', 'year']),
            'activeModule' => 'payroll',
        ]);
    }

    public function show(PayrollRun $run): Response
    {
        $this->authorize('view', $run);

        $run->load(['department', 'creator', 'approver', 'reverser', 'returns.trustee']);

        $lines = $run->lines()
            ->with(['employee.user', 'employee.department', 'grade'])
            ->orderBy('id')
            ->paginate(50);

        return Inertia::render('Payroll/Runs/Show', [
            'run'          => new PayrollRunResource($run),
            'lines'        => PayrollLineResource::collection($lines),
            'returns'      => StatutoryReturnResource::collection($run->returns),
            'activeModule' => 'payroll',
        ]);
    }

    public function store(StorePayrollRunRequest $request): RedirectResponse
    {
        $run = $this->payroll->createDraft(
            year:         (int) $request->validated('period_year'),
            month:        (int) $request->validated('period_month'),
            departmentId: $request->validated('department_id'),
            creator:      $request->user(),
            reason:       $request->validated('reason'),
        );

        return redirect()->route('payroll-runs.show', $run)->with('success', 'Payroll run draft created.');
    }

    public function calculate(PayrollRun $run): RedirectResponse
    {
        $this->authorize('view', $run);
        $this->payroll->calculate($run);
        return back()->with('success', 'Payroll calculated.');
    }

    public function approve(ApprovePayrollRunRequest $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('approve', $run);
        $this->payroll->approve($run, $request->user());
        return back()->with('success', 'Payroll run approved. Statutory returns are generating.');
    }

    public function reverse(ReversePayrollRunRequest $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('reverse', $run);
        $this->payroll->reverse($run, $request->user(), (string) $request->validated('reason'));
        return back()->with('success', 'Payroll run reversed.');
    }

    public function markPaid(PayrollRun $run): RedirectResponse
    {
        $this->authorize('approve', $run); // same gate as approve
        $this->payroll->markPaid($run);
        return back()->with('success', 'Payroll run marked as paid.');
    }

    public function downloadReturn(PayrollRun $run, int $returnId): StreamedResponse
    {
        $this->authorize('view', $run);

        $return = $run->returns()->findOrFail($returnId);

        abort_unless(Storage::disk('local')->exists($return->file_path), 404);

        return Storage::disk('local')->download($return->file_path);
    }
}
