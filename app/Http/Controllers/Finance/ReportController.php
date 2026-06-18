<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\Reports\TrialBalanceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly TrialBalanceReport $trialBalance)
    {
    }

    public function trialBalance(Request $request): Response
    {
        $asOf = $this->asOfDate($request);

        return Inertia::render('Finance/Reports/TrialBalance', [
            'activeModule' => 'finance-reports',
            'asOf'         => $asOf->toDateString(),
            'report'       => $this->trialBalance->forDate($asOf),
        ]);
    }

    public function trialBalanceCsv(Request $request): StreamedResponse
    {
        $asOf   = $this->asOfDate($request);
        $report = $this->trialBalance->forDate($asOf);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, [$row['code'], $row['name'], $row['type'], $row['debit'], $row['credit']]);
            }
            fputcsv($out, ['', 'TOTAL', '', $report['total_debit'], $report['total_credit']]);
            fclose($out);
        }, "trial-balance-{$report['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function trialBalancePdf(Request $request): HttpResponse
    {
        $asOf   = $this->asOfDate($request);
        $report = $this->trialBalance->forDate($asOf);

        return Pdf::loadView('finance.reports.trial-balance-pdf', ['report' => $report])
            ->download("trial-balance-{$report['as_of']}.pdf");
    }

    private function asOfDate(Request $request): CarbonImmutable
    {
        $raw = $request->query('as_of');

        return $raw ? CarbonImmutable::parse($raw) : CarbonImmutable::today();
    }
}
