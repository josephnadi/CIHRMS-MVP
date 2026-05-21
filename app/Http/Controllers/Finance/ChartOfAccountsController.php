<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreGlAccountRequest;
use App\Http\Requests\Finance\UpdateGlAccountRequest;
use App\Http\Resources\Finance\GlAccountResource;
use App\Models\GlAccount;
use App\Services\Finance\ChartOfAccountsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChartOfAccountsController extends Controller
{
    public function __construct(private readonly ChartOfAccountsService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['type', 'search']);

        return Inertia::render('Finance/Accounts/Index', [
            'tree'    => GlAccountResource::collection($this->service->tree()),
            'flat'    => GlAccountResource::collection($this->service->list($filters)),
            'filters' => $filters,
        ]);
    }

    public function store(StoreGlAccountRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());
        return back()->with('success', 'GL account created.');
    }

    public function update(UpdateGlAccountRequest $request, GlAccount $account): RedirectResponse
    {
        $this->service->update($account, $request->validated());
        return back()->with('success', 'GL account updated.');
    }

    public function destroy(GlAccount $account): RedirectResponse
    {
        $this->service->archive($account);
        return back()->with('success', 'GL account archived.');
    }
}
