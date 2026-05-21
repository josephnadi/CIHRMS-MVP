<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceHubService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceHubController extends Controller
{
    public function __construct(private readonly FinanceHubService $service)
    {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Hub', $this->service->summaryFor($request->user()));
    }
}
