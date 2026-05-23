<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreVendorRequest;
use App\Http\Requests\Finance\UpdateVendorRequest;
use App\Http\Resources\Finance\VendorResource;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\Vendor;
use App\Services\Finance\VendorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'search']);

        return Inertia::render('Finance/Vendors/Index', [
            'activeModule'    => 'finance-vendors',
            'vendors'         => VendorResource::collection($this->service->list($filters)),
            'filters'         => $filters,
            'expenseAccounts' => GlAccount::ofType('expense')->active()->orderBy('code')->get(['id','code','name']),
            'apAccounts'      => GlAccount::ofType('liability')->active()->orderBy('code')->get(['id','code','name']),
            'bankAccounts'    => OrgBankAccount::active()->orderBy('bank_name')->get(['id','bank_name','account_name','gl_account_id']),
        ]);
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());
        return back()->with('success', 'Vendor created.');
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->service->update($vendor, $request->validated());
        return back()->with('success', 'Vendor updated.');
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        $this->service->archive($vendor);
        return back()->with('success', 'Vendor archived.');
    }
}
