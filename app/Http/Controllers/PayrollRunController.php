<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\ApprovePayrollRunRequest;
use App\Http\Requests\Payroll\ReversePayrollRunRequest;
use App\Http\Requests\Payroll\StorePayrollRunRequest;
use App\Http\Resources\PayrollLineResource;
use App\Http\Resources\PayrollRunResource;
use App\Http\Resources\StatutoryReturnResource;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()
            ->with(['department', 'creator', 'approver'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->year,   fn ($q, $v) => $q->where('period_year', $v))
            ->latest('period_year')
            ->latest('period_month')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Payroll/Runs/Index', [
            'runs'         => PayrollRunResource::collection($runs),
            'filters'      => $request->only(['status', 'year']),
            'activeModule' => 'payroll',
        ]);
    }

    public function show(Request $request, PayrollRun $run): Response
    {
        $this->authorize('view', $run);

        $run->load(['department', 'creator', 'approver', 'reverser', 'returns.trustee', 'returns.submitter']);

        // Attach the run to each return so the resource can compute the due date
        // without an N+1 lazy-load of the run() belongsTo per row.
        $run->returns->each(fn ($r) => $r->setRelation('run', $run));

        $lines = $run->lines()
            ->with(['employee.user', 'employee.department', 'grade'])
            ->orderBy('id')
            ->paginate(50);

        return Inertia::render('Payroll/Runs/Show', [
            'run'          => new PayrollRunResource($run),
            'lines'        => PayrollLineResource::collection($lines),
            'returns'      => StatutoryReturnResource::collection($run->returns),
            'canRemit'     => $request->user()->hasPermission('statutory.remit'),
            'activeModule' => 'payroll',
        ]);
    }

    public function store(StorePayrollRunRequest $request): RedirectResponse
    {
        $run = $this->payroll->createDraft(
            year:         (int) $request->validated('period_year'),
            month:        (int) $request->validated('period_month'),
            departmentId: $request->validated('department_id'),
            creator:      $request->user(),
            reason:       $request->validated('reason'),
        );

        return redirect()->route('payroll-runs.show', $run)->with('success', 'Payroll run draft created.');
    }

    public function calculate(PayrollRun $run): RedirectResponse
    {
        $this->authorize('view', $run);
        $this->payroll->calculate($run);
        return back()->with('success', 'Payroll calculated.');
    }

    public function approve(ApprovePayrollRunRequest $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('approve', $run);
        $this->payroll->approve($run, $request->user());
        return back()->with('success', 'Payroll run approved. Statutory returns are generating.');
    }

    public function reverse(ReversePayrollRunRequest $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('reverse', $run);
        $this->payroll->reverse($run, $request->user(), (string) $request->validated('reason'));
        return back()->with('success', 'Payroll run reversed.');
    }

    public function markPaid(PayrollRun $run): RedirectResponse
    {
        $this->authorize('approve', $run); // same gate as approve
        $this->payroll->markPaid($run);
        return back()->with('success', 'Payroll run marked as paid.');
    }

    public function downloadReturn(PayrollRun $run, int $returnId): StreamedResponse
    {
        $this->authorize('view', $run);

        $return = $run->returns()->findOrFail($returnId);

        abort_unless(Storage::disk('local')->exists($return->file_path), 404);

        return Storage::disk('local')->download($return->file_path);
    }

    public function markReturnFiled(Request $request, PayrollRun $run, int $returnId, \App\Services\Payroll\RemittanceService $remittance): RedirectResponse
    {
        $this->authorize('view', $run); // visible to run viewers; the route permission gates the write
        if (! $request->user()->hasPermission('statutory.remit')) {
            abort(403);
        }

        $data = $request->validate([
            'reference'    => ['required', 'string', 'max:120'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $return = $run->returns()->findOrFail($returnId);

        try {
            $remittance->markSubmitted(
                $return,
                $request->user(),
                $data['reference'],
                isset($data['submitted_at']) ? \Carbon\CarbonImmutable::parse($data['submitted_at']) : null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Statutory return recorded as filed.');
    }

    /**
     * Stream the Oracle IPPD2/IPPD3 upload file for this run. Each call
     * regenerates the file from the current PayrollLine rows, so it always
     * reflects the latest state — there's no stale-file failure mode where
     * a re-run leaves a download pointing at outdated numbers.
     */
    public function downloadIppd(PayrollRun $run, \App\Services\Payroll\Ippd\IppdExporter $exporter): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $run);

        $contents = $exporter->preview($run);
        $filename = sprintf('IPPD-%s.csv', $run->reference);

        return response($contents, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Stream the GIFMIS journal-voucher CSV for this run. Like the IPPD
     * endpoint, the file is regenerated on every request so it always
     * reflects current PayrollLine state. Throws a 422 with the residual
     * if the journal doesn't balance — that's a calculator bug, not a
     * user error, but surfacing it before upload prevents a state-accountant
     * rejection on the GIFMIS side.
     */
    public function downloadGifmis(PayrollRun $run, \App\Services\Payroll\Gifmis\GifmisJournalExporter $exporter): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $run);

        try {
            $contents = $exporter->preview($run);
        } catch (\RuntimeException $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain']);
        }

        $filename = sprintf('GIFMIS-JV-%s.csv', $run->reference);

        return response($contents, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
