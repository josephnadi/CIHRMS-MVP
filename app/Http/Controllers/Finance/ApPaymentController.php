<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreApPaymentRequest;
use App\Http\Resources\Finance\ApPaymentResource;
use App\Models\ApPayment;
use App\Models\OrgBankAccount;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\ApPaymentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApPaymentController extends Controller
{
    public function __construct(private readonly ApPaymentService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'vendor_id']);

        $q = ApPayment::query()->with(['vendor:id,code,name', 'bankAccount:id,bank_name,account_name', 'allocations']);
        if (! empty($filters['status']))    $q->where('status', $filters['status']);
        if (! empty($filters['vendor_id'])) $q->where('vendor_id', $filters['vendor_id']);

        $payments = $q->orderByDesc('payment_date')->paginate(50)->withQueryString();

        return Inertia::render('Finance/ApPayments/Index', [
            'activeModule'   => 'finance-ap-payments',
            'payments'       => ApPaymentResource::collection($payments),
            'filters'        => $filters,
            'vendors'        => Vendor::active()->orderBy('name')->get(['id','code','name']),
            'openInvoices'   => VendorInvoice::open()->with('vendor:id,code,name')->orderBy('invoice_date')->get([
                'id','reference','vendor_id','vendor_invoice_no','total','amount_paid','invoice_date',
            ]),
            'bankAccounts'   => OrgBankAccount::active()->orderBy('bank_name')->get(['id','bank_name','account_name']),
        ]);
    }

    public function store(StoreApPaymentRequest $request): RedirectResponse
    {
        try {
            $this->service->record($request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['allocations' => $e->getMessage()]);
        }
        return back()->with('success', 'Payment recorded — journal entry posted.');
    }

    public function void(ApPayment $apPayment, Request $request): RedirectResponse
    {
        $reason = (string) $request->input('reason', 'no reason given');
        try {
            $this->service->void($apPayment, $request->user(), $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Payment voided — journal entry reversed.');
    }

    public function disburse(ApPayment $apPayment, Request $request): RedirectResponse
    {
        // F2 stub: record the intent only. Full BatchDisbursementService wiring is
        // a follow-up if/when the existing service supports single-payment dispatch.
        $apPayment->update(['narration' => trim(($apPayment->narration ?? '') . ' [disburse requested by ' . $request->user()->name . ']')]);
        return back()->with('success', 'Disbursement intent recorded. Operator must complete externally for F2.');
    }
}
