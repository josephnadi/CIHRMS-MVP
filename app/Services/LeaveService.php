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
        $leave = LeaveRequest::create($request->validated());

        event(new LeaveRequested($leave, $request->user()));

        return $leave;
    }

    public function updateStatus(UpdateLeaveStatusRequest $request, LeaveRequest $leaveRequest): LeaveRequest
    {
        $status = LeaveStatus::from($request->validated('status'));

        DB::transaction(function () use ($status, $leaveRequest, $request) {
            $leaveRequest->update([
                'status'      => $status,
                'approved_by' => $status === LeaveStatus::Approved ? $request->user()->id : null,
            ]);

            if ($status === LeaveStatus::Approved) {
                $balance = LeaveBalance::lockForUpdate()->firstOrCreate(
                    [
                        'employee_id' => $leaveRequest->employee_id,
                        'type'        => $leaveRequest->type->value,
                        'year'        => $leaveRequest->start_date->year,
                    ],
                    ['total_days' => 21.0, 'used_days' => 0.0]
                );
                $balance->increment('used_days', $leaveRequest->durationInDays());
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
