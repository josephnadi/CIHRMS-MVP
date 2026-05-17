<?php

namespace App\Http\Controllers;

use App\Enums\DataSubjectRequestType;
use App\Http\Requests\Privacy\FulfillRequest;
use App\Http\Requests\Privacy\RejectRequest;
use App\Http\Requests\Privacy\SubmitDataSubjectRequest;
use App\Http\Resources\DataSubjectRequestResource;
use App\Models\DataSubjectRequest;
use App\Services\Privacy\DataSubjectRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrivacyController extends Controller
{
    public function __construct(private readonly DataSubjectRequestService $service) {}

    // ── Subject self-service ────────────────────────────────────────────

    public function myRequests(Request $request): Response
    {
        $reqs = DataSubjectRequest::where('subject_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return Inertia::render('Privacy/MyRequests', [
            'requests' => DataSubjectRequestResource::collection($reqs),
            'types' => collect(DataSubjectRequestType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'produces_export' => $t->producesExport(),
                'is_mutating' => $t->isMutating(),
            ]),
            'activeModule' => 'privacy',
        ]);
    }

    public function submit(SubmitDataSubjectRequest $request): RedirectResponse
    {
        $req = $this->service->submit(
            subject:               $request->user(),
            type:                  DataSubjectRequestType::from($request->validated('request_type')),
            statement:             (string) $request->validated('subject_statement'),
            rectificationDetails:  $request->validated('rectification_details'),
            objectionPurpose:      $request->validated('objection_purpose'),
        );

        return redirect()->route('privacy.my')
            ->with('success', "Request {$req->reference} submitted. We will respond within 30 days.");
    }

    public function withdraw(Request $request, DataSubjectRequest $req): RedirectResponse
    {
        $this->authorize('withdraw', $req);
        $this->service->withdraw($req, $request->user());

        return back()->with('success', "Request {$req->reference} withdrawn.");
    }

    public function downloadMyExport(Request $request, DataSubjectRequest $req): BinaryFileResponse
    {
        $this->authorize('downloadExport', $req);

        abort_unless($req->export_path && file_exists($req->export_path), 404);

        return response()->download($req->export_path, "{$req->reference}.zip");
    }

    // ── DPO admin queue ────────────────────────────────────────────────

    public function adminIndex(Request $request): Response
    {
        $this->authorize('viewAny', DataSubjectRequest::class);

        $reqs = DataSubjectRequest::query()
            ->with(['subject:id,name,email', 'assignee:id,name'])
            ->when($request->status,       fn ($q, $v) => $q->where('status', $v))
            ->when($request->request_type, fn ($q, $v) => $q->where('request_type', $v))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $open = DataSubjectRequest::open()->get(['id', 'submitted_at', 'target_completion_date']);

        $stats = [
            'open'          => $open->count(),
            'overdue'       => DataSubjectRequest::where('status', 'overdue')->count(),
            'this_month'    => DataSubjectRequest::whereMonth('submitted_at', now()->month)
                ->whereYear('submitted_at', now()->year)->count(),
            'fulfilled_ytd' => DataSubjectRequest::where('status', 'fulfilled')
                ->whereYear('completed_at', now()->year)->count(),
            'rejected_ytd'  => DataSubjectRequest::where('status', 'rejected')
                ->whereYear('completed_at', now()->year)->count(),
            'within_sla_pct' => (function () use ($open) {
                if ($open->isEmpty()) return 100;
                $within = $open->filter(fn ($r) =>
                    $r->target_completion_date && $r->target_completion_date->gte(now()->startOfDay())
                )->count();
                return (int) round(($within / $open->count()) * 100);
            })(),
            'avg_age_days'  => (int) round(
                $open->avg(fn ($r) => $r->submitted_at ? now()->diffInDays($r->submitted_at) : 0) ?? 0
            ),
            'total_all_time' => DataSubjectRequest::count(),
        ];

        // Composition by request type — feeds the analytical band
        $typeBreakdown = DataSubjectRequest::query()
            ->selectRaw('request_type, COUNT(*) as c')
            ->groupBy('request_type')
            ->pluck('c', 'request_type')
            ->all();

        // Composition by status — feeds the donut
        $statusBreakdown = DataSubjectRequest::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return Inertia::render('Privacy/Admin/Index', [
            'requests'         => DataSubjectRequestResource::collection($reqs),
            'stats'            => $stats,
            'typeBreakdown'    => $typeBreakdown,
            'statusBreakdown'  => $statusBreakdown,
            'filters'          => $request->only(['status', 'request_type']),
            'activeModule'     => 'privacy-admin',
        ]);
    }

    public function adminShow(DataSubjectRequest $req): Response
    {
        $this->authorize('view', $req);
        $req->load(['subject', 'assignee', 'decider']);

        return Inertia::render('Privacy/Admin/Show', [
            'request'      => new DataSubjectRequestResource($req),
            'activeModule' => 'privacy-admin',
        ]);
    }

    public function acknowledge(Request $request, DataSubjectRequest $req): RedirectResponse
    {
        $this->authorize('fulfill', $req);

        try {
            $this->service->acknowledge($req, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Request {$req->reference} acknowledged.");
    }

    public function fulfill(FulfillRequest $request, DataSubjectRequest $req): RedirectResponse
    {
        // Erasure-type fulfilments require the higher gate.
        if ($req->request_type->value === 'erasure') {
            $this->authorize('erase', $req);
        } else {
            $this->authorize('fulfill', $req);
        }

        try {
            $this->service->fulfill($req, $request->user(), (string) $request->validated('summary'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Request {$req->reference} fulfilled.");
    }

    public function reject(RejectRequest $request, DataSubjectRequest $req): RedirectResponse
    {
        $this->authorize('fulfill', $req);

        try {
            $this->service->reject(
                req:             $req,
                dpo:             $request->user(),
                statutoryBasis:  (string) $request->validated('statutory_basis'),
                summary:         (string) $request->validated('summary'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Request {$req->reference} rejected.");
    }
}
