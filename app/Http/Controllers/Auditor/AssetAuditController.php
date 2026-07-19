<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auditor;

use App\Enums\AssetAuditAction;
use App\Enums\AssetAuditResult;
use App\Enums\AssetAuditStatus;
use App\Enums\AssetCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\CancelAssetAuditRequest;
use App\Http\Requests\Assets\CompleteAssetAuditRequest;
use App\Http\Requests\Assets\CountAssetAuditLineRequest;
use App\Http\Requests\Assets\ResolveAssetAuditLineRequest;
use App\Http\Requests\Assets\StoreAssetAuditRequest;
use App\Http\Resources\AssetAuditResource;
use App\Models\AssetAudit;
use App\Models\AssetAuditLine;
use App\Services\AssetAuditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetAuditController extends Controller
{
    public function __construct(private readonly AssetAuditService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status']);
        $q = AssetAudit::query()->withCount('lines');
        if (! empty($filters['status'])) $q->where('status', $filters['status']);

        return Inertia::render('Auditor/AssetAudits/Index', [
            'activeModule' => 'auditor-asset-audits',
            'audits'       => AssetAuditResource::collection($q->orderByDesc('created_at')->paginate(50)->withQueryString()),
            'filters'      => $filters,
            'statuses'     => collect(AssetAuditStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Auditor/AssetAudits/Create', [
            'activeModule' => 'auditor-asset-audits',
            'categories'   => collect(AssetCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    public function show(AssetAudit $assetAudit, Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('asset_audits.view'), 403);
        $assetAudit->load(['lines.asset', 'lines.expectedHolder.user', 'events.actor']);

        return Inertia::render('Auditor/AssetAudits/Show', [
            'activeModule' => 'auditor-asset-audits',
            'audit'        => (new AssetAuditResource($assetAudit))->resolve(),
            'resultOptions'=> collect(AssetAuditResult::cases())->reject(fn ($r) => $r === AssetAuditResult::Pending)
                                ->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'can'          => ['manage' => $request->user()->hasPermission('asset_audits.manage')],
        ]);
    }

    public function store(StoreAssetAuditRequest $request): RedirectResponse
    {
        $audit = $this->service->open($request->validated(), $request->user());
        return redirect()->route('auditor.asset-audits.show', $audit->id)->with('success', 'Audit opened — assets snapshotted.');
    }

    public function count(CountAssetAuditLineRequest $request, AssetAudit $assetAudit, AssetAuditLine $line): RedirectResponse
    {
        abort_unless($line->asset_audit_id === $assetAudit->id, 404);
        try {
            $this->service->count($line, AssetAuditResult::from($request->validated('result')), $request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Line counted.');
    }

    public function resolve(ResolveAssetAuditLineRequest $request, AssetAudit $assetAudit, AssetAuditLine $line): RedirectResponse
    {
        abort_unless($line->asset_audit_id === $assetAudit->id, 404);
        try {
            $this->service->applyResolution($line, AssetAuditAction::from($request->validated('action')), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Resolution applied.');
    }

    public function complete(CompleteAssetAuditRequest $request, AssetAudit $assetAudit): RedirectResponse
    {
        try {
            $this->service->complete($assetAudit, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Audit completed.');
    }

    public function cancel(CancelAssetAuditRequest $request, AssetAudit $assetAudit): RedirectResponse
    {
        try {
            $this->service->cancel($assetAudit, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Audit cancelled.');
    }
}
