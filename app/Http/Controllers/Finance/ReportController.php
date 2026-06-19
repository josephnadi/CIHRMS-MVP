<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\GlAccount;
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
    public function __construct(
        private readonly \App\Services\Finance\Reports\TrialBalanceReport $trialBalance,
        private readonly \App\Services\Finance\Reports\IncomeExpenditureReport $incomeExpenditure,
        private readonly \App\Services\Finance\Reports\FinancialPositionReport $financialPosition,
        private readonly \App\Services\Finance\LedgerBalanceService $ledger,
        private readonly \App\Services\Finance\Reports\CashFlowReport $cashFlow,
        private readonly \App\Services\Finance\Reports\BudgetVsActualsReport $budgetVsActuals,
    ) {
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

    public function financialActivities(Request $request): Response
    {
        [$from, $to] = $this->periodRange($request);

        return Inertia::render('Finance/Reports/FinancialActivities', [
            'activeModule' => 'finance-reports',
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'report'       => $this->incomeExpenditure->forPeriod($from, $to),
        ]);
    }

    public function financialPosition(Request $request): Response
    {
        $asOf = $this->asOfDate($request);

        return Inertia::render('Finance/Reports/FinancialPosition', [
            'activeModule' => 'finance-reports',
            'asOf'         => $asOf->toDateString(),
            'report'       => $this->financialPosition->asOf($asOf),
        ]);
    }

    public function accountLedger(Request $request, GlAccount $account): Response
    {
        $to   = $this->safeParse($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->safeParse($request->query('from'));

        return Inertia::render('Finance/Reports/AccountLedger', [
            'activeModule' => 'finance-reports',
            'account'      => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'from'         => $from?->toDateString(),
            'to'           => $to->toDateString(),
            'lines'        => $this->ledger->accountLines($account->id, $from, $to),
        ]);
    }

    public function financialActivitiesCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->periodRange($request);
        $report = $this->incomeExpenditure->forPeriod($from, $to);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Section', 'Code', 'Account', 'Current', 'Prior']);
            foreach (['income' => 'Income', 'expenditure' => 'Expenditure'] as $key => $label) {
                foreach ($report[$key]['rows'] as $row) {
                    fputcsv($out, [$label, $row['code'], $row['name'], $row['current'], $row['prior']]);
                }
                fputcsv($out, [$label . ' total', '', '', $report[$key]['total_current'], $report[$key]['total_prior']]);
            }
            fputcsv($out, ['Surplus/(Deficit)', '', '', $report['surplus_current'], $report['surplus_prior']]);
            fclose($out);
        }, "financial-activities-{$report['from']}-to-{$report['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function cashFlow(Request $request): Response
    {
        [$from, $to] = $this->periodRange($request);

        return Inertia::render('Finance/Reports/CashFlow', [
            'activeModule' => 'finance-reports',
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'report'       => $this->cashFlow->forPeriod($from, $to),
        ]);
    }

    public function cashFlowCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->periodRange($request);
        $report = $this->cashFlow->forPeriod($from, $to);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Cash Flow Statement', "{$report['from']} to {$report['to']}"]);
            fputcsv($out, []);
            fputcsv($out, ['Direct method', 'Amount']);
            fputcsv($out, ['Operating', $report['direct']['operating']]);
            fputcsv($out, ['Investing', $report['direct']['investing']]);
            fputcsv($out, ['Financing', $report['direct']['financing']]);
            fputcsv($out, ['Net change in cash', $report['direct']['net']]);
            fputcsv($out, []);
            fputcsv($out, ['Indirect method', 'Amount']);
            fputcsv($out, ['Surplus/(Deficit)', $report['indirect']['surplus']]);
            fputcsv($out, ['Operating', $report['indirect']['operating']]);
            fputcsv($out, ['Investing', $report['indirect']['investing']]);
            fputcsv($out, ['Financing', $report['indirect']['financing']]);
            fputcsv($out, ['Net change in cash', $report['indirect']['net']]);
            fclose($out);
        }, "cash-flow-{$report['from']}-to-{$report['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function budgetVsActuals(Request $request): Response
    {
        [$year, $period] = $this->budgetYearPeriod($request);

        return Inertia::render('Finance/Reports/BudgetVsActuals', [
            'activeModule' => 'finance-reports',
            'year'         => $year,
            'period'       => $period,
            'report'       => $this->budgetVsActuals->forYear($year, $period),
        ]);
    }

    public function budgetVsActualsCsv(Request $request): StreamedResponse
    {
        [$year, $period] = $this->budgetYearPeriod($request);
        $report = $this->budgetVsActuals->forYear($year, $period);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Code', 'Account', 'Annual budget', 'YTD budget', 'YTD actual', 'Variance', 'Status']);
            foreach ($report['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    fputcsv($out, [$row['type'], $row['code'], $row['name'], $row['annual_budget'],
                        $row['ytd_budget'], $row['ytd_actual'], $row['variance'],
                        $row['favourable'] === null ? '' : ($row['favourable'] ? 'Favourable' : 'Unfavourable')]);
                }
                fputcsv($out, [strtoupper($group['type']) . ' total', '', '', $group['annual_budget'],
                    $group['ytd_budget'], $group['ytd_actual'], $group['variance'], '']);
            }
            fputcsv($out, ['GRAND TOTAL', '', '', $report['totals']['annual_budget'],
                $report['totals']['ytd_budget'], $report['totals']['ytd_actual'], $report['totals']['variance'], '']);
            fclose($out);
        }, "budget-vs-actuals-{$report['year']}-p{$report['as_of_period']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function budgetVsActualsPdf(Request $request): HttpResponse
    {
        [$year, $period] = $this->budgetYearPeriod($request);
        $report = $this->budgetVsActuals->forYear($year, $period);

        return Pdf::loadView('finance.reports.budget-vs-actuals-pdf', ['report' => $report])
            ->download("budget-vs-actuals-{$report['year']}-p{$report['as_of_period']}.pdf");
    }

    /** @return array{0:int,1:int} [year, periodNo] */
    private function budgetYearPeriod(Request $request): array
    {
        $year   = (int) ($request->query('year') ?: CarbonImmutable::today()->year);
        $period = (int) ($request->query('period') ?: 12);

        return [$year, max(1, min(12, $period))];
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} [from, to] */
    private function periodRange(Request $request): array
    {
        $to   = $this->safeParse($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->safeParse($request->query('from')) ?? $to->startOfMonth();

        return [$from, $to];
    }

    private function safeParse(?string $raw): ?CarbonImmutable
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

    private function asOfDate(Request $request): CarbonImmutable
    {
        return $this->safeParse($request->query('as_of')) ?? CarbonImmutable::today();
    }
}
