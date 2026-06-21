<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreVendorInvoiceRequest;
use App\Http\Requests\Finance\UpdateVendorInvoiceRequest;
use App\Http\Resources\Finance\VendorInvoiceResource;
use App\Models\GlAccount;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\VendorInvoiceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApInvoiceController extends Controller
{
    public function __construct(private readonly VendorInvoiceService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'vendor_id', 'search']);

        // Lines are eager-loaded so the Index can prefill the edit panel for drafts.
        $q = VendorInvoice::query()->with(['vendor:id,code,name', 'lines']);
        if (! empty($filters['status']))    $q->where('status', $filters['status']);
        if (! empty($filters['vendor_id'])) $q->where('vendor_id', $filters['vendor_id']);
        if (! empty($filters['search']))    $q->where('reference', 'like', '%'.$filters['search'].'%');

        $invoices = $q->orderByDesc('invoice_date')->paginate(50)->withQueryString();

        return Inertia::render('Finance/ApInvoices/Index', [
            'activeModule'    => 'finance-ap-invoices',
            'invoices'        => VendorInvoiceResource::collection($invoices),
            'filters'         => $filters,
            'vendors'         => Vendor::active()->orderBy('name')->get(['id','code','name','default_expense_gl_account_id','default_ap_gl_account_id']),
            'expenseAccounts' => GlAccount::ofType('expense')->active()->orderBy('code')->get(['id','code','name']),
        ]);
    }

    public function show(VendorInvoice $apInvoice, Request $request): Response
    {
        // Defense-in-depth (M8): mirror the route-group permission gate.
        abort_unless($request->user()?->hasPermission('ap_invoices.view'), 403);

        $apInvoice->load(['vendor', 'lines.glAccount', 'accrualJournalEntry', 'allocations.payment']);

        return Inertia::render('Finance/ApInvoices/Show', [
            'activeModule' => 'finance-ap-invoices',
            'invoice'      => (new VendorInvoiceResource($apInvoice))->resolve(),
        ]);
    }

    public function store(StoreVendorInvoiceRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user());
        return back()->with('success', 'Invoice created — accrual journal posted.');
    }

    public function update(UpdateVendorInvoiceRequest $request, VendorInvoice $apInvoice): RedirectResponse
    {
        try {
            $this->service->update($apInvoice, $request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice updated — accrual re-posted.');
    }

    public function destroy(VendorInvoice $apInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ap_invoices.create'), 403);

        try {
            $this->service->delete($apInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Draft invoice deleted — accrual reversed.');
    }

    /** Print-friendly view of the invoice (on-screen, browser print). */
    public function print(VendorInvoice $apInvoice, Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('ap_invoices.view'), 403);

        $apInvoice->load(['vendor', 'lines.glAccount']);

        return Inertia::render('Finance/ApInvoices/Print', [
            'invoice' => (new VendorInvoiceResource($apInvoice))->resolve(),
            'org'     => ['name' => config('app.name')],
        ]);
    }

    public function submit(VendorInvoice $apInvoice, Request $request): RedirectResponse
    {
        if (! $request->user()?->hasPermission('ap_invoices.create')) {
            abort(403);
        }
        try {
            $this->service->submit($apInvoice);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice submitted for approval.');
    }

    public function approve(VendorInvoice $apInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ap_invoices.approve'), 403);

        try {
            $this->service->approve($apInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice approved.');
    }

    public function cancel(VendorInvoice $apInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ap_invoices.approve'), 403);

        $reason = (string) $request->input('reason', 'no reason given');
        try {
            $this->service->cancel($apInvoice, $request->user(), $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice cancelled — accrual reversed.');
    }
}
