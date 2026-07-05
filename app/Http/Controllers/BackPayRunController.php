<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BackPayRun;
use App\Models\SalaryRevision;
use App\Services\Payroll\BackPayRunService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BackPayRunController extends Controller
{
    public function __construct(private readonly BackPayRunService $service) {}

    /** Create a draft back-pay run from a revision, then jump to its detail page. */
    public function store(Request $request, SalaryRevision $revision): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.run'), 403);

        try {
            $run = $this->service->create($revision, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['back_pay' => $e->getMessage()]);
        }

        return redirect()->route('back-pay-runs.show', $run->id)
            ->with('success', "Back-pay run {$run->reference} created for {$run->employees_count} employees.");
    }

    public function show(Request $request, BackPayRun $run): Response
    {
        abort_unless($request->user()?->hasPermission('payroll.run'), 403);

        $run->load([
            'revision:id,reference,percentage,effective_from',
            'creator:id,name',
            'approver:id,name',
            'lines.employee.user:id,name',
        ]);

        return Inertia::render('Payroll/BackPayRuns/Show', [
            'run'          => [
                'id'                   => $run->id,
                'reference'            => $run->reference,
                'status'               => $run->status,
                'effective_from'       => $run->effective_from?->toDateString(),
                'employees_count'      => $run->employees_count,
                'gross_total'          => (float) $run->gross_total,
                'arrears_net_total'    => (float) $run->arrears_net_total,
                'back_paye_total'      => (float) $run->back_paye_total,
                'ssnit_employee_total' => (float) $run->ssnit_employee_total,
                'ssnit_employer_total' => (float) $run->ssnit_employer_total,
                'tier2_employer_total' => (float) $run->tier2_employer_total,
                'tier3_employee_total' => (float) $run->tier3_employee_total,
                'approved_at'          => $run->approved_at?->toDateTimeString(),
                'paid_at'              => $run->paid_at?->toDateTimeString(),
                'revision'             => $run->revision,
                'creator'              => $run->creator?->only(['id', 'name']),
                'approver'             => $run->approver?->only(['id', 'name']),
            ],
            'lines'        => $run->lines->map(fn ($l) => [
                'employee_id'    => $l->employee_id,
                'employee_name'  => $l->employee?->user?->name ?? $l->employee?->full_name ?? null,
                'employee_no'    => $l->employee?->employee_no,
                'gross'          => (float) $l->gross,
                'arrears_net'    => (float) $l->arrears_net,
                'back_paye'      => (float) $l->back_paye,
                'ssnit_employee' => (float) $l->ssnit_employee,
                'tier2_employer' => (float) $l->tier2_employer,
                'tier3_employee' => (float) $l->tier3_employee,
                'months'         => $l->breakdown ?? [],
            ]),
            'canApprove'   => $run->status === BackPayRun::STATUS_DRAFT && $run->created_by !== $request->user()?->id,
            'activeModule' => 'payroll',
        ]);
    }

    public function approve(Request $request, BackPayRun $run): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.run'), 403);

        try {
            $this->service->approve($run, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return back()->with('success', "Back-pay run {$run->reference} approved and posted to the ledger.");
    }

    public function pay(Request $request, BackPayRun $run): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.run'), 403);

        try {
            $this->service->markPaid($run);
        } catch (DomainException $e) {
            return back()->withErrors(['pay' => $e->getMessage()]);
        }

        return back()->with('success', "Back-pay run {$run->reference} marked paid.");
    }
}
