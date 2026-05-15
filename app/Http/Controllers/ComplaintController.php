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
        return Inertia::render('Complaints/Index', [
            'complaints'   => ComplaintResource::collection($this->complaints->list($request->status)),
            'filters'      => $request->only(['status']),
            'activeModule' => 'governance',
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
