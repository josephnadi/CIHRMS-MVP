<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceSummaryV1Resource;
use App\Models\AttendanceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'employee_id'   => ['nullable', 'integer', 'exists:employees,id'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date', 'after_or_equal:from'],
            'status'        => ['nullable', 'string'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $rows = AttendanceSummary::query()
            ->with('employee:id,employee_no')
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('summary_date', '>=', $v))
            ->when($request->to,   fn ($q, $v) => $q->whereDate('summary_date', '<=', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('summary_date')
            ->paginate($request->integer('per_page', 100));

        return AttendanceSummaryV1Resource::collection($rows);
    }
}
