<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\GeneratePayslipRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Employee;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): Response
    {
        $employees = Employee::with('user')
            ->active()
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'name'        => $e->user?->name,
                'employee_no' => $e->employee_no,
            ]);

        return Inertia::render('Payments/Index', [
            'payments'     => PaymentResource::collection($this->payments->list($request)),
            'employees'    => $employees,
            'analytics'    => $this->payments->analytics(),
            'filters'      => $request->only(['status', 'employee_id', 'month']),
            'activeModule' => 'payroll',
        ]);
    }

    public function show(Payment $payment): Response
    {
        return Inertia::render('Payments/Show', [
            'payment'      => new PaymentResource($payment->load(['employee.user', 'processedBy', 'items'])),
            'activeModule' => 'payroll',
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $this->payments->create($request);

        return back()->with('success', 'Payment record created.');
    }

    public function markPaid(Payment $payment): RedirectResponse
    {
        $this->payments->markPaid($payment, (int) Auth::id());

        return back()->with('success', 'Payment marked as paid.');
    }

    public function previewPayslip(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'basic'                        => ['required', 'numeric', 'min:0'],
            'allowances'                   => ['array'],
            'allowances.*.label'           => ['nullable', 'string', 'max:120'],
            'allowances.*.amount'          => ['nullable', 'numeric', 'min:0'],
            'voluntary_deductions'         => ['array'],
            'voluntary_deductions.*.label' => ['nullable', 'string', 'max:120'],
            'voluntary_deductions.*.amount'=> ['nullable', 'numeric', 'min:0'],
            'tier3_employee'               => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->payments->previewPayslip($payload));
    }

    public function generatePayslip(GeneratePayslipRequest $request): RedirectResponse
    {
        $payment = $this->payments->generatePayslip($request);

        return redirect()
            ->route('payments.show', $payment->id)
            ->with('success', 'Payslip generated successfully.');
    }
}
