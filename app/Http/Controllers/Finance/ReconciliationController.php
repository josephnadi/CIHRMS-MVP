<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\LinkReconciliationLineRequest;
use App\Http\Requests\Finance\PostBankAdjustmentRequest;
use App\Http\Requests\Finance\UnlinkReconciliationLineRequest;
use App\Http\Requests\Finance\UploadBankStatementRequest;
use App\Http\Resources\Finance\BankStatementLineResource;
use App\Http\Resources\Finance\BankStatementResource;
use App\Models\ApPayment;
use App\Models\ArReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Services\Finance\BankAdjustmentService;
use App\Services\Finance\ReconciliationMatcher;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\StatementImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly StatementImportService $importer,
        private readonly ReconciliationMatcher $matcher,
        private readonly ReconciliationService $reconciliation,
        private readonly BankAdjustmentService $adjustments,
    ) {
    }

    public function index(): Response
    {
        $statements = BankStatement::with('orgBankAccount:id,bank_name')
            ->orderByDesc('statement_date')
            ->paginate(50);

        return Inertia::render('Finance/Reconciliation/Index', [
            'activeModule' => 'finance-reconciliation',
            'statements'   => BankStatementResource::collection($statements),
            'bankAccounts' => OrgBankAccount::active()->orderBy('bank_name')->get(['id','bank_name','account_name','currency']),
        ]);
    }

    public function show(BankStatement $bankStatement): Response
    {
        $bankStatement->load('orgBankAccount');
        $lines = $bankStatement->lines()->orderBy('line_no')->get();

        $unreconciledAp = ApPayment::where('org_bank_account_id', $bankStatement->org_bank_account_id)
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ApPayment::class)->whereNotNull('matched_id');
            })
            ->orderByDesc('payment_date')->limit(200)->get(['id','reference','payment_date','amount','external_ref']);

        $unreconciledAr = ArReceipt::where('org_bank_account_id', $bankStatement->org_bank_account_id)
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ArReceipt::class)->whereNotNull('matched_id');
            })
            ->orderByDesc('receipt_date')->limit(200)->get(['id','reference','receipt_date','amount','external_ref']);

        return Inertia::render('Finance/Reconciliation/Show', [
            'activeModule'     => 'finance-reconciliation',
            'statement'        => (new BankStatementResource($bankStatement))->resolve(),
            'lines'            => BankStatementLineResource::collection($lines)->resolve(),
            'unreconciledAp'   => $unreconciledAp,
            'unreconciledAr'   => $unreconciledAr,
        ]);
    }

    public function store(UploadBankStatementRequest $request): RedirectResponse
    {
        $bank = OrgBankAccount::findOrFail($request->validated('org_bank_account_id'));

        try {
            $statement = $this->importer->import(
                $request->file('file'),
                $bank,
                $request->user(),
                $request->validated('bank_key'),
            );
            $this->matcher->matchUnreconciled($statement);
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('finance.reconciliation.show', $statement)
            ->with('success', 'Statement imported and auto-matched.');
    }

    public function link(BankStatementLine $line, LinkReconciliationLineRequest $request): RedirectResponse
    {
        $targetClass = $request->validated('target_type') === 'ap_payment' ? ApPayment::class : ArReceipt::class;
        $target = $targetClass::findOrFail($request->validated('target_id'));

        try {
            $this->reconciliation->link($line, $target, $request->user(), 'manual');
        } catch (DomainException $e) {
            return back()->withErrors(['target_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Line linked.');
    }

    public function unlink(BankStatementLine $line, UnlinkReconciliationLineRequest $request): RedirectResponse
    {
        try {
            $this->reconciliation->unlink($line, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Line unlinked.');
    }

    public function adjust(BankStatementLine $line, PostBankAdjustmentRequest $request): RedirectResponse
    {
        $gl = GlAccount::findOrFail($request->validated('gl_account_id'));
        // The adjustment service walks line->statement->orgBankAccount->glAccount.
        $line->loadMissing('statement.orgBankAccount.glAccount');

        try {
            $this->adjustments->postAdjustment($line, $gl, $request->user(), $request->validated('narration'));
        } catch (DomainException $e) {
            return back()->withErrors(['gl_account_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Bank adjustment posted.');
    }

    public function rematch(BankStatement $bankStatement): RedirectResponse
    {
        // The matcher reads $statement->importer to pick the matching strategy.
        $bankStatement->loadMissing('importer');
        $counts = $this->matcher->matchUnreconciled($bankStatement);

        $linked = $counts['high'] + $counts['medium'] + $counts['low'];
        $msg = $linked > 0
            ? "Re-matched {$linked} lines (high {$counts['high']} / medium {$counts['medium']} / low {$counts['low']}, {$counts['unmatched']} still unmatched)."
            : "No new matches found ({$counts['unmatched']} lines still unmatched).";

        return back()->with('success', $msg);
    }

    public function print(BankStatement $bankStatement, Request $request): SymfonyResponse
    {
        $bankStatement->load('orgBankAccount');

        $lines = $bankStatement->lines()
            ->with('matched:id,reference')
            ->orderBy('line_no')
            ->get()
            ->each(function ($line) {
                $line->matched_reference = $line->matched?->reference;
            });

        $reconciledLines = $lines->filter(fn ($l) => $l->reconciled_at !== null)->values();
        $unmatchedLines  = $lines->filter(fn ($l) => $l->reconciled_at === null)->values();

        $totalDr = (float) $lines->filter(fn ($l) => (float) $l->amount < 0)->sum(fn ($l) => abs((float) $l->amount));
        $totalCr = (float) $lines->filter(fn ($l) => (float) $l->amount > 0)->sum(fn ($l) => (float) $l->amount);

        $payload = [
            'statement'        => $bankStatement,
            'bank'             => $bankStatement->orgBankAccount,
            'reconciledLines'  => $reconciledLines,
            'unmatchedLines'   => $unmatchedLines,
            'reconciledCount'  => $reconciledLines->count(),
            'unmatchedCount'   => $unmatchedLines->count(),
            'totalCount'       => $lines->count(),
            'totalDr'          => $totalDr,
            'totalCr'          => $totalCr,
            'generatedBy'      => $request->user()?->name ?? 'system',
        ];

        $filename = sprintf(
            'reconciliation-%s-%s.pdf',
            $bankStatement->orgBankAccount?->bank_name ? str_replace(' ', '-', strtolower($bankStatement->orgBankAccount->bank_name)) : 'statement',
            $bankStatement->statement_date->toDateString(),
        );

        $pdf = Pdf::loadView('pdf.reconciliation-statement', $payload)->setPaper('a4', 'portrait');

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }
}
