<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Finance\CustomerStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatementController extends Controller
{
    public function __construct(private readonly CustomerStatementService $service)
    {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Statements/Index', [
            'activeModule' => 'finance-statements',
            'customers'    => Customer::active()->orderBy('name')->get(['id','code','name']),
        ]);
    }

    public function show(Customer $customer, Request $request): Response
    {
        $today = CarbonImmutable::today();
        $defaultFrom = $today->startOfMonth()->subMonths(2);
        $defaultTo   = $today;

        $from = CarbonImmutable::parse((string) $request->query('from', $defaultFrom->toDateString()));
        $to   = CarbonImmutable::parse((string) $request->query('to',   $defaultTo->toDateString()));

        return Inertia::render('Finance/Statements/Index', [
            'activeModule' => 'finance-statements',
            'customers'    => Customer::active()->orderBy('name')->get(['id','code','name']),
            'statement'    => $this->service->generate($customer, $from, $to),
            'selectedCustomerId' => $customer->id,
        ]);
    }
}
