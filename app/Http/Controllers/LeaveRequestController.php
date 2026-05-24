<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateLeaveStatusRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveService $leaves) {}

    public function index(Request $request): Response
    {
        $user     = $request->user();
        $employee = $user->employee;

        // HR / approvers get the pending-approval badge and the employee
        // picker for the org-wide filter. Self-service users get 0 / [].
        $canApprove = $user->hasPermission('leave.approve')
            || $user->hasPermission('leave.manage');

        $pendingCount = $canApprove ? LeaveRequest::pending()->count() : 0;

        $employees = $canApprove
            ? Employee::with('user:id,name')
                ->orderBy('employee_no')
                ->get(['id', 'user_id', 'employee_no'])
                ->map(fn ($e) => [
                    'id'          => $e->id,
                    'employee_no' => $e->employee_no,
                    'name'        => $e->user?->name ?? $e->employee_no,
                ])
                ->values()
            : [];

        return Inertia::render('Leave/Index', [
            'leaves'        => LeaveRequestResource::collection($this->leaves->list($request)),
            'balances'      => $employee
                ? LeaveBalanceResource::collection($this->leaves->balances($employee->id, now()->year))
                : [],
            'pendingCount'  => $pendingCount,
            'employees'     => $employees,
            'filters'       => $request->only(['status', 'employee_id', 'type', 'from', 'to']),
            'activeModule'  => 'leave',
        ]);
    }

    public function show(LeaveRequest $leaveRequest): Response
    {
        return Inertia::render('Leave/Show', [
            'leaveRequest' => new LeaveRequestResource($this->leaves->find($leaveRequest->id)),
            'activeModule' => 'leave',
        ]);
    }

    public function store(StoreLeaveRequest $request): RedirectResponse
    {
        $this->leaves->request($request);

        return back()->with('success', 'Leave request submitted successfully.');
    }

    public function updateStatus(UpdateLeaveStatusRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leaves->updateStatus($request, $leaveRequest);

        return back()->with('success', 'Leave status updated.');
    }

    /**
     * Cancel (withdraw) a still-pending leave request. The requester can
     * cancel their own; HR with leave.manage can cancel anyone's. Approved
     * requests can't be cancelled from here — those go through reversal.
     */
    public function destroy(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('cancel', $leaveRequest);

        $leaveRequest->delete();

        return back()->with('success', 'Leave request withdrawn.');
    }
}
