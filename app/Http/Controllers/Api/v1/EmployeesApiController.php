<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only Employees API v1.
 * Sanctum-authenticated. Scoped via `employees.view` permission OR
 * RBAC scope (only employees in departments the user manages).
 */
class EmployeesApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('employees.view'), 403);

        return EmployeeResource::collection(
            Employee::visibleTo($request->user())
                ->with(['user', 'department', 'currentGrade'])
                ->when($request->department_id, fn ($q, $v) => $q->where('department_id', $v))
                ->when($request->q, function ($q, $v) {
                    $q->where(fn ($qq) => $qq->where('employee_no', 'like', "%{$v}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")));
                })
                ->paginate((int) min($request->per_page ?? 25, 100))
        );
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        abort_unless($request->user()->hasPermission('employees.view'), 403);
        abort_unless(Employee::visibleTo($request->user())->whereKey($employee->id)->exists(), 404);

        return new EmployeeResource(
            $employee->load(['user', 'department', 'currentGrade', 'currentPosition', 'manager.user']),
        );
    }
}
