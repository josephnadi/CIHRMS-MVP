<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(private readonly FinanceAnalyticsService $analytics)
    {
    }

    public function dashboard(Request $request): Response
    {
        [$year, $from, $to] = $this->range($request);

        return Inertia::render('Finance/Analytics/Dashboard', [
            'activeModule' => 'finance-analytics',
            'year'         => $year,
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'kpis'         => $this->analytics->kpis($from, $to),
            'trends'       => $this->analytics->trends($from, $to),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        [, $from, $to] = $this->range($request);
        $t = $this->analytics->trends($from, $to);

        return response()->streamDownload(function () use ($t) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Month', 'Income', 'Expenditure', 'Surplus', 'Cash']);
            foreach ($t['months'] as $i => $m) {
                fputcsv($out, [$m, $t['income'][$i], $t['expenditure'][$i], $t['surplus'][$i], $t['cash'][$i]]);
            }
            fclose($out);
        }, "finance-analytics-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request): HttpResponse
    {
        [$year, $from, $to] = $this->range($request);

        return Pdf::loadView('finance.analytics-pdf', [
            'year'   => $year,
            'from'   => $from->toDateString(),
            'to'     => $to->toDateString(),
            'kpis'   => $this->analytics->kpis($from, $to),
            'trends' => $this->analytics->trends($from, $to),
        ])->download("finance-analytics-{$year}.pdf");
    }

    /** @return array{0:int,1:CarbonImmutable,2:CarbonImmutable} */
    private function range(Request $request): array
    {
        $year = (int) ($request->query('year') ?: CarbonImmutable::today()->year);
        $to   = $this->parse($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->parse($request->query('from')) ?? CarbonImmutable::create($year, 1, 1);

        return [$year, $from, $to];
    }

    private function parse(?string $raw): ?CarbonImmutable
    {
        if (! $raw) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
