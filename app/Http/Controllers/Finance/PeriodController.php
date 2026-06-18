<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function __construct(private readonly PeriodCloseService $service)
    {
    }

    public function index(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));

        // Ensure the requested year exists so the calendar is never empty.
        app(FiscalCalendarService::class)->ensureYear($year);

        $fiscalYear = FiscalYear::where('year', $year)->with('periods')->firstOrFail();

        return Inertia::render('Finance/FiscalCalendar/Index', [
            'activeModule' => 'finance-periods',
            'year'         => $year,
            'years'        => FiscalYear::orderBy('year')->pluck('year'),
            'periods'      => FiscalPeriodResource::collection($fiscalYear->periods),
        ]);
    }

    public function close(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(fn () => $this->service->close($fiscalPeriod, $request->user()), "Period {$fiscalPeriod->name} closed.");
    }

    public function reopen(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(fn () => $this->service->reopen($fiscalPeriod, $request->user()), "Period {$fiscalPeriod->name} reopened.");
    }

    public function lock(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(fn () => $this->service->lock($fiscalPeriod, $request->user()), "Period {$fiscalPeriod->name} locked.");
    }

    private function transition(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
