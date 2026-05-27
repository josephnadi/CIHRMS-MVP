<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreArInvoiceRequest;
use App\Http\Resources\Finance\ArInvoiceResource;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Services\Finance\ArInvoiceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArInvoiceController extends Controller
{
    public function __construct(private readonly ArInvoiceService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'customer_id', 'search']);

        $q = ArInvoice::query()->with(['customer:id,code,name']);
        if (! empty($filters['status']))       $q->where('status', $filters['status']);
        if (! empty($filters['customer_id']))  $q->where('customer_id', $filters['customer_id']);
        if (! empty($filters['search']))       $q->where('reference', 'like', '%'.$filters['search'].'%');

        $invoices = $q->orderByDesc('invoice_date')->paginate(50)->withQueryString();

        return Inertia::render('Finance/ArInvoices/Index', [
            'activeModule'   => 'finance-ar-invoices',
            'invoices'       => ArInvoiceResource::collection($invoices),
            'filters'        => $filters,
            'customers'      => Customer::active()->orderBy('name')->get(['id','code','name','default_income_gl_account_id','default_ar_gl_account_id']),
            'incomeAccounts' => GlAccount::ofType('income')->active()->orderBy('code')->get(['id','code','name']),
        ]);
    }

    public function show(ArInvoice $arInvoice, Request $request): Response
    {
        // Defense-in-depth (M8): the route group requires `ar_invoices.view`,
        // but a permission re-check here keeps the controller closed if the
        // middleware is ever refactored or this method is invoked outside
        // the route stack.
        abort_unless($request->user()?->hasPermission('ar_invoices.view'), 403);

        $arInvoice->load(['customer', 'lines.glAccount', 'accrualJournalEntry', 'writeOffJournalEntry', 'allocations.receipt']);

        return Inertia::render('Finance/ArInvoices/Show', [
            'activeModule' => 'finance-ar-invoices',
            'invoice'      => (new ArInvoiceResource($arInvoice))->resolve(),
        ]);
    }

    public function store(StoreArInvoiceRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice created — accrual journal posted.');
    }

    public function submit(ArInvoice $arInvoice, Request $request): RedirectResponse
    {
        if (! $request->user()?->hasPermission('ar_invoices.create')) {
            abort(403);
        }
        try {
            $this->service->submit($arInvoice);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice submitted for approval.');
    }

    public function approve(ArInvoice $arInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ar_invoices.approve'), 403);

        try {
            $this->service->approve($arInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice approved.');
    }

    public function cancel(ArInvoice $arInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ar_invoices.approve'), 403);

        $reason = (string) $request->input('reason', 'no reason given');
        try {
            $this->service->cancel($arInvoice, $request->user(), $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice cancelled — accrual reversed.');
    }

    public function writeOff(ArInvoice $arInvoice, Request $request): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->writeOff($arInvoice, $request->user(), (string) $request->input('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice written off — bad-debt journal posted.');
    }
}
