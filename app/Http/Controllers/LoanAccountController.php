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

        return Inertia::render('Loans/Index', [
            'loans'        => LoanAccountResource::collection($loans),
            'products'     => LoanProductResource::collection(LoanProduct::active()->orderBy('name')->get()),
            'stats'        => $stats,
            'filters'      => $request->only(['status', 'product_id', 'q']),
            'activeModule' => 'loans',
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
}
