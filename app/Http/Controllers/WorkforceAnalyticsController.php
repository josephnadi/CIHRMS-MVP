<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WorkforceAnalyticsRequest;
use App\Models\Department;
use App\Services\WorkforceAnalyticsService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class WorkforceAnalyticsController extends Controller
{
    public function __construct(private readonly WorkforceAnalyticsService $analytics)
    {
    }

    public function index(WorkforceAnalyticsRequest $request): Response
    {
        $to   = $request->filled('to') ? CarbonImmutable::parse($request->date('to')) : CarbonImmutable::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->date('from')) : $to->subYear();
        $deptId = $request->filled('department_id') ? (int) $request->integer('department_id') : null;

        return Inertia::render('Analytics/Workforce', [
            'activeModule' => 'workforce-analytics',
            'filters'      => [
                'department_id' => $deptId,
                'from'          => $from->toDateString(),
                'to'            => $to->toDateString(),
            ],
            'departments'  => Department::query()->orderBy('name')->get(['id', 'name']),
            'metrics'      => $this->analytics->metrics($deptId, $from, $to),
        ]);
    }
}
