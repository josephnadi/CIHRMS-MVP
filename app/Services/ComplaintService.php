<?php

namespace App\Services;

use App\Enums\ComplaintStatus;
use App\Http\Requests\Complaint\StoreComplaintRequest;
use App\Http\Requests\Complaint\UpdateComplaintStatusRequest;
use App\Models\Complaint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class ComplaintService
{
    public function create(StoreComplaintRequest $request): Complaint
    {
        return Complaint::create([
            'reference'    => $this->generateReference(),
            'submitted_by' => $request->validated('submitted_by') ?: 'anonymous',
            'details'      => $request->validated('details'),
        ]);
    }

    public function updateStatus(UpdateComplaintStatusRequest $request, Complaint $complaint): Complaint
    {
        $updates = [];
        if ($request->filled('status')) {
            $updates['status'] = ComplaintStatus::from($request->validated('status'));
        }
        if ($request->has('assigned_to')) {
            // explicit null is permitted (un-assign)
            $updates['assigned_to'] = $request->validated('assigned_to');
        }
        if (! empty($updates)) {
            $complaint->update($updates);
        }

        return $complaint;
    }

    public function list(string $status = null): LengthAwarePaginator
    {
        return Complaint::with('assignee:id,name')
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(20);
    }

    public function track(string $reference): ?Complaint
    {
        return Complaint::where('reference', $reference)->first();
    }

    private function generateReference(): string
    {
        do {
            $ref = 'CMP-'.strtoupper(Str::random(8));
        } while (Complaint::where('reference', $ref)->exists());

        return $ref;
    }
}
