<?php

namespace App\Http\Controllers;

use App\Http\Requests\Complaint\StoreComplaintRequest;
use App\Http\Requests\Complaint\UpdateComplaintStatusRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    public function index(Request $request): Response
    {
        // The page is reachable by anyone with complaints.create (to submit /
        // track by reference), but the complaint QUEUE and the investigator list
        // are manager-only. Complaints aren't tied to a submitting account
        // (submitted_by is free-text / "anonymous"), so non-managers receive an
        // empty list — never other people's complaints — rather than a scoped one.
        $canManage = $request->user()?->hasPermission('complaints.manage') === true;

        $investigators = $canManage
            ? \App\Models\User::query()
                ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'complaints.manage'))
                ->orWhereIn('role', ['super_admin', 'ceo', 'hr_admin'])
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(50)
                ->get()
            : collect();

        $complaints = $canManage
            ? $this->complaints->list($request->status)
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);

        return Inertia::render('Complaints/Index', [
            'complaints'    => ComplaintResource::collection($complaints),
            'filters'       => $request->only(['status']),
            'investigators' => $investigators,
            'activeModule'  => 'governance',
        ]);
    }

    public function store(StoreComplaintRequest $request): RedirectResponse
    {
        $complaint = $this->complaints->create($request);

        return back()->with('success', "Complaint submitted. Reference: {$complaint->reference}");
    }

    public function updateStatus(UpdateComplaintStatusRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->complaints->updateStatus($request, $complaint);

        return back()->with('success', 'Complaint status updated.');
    }

    public function track(Request $request): Response
    {
        $reference = $request->input('reference');
        $complaint = $reference ? $this->complaints->track($reference) : null;

        return Inertia::render('Complaints/Track', [
            'complaint' => $complaint ? new ComplaintResource($complaint) : null,
            'reference' => $reference,
        ]);
    }
}
