<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeV1Resource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status'        => ['nullable', 'string'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $employees = Employee::query()
            ->with(['user:id,name,email', 'department:id,name,code', 'currentGrade:id,code'])
            ->when($request->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->status,        fn ($q, $v) => $q->where('status', $v))
            ->orderBy('employee_no')
            ->paginate($request->integer('per_page', 50));

        return EmployeeV1Resource::collection($employees);
    }

    public function show(Employee $employee): EmployeeV1Resource
    {
        $employee->load(['user', 'department', 'currentGrade']);
        return new EmployeeV1Resource($employee);
    }
}
