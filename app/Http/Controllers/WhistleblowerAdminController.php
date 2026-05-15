<?php

namespace App\Http\Controllers;

use App\Enums\WhistleblowerSeverity;
use App\Enums\WhistleblowerStatus;
use App\Http\Requests\Whistleblower\LogActionRequest;
use App\Http\Requests\Whistleblower\PostMessageRequest;
use App\Http\Requests\Whistleblower\TriageReportRequest;
use App\Http\Resources\WhistleblowerReportResource;
use App\Models\User;
use App\Models\WhistleblowerReport;
use App\Services\Whistleblower\WhistleblowerInvestigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Investigator dashboard. Access is segregated: holders of
 * `whistleblower.investigate` see only cases assigned to them; holders of
 * `whistleblower.manage` see everything; `whistleblower.view_all` is the
 * read-only auditor lane.
 */
class WhistleblowerAdminController extends Controller
{
    public function __construct(private readonly WhistleblowerInvestigationService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WhistleblowerReport::class);

        $user = $request->user();
        $manages = $user->hasPermission('whistleblower.manage')
                || $user->hasPermission('whistleblower.view_all');

        $reports = WhistleblowerReport::query()
            ->with('investigator:id,name')
            ->when(! $manages, fn ($q) => $q->where('assigned_investigator_id', $user->id))
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->when($request->severity, fn ($q, $v) => $q->where('severity', $v))
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->latest('received_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'open_total'    => WhistleblowerReport::open()->count(),
            'awaiting_triage' => WhistleblowerReport::where('status', WhistleblowerStatus::Submitted->value)->count(),
            'critical_open' => WhistleblowerReport::open()->where('severity', WhistleblowerSeverity::Critical->value)->count(),
            'closed_ytd'    => WhistleblowerReport::whereYear('closed_at', now()->year)->count(),
        ];

        return Inertia::render('Whistleblower/Admin/Index', [
            'reports'      => WhistleblowerReportResource::collection($reports),
            'stats'        => $stats,
            'filters'      => $request->only(['status', 'severity', 'category']),
            'activeModule' => 'whistleblower',
        ]);
    }

    public function show(WhistleblowerReport $report): Response
    {
        $this->authorize('view', $report);

        $report->load([
            'investigator', 'triager', 'closer',
            'subjects.linkedEmployee',
            'evidence.uploader',
            'actions.investigator',
            'messages.poster',
        ]);

        return Inertia::render('Whistleblower/Admin/Show', [
            'report'       => new WhistleblowerReportResource($report),
            'investigators' => User::whereIn('role', ['super_admin', 'auditor', 'hr_admin'])
                ->orderBy('name')->get(['id', 'name', 'role']),
            'activeModule' => 'whistleblower',
        ]);
    }

    public function triage(TriageReportRequest $request, WhistleblowerReport $report): RedirectResponse
    {
        $this->authorize('triage', $report);

        $assignee = $request->validated('assigned_investigator_id')
            ? User::find($request->validated('assigned_investigator_id'))
            : null;

        try {
            $this->service->triage(
                report:        $report,
                investigator:  $request->user(),
                severity:      WhistleblowerSeverity::from($request->validated('severity')),
                assignTo:      $assignee,
                notes:         $request->validated('notes'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Case {$report->case_number} triaged.");
    }

    public function logAction(LogActionRequest $request, WhistleblowerReport $report): RedirectResponse
    {
        $this->authorize('act', $report);

        $this->service->logAction(
            report:        $report,
            investigator:  $request->user(),
            type:          \App\Enums\InvestigationActionType::from($request->validated('action_type')),
            notes:         $request->validated('notes'),
            meta:          $request->validated('meta') ?? [],
        );

        if ($request->validated('new_status')) {
            try {
                $this->service->changeStatus(
                    report:         $report->fresh(),
                    investigator:   $request->user(),
                    newStatus:      WhistleblowerStatus::from($request->validated('new_status')),
                    closureSummary: $request->validated('closure_summary'),
                );
            } catch (\DomainException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        return back()->with('success', 'Investigation action logged.');
    }

    public function postMessage(PostMessageRequest $request, WhistleblowerReport $report): RedirectResponse
    {
        $this->authorize('act', $report);

        $this->service->postMessageToSubmitter(
            report:       $report,
            investigator: $request->user(),
            body:         (string) $request->validated('body'),
        );

        return back()->with('success', 'Message posted. The submitter can read it via their tracking code.');
    }

    public function assign(Request $request, WhistleblowerReport $report): RedirectResponse
    {
        $this->authorize('act', $report);
        $data = $request->validate(['investigator_id' => 'required|integer|exists:users,id']);

        $assignee = User::findOrFail($data['investigator_id']);
        $this->service->assignInvestigator($report, $request->user(), $assignee);

        return back()->with('success', "Reassigned to {$assignee->name}.");
    }
}
