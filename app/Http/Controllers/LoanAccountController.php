<?php

namespace App\Http\Controllers;

use App\Http\Requests\Loans\ApplyLoanRequest;
use App\Http\Requests\Loans\ApproveLoanRequest;
use App\Http\Requests\Loans\DisburseLoanRequest;
use App\Http\Resources\LoanAccountResource;
use App\Http\Resources\LoanProductResource;
use App\Http\Resources\LoanRepaymentResource;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Services\Loans\AmortizationCalculator;
use App\Services\Loans\LoanService;
use App\Support\DbExpr;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoanAccountController extends Controller
{
    public function __construct(
        private readonly LoanService $loans,
        private readonly AmortizationCalculator $calc,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LoanAccount::class);

        $loans = LoanAccount::query()
            ->with(['employee.user', 'product'])
            ->when($request->status,     fn ($q, $v) => $q->where('status', $v))
            ->when($request->product_id, fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->q, function ($q, $v) {
                $q->where(fn ($qq) => $qq->where('reference', 'like', "%{$v}%")
                    ->orWhereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$v}%")));
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'active_count'        => LoanAccount::activeForRepayment()->count(),
            'total_outstanding'   => (float) LoanAccount::activeForRepayment()->sum('outstanding_balance'),
            'pending_approval'    => LoanAccount::where('status', 'pending_approval')->count(),
            'disbursed_this_year' => (float) LoanAccount::whereYear('disbursed_at', now()->year)
                ->sum('disbursed_amount'),
        ];

        // Status distribution for the composition donut (all-time snapshot)
        $statusBreakdown = LoanAccount::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        // Monthly disbursement trend (last 12 months) for the LiveBars chart
        $start = now()->subMonths(11)->startOfMonth();
        $aggRows = LoanAccount::query()
            ->whereNotNull('disbursed_at')
            ->where('disbursed_at', '>=', $start)
            ->selectRaw(DbExpr::yearMonth('disbursed_at') . ' as ym, SUM(disbursed_amount) as total, COUNT(*) as cnt')
            ->groupBy('ym')
            ->pluck('total', 'ym')
            ->all();

        $monthlyDisbursements = [];
        $cursor = $start->copy();
        for ($i = 0; $i < 12; $i++) {
            $ym = $cursor->format('Y-m');
            $monthlyDisbursements[] = [
                'label' => $cursor->format('M'),
                'ym'    => $ym,
                'value' => round((float) ($aggRows[$ym] ?? 0), 2),
            ];
            $cursor = $cursor->copy()->addMonth();
        }

        return Inertia::render('Loans/Index', [
            'loans'                => LoanAccountResource::collection($loans),
            'products'             => LoanProductResource::collection(LoanProduct::active()->orderBy('name')->get()),
            'stats'                => $stats,
            'statusBreakdown'      => $statusBreakdown,
            'monthlyDisbursements' => $monthlyDisbursements,
            'filters'              => $request->only(['status', 'product_id', 'q']),
            'activeModule'         => 'loans',
        ]);
    }

    public function show(LoanAccount $loan): Response
    {
        $this->authorize('view', $loan);

        $loan->load(['employee.user', 'product', 'applicant', 'approver', 'disburser']);
        $repayments = $loan->repayments()->orderBy('installment_no')->get();

        return Inertia::render('Loans/Show', [
            'loan'         => new LoanAccountResource($loan),
            'repayments'   => LoanRepaymentResource::collection($repayments),
            'activeModule' => 'loans',
        ]);
    }

    public function store(ApplyLoanRequest $request): RedirectResponse
    {
        $user = $request->user();
        // HR may apply on behalf; default to the actor's own employee record.
        $employee = $request->validated('employee_id')
            ? Employee::findOrFail($request->validated('employee_id'))
            : $user->employee;

        if (! $employee) {
            return back()->with('error', 'No employee record linked to this user.');
        }

        $product = LoanProduct::findOrFail($request->validated('product_id'));

        try {
            $loan = $this->loans->apply(
                employee:   $employee,
                product:    $product,
                principal:  (float) $request->validated('principal'),
                termMonths: (int)   $request->validated('term_months'),
                purpose:    $request->validated('purpose'),
                applicant:  $user,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('loans.show', $loan)->with('success', "Loan {$loan->reference} submitted for approval.");
    }

    public function decide(ApproveLoanRequest $request, LoanAccount $loan): RedirectResponse
    {
        $this->authorize('approve', $loan);

        try {
            if ($request->validated('decision') === 'approve') {
                $this->loans->approve($loan, $request->user());
                return back()->with('success', "Loan {$loan->reference} approved.");
            }

            $this->loans->reject($loan, $request->user(), (string) $request->validated('reason'));
            return back()->with('success', "Loan {$loan->reference} rejected.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function disburse(DisburseLoanRequest $request, LoanAccount $loan): RedirectResponse
    {
        $this->authorize('disburse', $loan);

        $firstPeriod = $request->validated('first_repayment_period')
            ? CarbonImmutable::parse($request->validated('first_repayment_period'))->startOfMonth()
            : null;

        try {
            $this->loans->disburse($loan, $request->user(), $firstPeriod);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Loan {$loan->reference} disbursed. Repayment schedule generated.");
    }

    /**
     * AJAX preview — used by the Apply UI to show the amortization schedule
     * before the employee submits.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id'  => ['required', 'integer', 'exists:loan_products,id'],
            'principal'   => ['required', 'numeric', 'min:1'],
            'term_months' => ['required', 'integer', 'min:1', 'max:360'],
        ]);

        $product = LoanProduct::findOrFail($data['product_id']);

        $bundle = $this->calc->calculate(
            principal:  (float) $data['principal'],
            termMonths: (int)   $data['term_months'],
            annualRate: (float) $product->annual_interest_rate,
            method:     $product->amortization_method,
        );

        return response()->json($bundle);
    }

    // ── Loan product catalogue (managed inline with the loans page) ─────
    // Only finance / loan-product-managers can curate the catalogue; per-route
    // middleware in routes/web.php enforces `loans.product_manage`.

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $this->validateProduct($request);
        LoanProduct::create($data);
        return back()->with('success', 'Loan product created.');
    }

    public function updateProduct(Request $request, LoanProduct $product): RedirectResponse
    {
        $data = $this->validateProduct($request);
        $product->update($data);
        return back()->with('success', 'Loan product updated.');
    }

    public function destroyProduct(LoanProduct $product): RedirectResponse
    {
        $loanCount = $product->loans()->count();
        if ($loanCount > 0) {
            return back()->with('error',
                "Cannot delete product: {$loanCount} loan(s) reference it. Deactivate (is_active=false) instead so it stops appearing in the apply flow."
            );
        }
        $product->delete();
        return back()->with('success', 'Loan product removed.');
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'code'                 => ['required', 'string', 'max:30'],
            'name'                 => ['required', 'string', 'max:120'],
            'type'                 => ['required', 'string'],
            'min_amount'           => ['required', 'numeric', 'min:0'],
            'max_amount'           => ['required', 'numeric', 'gt:0'],
            'min_term_months'      => ['required', 'integer', 'min:1', 'max:360'],
            'max_term_months'      => ['required', 'integer', 'min:1', 'max:360'],
            'annual_interest_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'amortization_method'  => ['required', 'string'],
            'max_dti_ratio'        => ['nullable', 'numeric', 'min:0', 'max:1'],
            'requires_guarantor'   => ['sometimes', 'boolean'],
            'requires_collateral'  => ['sometimes', 'boolean'],
            'approvals_required'   => ['required', 'integer', 'min:1', 'max:5'],
            'is_active'            => ['sometimes', 'boolean'],
            'description'          => ['nullable', 'string', 'max:1000'],
            'effective_from'       => ['required', 'date'],
            'effective_to'         => ['nullable', 'date', 'after:effective_from'],
        ]);
    }
}
