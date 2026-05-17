<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\EmployeeCreated;
use App\Http\Requests\Employee\StoreDepartmentRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Employee\UploadDocumentRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeSkill;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeeService
{
    public function create(StoreEmployeeRequest $request): Employee
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $userId = $data['user_id'] ?? null;

            // Inline user creation (HR creates the login + employee in one go).
            if (! empty($data['create_user'])) {
                $user = User::create([
                    'name'     => $data['user_name'],
                    'email'    => $data['user_email'],
                    'staff_id' => $data['staff_id'] ?? null,
                    'password' => Hash::make($data['user_password']),
                    'role'     => $data['user_role'],
                ]);

                // Attach DB-backed role pivot too.
                $role = Role::where('slug', $data['user_role'])->first();
                if ($role) {
                    $user->roles()->syncWithoutDetaching([
                        $role->id => ['department_id' => $data['department_id'] ?? null],
                    ]);
                }

                $userId = $user->id;
            }

            $employee = Employee::create(array_merge(
                collect($data)->except([
                    'create_user', 'user_name', 'user_email',
                    'user_role', 'user_password', 'staff_id',
                ])->all(),
                ['user_id' => $userId]
            ));

            event(new EmployeeCreated($employee, $request->user()));

            return $employee;
        });
    }

    public function uploadDocument(UploadDocumentRequest $request, Employee $employee): EmployeeDocument
    {
        $path = $request->file('document')->store('employee-documents', 'public');

        return $employee->documents()->create([
            'title'     => $request->validated('title'),
            'file_path' => $path,
            'mime_type' => $request->file('document')->getMimeType(),
        ]);
    }

    public function uploadAvatar(Employee $employee, UploadedFile $file): Employee
    {
        if ($employee->avatar_path) {
            Storage::disk('public')->delete($employee->avatar_path);
        }

        $path = $file->store('avatars', 'public');
        $employee->update(['avatar_path' => $path]);

        return $employee->fresh();
    }

    public function createDepartment(StoreDepartmentRequest $request): Department
    {
        return Department::create($request->validated());
    }

    public function updateDepartment(Department $department, array $attributes): Department
    {
        $department->update($attributes);
        return $department->fresh();
    }

    /**
     * Soft-delete a department. Refuses if any employees still belong to it
     * — the caller should re-assign first. Returns the count of employees
     * if the deletion is refused so the UI can surface a clear message.
     */
    public function deleteDepartment(Department $department): void
    {
        $employeeCount = Employee::where('department_id', $department->id)->count();
        if ($employeeCount > 0) {
            throw new \DomainException(
                "Cannot delete department: {$employeeCount} employee(s) still assigned. Re-assign them first."
            );
        }
        $department->delete();
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return Employee::with(['department', 'user', 'manager.user'])
            ->visibleTo($request->user())
            ->when($request->department_id, fn ($q, $v) => $q->inDepartment((int) $v))
            ->when($request->status,        fn ($q, $v) => $q->where('status', $v))
            ->when($request->search,        fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%"))
                  ->orWhere('employee_no', 'like', "%{$v}%")
                  ->orWhere('position',    'like', "%{$v}%");
            }))
            ->latest()
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function find(int $id): Employee
    {
        return Employee::with([
            'department', 'user', 'manager.user',
            'leaveRequests' => fn ($q) => $q->latest()->limit(8),
            'tickets'       => fn ($q) => $q->latest()->limit(8),
            'payments'      => fn ($q) => $q->latest()->limit(8),
            'documents',
            'skills',
            'reports.user',
        ])->findOrFail($id);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): Employee
    {
        $employee->update($request->validated());

        return $employee->fresh(['department', 'user', 'manager.user']);
    }

    public function listDepartments(): Collection
    {
        return Department::with('head:id,name')
            ->withCount(['employees as active_employee_count' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get();
    }

    public function addSkill(Employee $employee, array $data): EmployeeSkill
    {
        return $employee->skills()->create($data);
    }

    public function removeSkill(EmployeeSkill $skill): void
    {
        $skill->delete();
    }
}
