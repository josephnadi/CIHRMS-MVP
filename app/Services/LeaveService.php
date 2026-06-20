<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Events\LeaveRequested;
use App\Events\LeaveStatusUpdated;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateLeaveStatusRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function request(StoreLeaveRequest $request): LeaveRequest
    {
        // `attachment` is an uploaded file, not a column — strip it from the
        // mass-assignment payload and store it separately on the private disk.
        $data = collect($request->validated())->except('attachment')->all();

        if ($request->hasFile('attachment')) {
            // Private 'local' disk (mirrors EmployeeService::uploadDocument):
            // never served via the public /storage/ symlink.
            $data['attachment_path'] = $request->file('attachment')->store('leave-attachments', 'local');
        }

        $leave = LeaveRequest::create($data);

        event(new LeaveRequested($leave, $request->user()));

        return $leave;
    }

    public function updateStatus(UpdateLeaveStatusRequest $request, LeaveRequest $leaveRequest): LeaveRequest
    {
        $status = LeaveStatus::from($request->validated('status'));

        $isDecision = in_array($status, [LeaveStatus::Approved, LeaveStatus::Rejected], true);

        DB::transaction(function () use ($status, $leaveRequest, $request, $isDecision) {
            $leaveRequest->update([
                'status'           => $status,
                'approved_by'      => $status === LeaveStatus::Approved ? $request->user()->id : null,
                // Persist the approver's decision note + timestamp. The UI sends
                // `comment` on approve/reject; it was previously dropped, leaving
                // the Status History block permanently empty.
                'decision_comment' => $isDecision ? $request->validated('comment') : null,
                'decided_at'       => $isDecision ? now() : null,
            ]);

            // Charge the leave against the employee's balance. The entitlement is
            // derived per type (not a flat 21), and the days charged use the type's
            // basis (working days for most, calendar days for maternity). Unpaid
            // leave has no paid entitlement, so it is not charged to a balance.
            $entitlement = $leaveRequest->type->defaultEntitlementDays();
            if ($status === LeaveStatus::Approved && $entitlement !== null) {
                $balance = LeaveBalance::lockForUpdate()->firstOrCreate(
                    [
                        'employee_id' => $leaveRequest->employee_id,
                        'type'        => $leaveRequest->type->value,
                        'year'        => $leaveRequest->start_date->year,
                    ],
                    ['total_days' => $entitlement, 'used_days' => 0.0]
                );
                $balance->increment('used_days', $leaveRequest->chargeableDays());
            }
        });

        event(new LeaveStatusUpdated($leaveRequest, $request->user()));

        return $leaveRequest;
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return LeaveRequest::with(['employee.user', 'approver'])
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->forEmployee((int) $v))
            ->when($request->type,        fn ($q, $v) => $q->where('type', $v))
            ->when($request->from,        fn ($q, $v) => $q->where('start_date', '>=', $v))
            ->when($request->to,          fn ($q, $v) => $q->where('end_date',   '<=', $v))
            ->latest()
            ->paginate($request->per_page ?? 20);
    }

    public function find(int $id): LeaveRequest
    {
        return LeaveRequest::with(['employee.user', 'approver'])->findOrFail($id);
    }

    public function balances(int $employeeId, int $year): Collection
    {
        return LeaveBalance::where('employee_id', $employeeId)
            ->where('year', $year)
            ->get();
    }
}
