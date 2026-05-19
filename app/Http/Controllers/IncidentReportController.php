<?php

namespace App\Http\Controllers;

use App\Http\Requests\IncidentReport\AssignIncidentReportRequest;
use App\Http\Requests\IncidentReport\CloseIncidentReportRequest;
use App\Http\Requests\IncidentReport\StoreIncidentMessageRequest;
use App\Http\Requests\IncidentReport\StoreIncidentReportRequest;
use App\Http\Requests\IncidentReport\UpdateIncidentReportRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\IncidentReport;
use App\Models\IncidentReportAttachment;
use App\Models\User;
use App\Services\IncidentReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function __construct(private readonly IncidentReportService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Governance/Incidents/Index', [
            'reports'      => IncidentReportResource::collection($this->service->list($request)),
            'reviewers'    => $this->service->eligibleReviewers(),
            'filters'      => $request->only(['category', 'status', 'q']),
            'activeModule' => 'governance',
        ]);
    }

    public function show(IncidentReport $report): Response
    {
        $this->authorize('view', $report);
        $report->load(['employee.user', 'currentAssignees', 'messages.author', 'messages.attachments', 'attachments']);

        return Inertia::render('Governance/Incidents/Show', [
            'report'       => new IncidentReportResource($report),
            'reviewers'    => $this->service->eligibleReviewers(),
            'activeModule' => 'governance',
        ]);
    }

    public function store(StoreIncidentReportRequest $request): RedirectResponse
    {
        $report = $this->service->create(
            $request->user(),
            $request->validated(),
            (array) $request->file('attachments', []),
        );
        return redirect()->route('incidents.show', $report)->with('success', 'Your report has been submitted privately.');
    }

    public function update(UpdateIncidentReportRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('update', $report);
        $this->service->update($report, $request->validated());
        return back()->with('success', 'Report updated.');
    }

    public function assign(AssignIncidentReportRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('assign', $report);
        $this->service->assign($report, (int) $request->validated('user_id'), $request->user());
        return back()->with('success', 'Reviewer assigned.');
    }

    public function unassign(IncidentReport $report, User $user, Request $request): RedirectResponse
    {
        $this->authorize('assign', $report);
        $this->service->unassign($report, $user->id, $request->user());
        return back()->with('success', 'Reviewer removed.');
    }

    public function postMessage(StoreIncidentMessageRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('postMessage', $report);
        $this->service->postMessage(
            $report,
            $request->user(),
            $request->validated(),
            (array) $request->file('attachments', []),
        );
        return back()->with('success', 'Reply posted.');
    }

    public function close(CloseIncidentReportRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('close', $report);
        $this->service->close($report, $request->user(), $request->validated('resolution_note'));
        return back()->with('success', 'Report closed.');
    }

    public function reopen(IncidentReport $report, Request $request): RedirectResponse
    {
        $this->authorize('close', $report); // reopen permission == close permission (current assignees)
        $this->service->reopen($report, $request->user());
        return back()->with('success', 'Report reopened.');
    }

    public function downloadAttachment(IncidentReportAttachment $attachment): StreamedResponse
    {
        $this->authorize('downloadAttachment', $attachment);
        return Storage::disk('incidents')->download($attachment->file_path, $attachment->original_name);
    }
}
