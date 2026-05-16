<?php

namespace App\Http\Controllers;

use App\Http\Resources\DisbursementResource;
use App\Models\Disbursement;
use App\Models\PayrollRun;
use App\Services\Disbursement\BatchDisbursementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DisbursementController extends Controller
{
    public function __construct(private readonly BatchDisbursementService $batch) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('payroll.disburse')
                  || $request->user()->hasPermission('payroll.view_all'), 403);

        $rows = Disbursement::query()
            ->with(['employee.user', 'run:id,reference,period_year,period_month'])
            ->when($request->run_id,  fn ($q, $v) => $q->where('payroll_run_id', $v))
            ->when($request->channel, fn ($q, $v) => $q->where('channel', $v))
            ->when($request->status,  fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $stats = [
            'pending'    => Disbursement::where('status', 'pending')->count(),
            'sent'       => Disbursement::where('status', 'sent')->count(),
            'settled'    => Disbursement::where('status', 'settled')->count(),
            'failed'     => Disbursement::where('status', 'failed')->count(),
            'momo_total' => (float) Disbursement::whereIn('channel', ['mtn_momo', 'vodafone_cash', 'airtel_tigo'])
                ->where('status', 'settled')->sum('net_to_recipient'),
            'e_levy_total' => (float) Disbursement::sum('e_levy'),
        ];

        return Inertia::render('Disbursements/Index', [
            'disbursements' => DisbursementResource::collection($rows),
            'stats'         => $stats,
            'filters'       => $request->only(['run_id', 'channel', 'status']),
            'activeModule'  => 'disbursements',
        ]);
    }

    public function dispatchRun(Request $request, PayrollRun $run): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payroll.disburse'), 403);

        $result = $this->batch->dispatch($run);

        return back()->with('success', sprintf(
            'Dispatched: %d sent, %d failed, %d skipped (manual channels).',
            $result['sent'], $result['failed'], $result['skipped'],
        ));
    }

    public function reconcile(Request $request, PayrollRun $run): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payroll.disburse'), 403);

        $touched = $this->batch->reconcile($run);

        return back()->with('success', "Reconciliation: {$touched} disbursement(s) updated.");
    }
}
