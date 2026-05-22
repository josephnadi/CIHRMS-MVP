<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreOrgBankAccountRequest;
use App\Http\Requests\Finance\UpdateOrgBankAccountRequest;
use App\Http\Resources\Finance\OrgBankAccountResource;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Services\Finance\OrgBankAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgBankAccountController extends Controller
{
    public function __construct(private readonly OrgBankAccountService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['purpose']);
        $banks = $this->service->list($filters);

        return Inertia::render('Finance/BankAccounts/Index', [
            'activeModule' => 'finance-bank-accounts',
            'banks'        => OrgBankAccountResource::collection($banks),
            'filters'      => $filters,
            'assetAccounts'=> GlAccount::ofType('asset')->active()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreOrgBankAccountRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());
        return back()->with('success', 'Bank account created.');
    }

    public function update(UpdateOrgBankAccountRequest $request, OrgBankAccount $bankAccount): RedirectResponse
    {
        $this->service->update($bankAccount, $request->validated());
        return back()->with('success', 'Bank account updated.');
    }

    public function destroy(OrgBankAccount $bankAccount): RedirectResponse
    {
        $this->service->archive($bankAccount);
        return back()->with('success', 'Bank account archived.');
    }
}
