<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreArReceiptRequest;
use App\Http\Resources\Finance\ArReceiptResource;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Customer;
use App\Models\OrgBankAccount;
use App\Services\Finance\ArReceiptService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArReceiptController extends Controller
{
    public function __construct(private readonly ArReceiptService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'customer_id']);

        $q = ArReceipt::query()->with(['customer:id,code,name', 'bankAccount:id,bank_name,account_name', 'allocations']);
        if (! empty($filters['status']))      $q->where('status', $filters['status']);
        if (! empty($filters['customer_id'])) $q->where('customer_id', $filters['customer_id']);

        $receipts = $q->orderByDesc('receipt_date')->paginate(50)->withQueryString();

        return Inertia::render('Finance/ArReceipts/Index', [
            'activeModule'   => 'finance-ar-receipts',
            'receipts'       => ArReceiptResource::collection($receipts),
            'filters'        => $filters,
            'customers'      => Customer::active()->orderBy('name')->get(['id','code','name','default_bank_account_id']),
            'bankAccounts'   => OrgBankAccount::active()->orderBy('bank_name')->get(['id','bank_name','account_name','gl_account_id']),
            'openInvoices'   => ArInvoice::query()->open()->orderBy('reference')->get([
                'id', 'reference', 'customer_id', 'total', 'amount_received', 'currency',
            ])->map(fn ($i) => [
                'id'              => $i->id,
                'reference'       => $i->reference,
                'customer_id'     => $i->customer_id,
                'total'           => (float) $i->total,
                'amount_received' => (float) $i->amount_received,
                'outstanding'     => $i->outstandingAmount(),
                'currency'        => $i->currency,
            ]),
        ]);
    }

    public function store(StoreArReceiptRequest $request): RedirectResponse
    {
        try {
            $this->service->record($request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Receipt recorded — journal posted, invoices updated.');
    }

    public function void(ArReceipt $arReceipt, Request $request): RedirectResponse
    {
        $reason = (string) $request->input('reason', 'no reason given');
        try {
            $this->service->void($arReceipt, $request->user(), $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Receipt voided — journal reversed.');
    }
}
