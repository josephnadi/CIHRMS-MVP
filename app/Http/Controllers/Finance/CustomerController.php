<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreCustomerRequest;
use App\Http\Requests\Finance\UpdateCustomerRequest;
use App\Http\Resources\Finance\CustomerResource;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Services\Finance\CustomerService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'search']);

        return Inertia::render('Finance/Customers/Index', [
            'activeModule'   => 'finance-customers',
            'customers'      => CustomerResource::collection($this->service->list($filters)),
            'filters'        => $filters,
            'incomeAccounts' => GlAccount::ofType('income')->active()->orderBy('code')->get(['id','code','name']),
            'arAccounts'     => GlAccount::ofType('asset')->active()->orderBy('code')->get(['id','code','name']),
            'bankAccounts'   => OrgBankAccount::active()->orderBy('bank_name')->get(['id','bank_name','account_name','gl_account_id']),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());
        return back()->with('success', 'Customer created.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->service->update($customer, $request->validated());
        return back()->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        try {
            $this->service->archive($customer);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Customer archived.');
    }
}
