<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
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
use App\Models\BenefitPlan;
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
    public function __construct(
        private readonly EmployeeIdentifierService $ids = new EmployeeIdentifierService(),
        private readonly ?BenefitsService $benefits = null,
    ) {}

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
                    'staff_id' => $data['staff_id'] ?? $this->ids->nextStaffId(),
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

            $employeeData = collect($data)->except([
                'create_user', 'user_name', 'user_email',
                'user_role', 'user_password', 'staff_id',
                'benefit_plan_ids',
            ])->all();

            if (empty($employeeData['employee_no'])) {
                $employeeData['employee_no'] = $this->ids->nextEmployeeNo();
            }

            $employee = Employee::create(array_merge($employeeData, ['user_id' => $userId]));

            // Enrol the new employee in any selected benefit plans. Premium is
            // derived from the plan's contribution % so HR doesn't have to enter
            // it manually for each new hire.
            $planIds = $data['benefit_plan_ids'] ?? [];
            if ($this->benefits && ! empty($planIds)) {
                $effective = $employee->hire_date ?? now();
                foreach (BenefitPlan::whereIn('id', $planIds)->active()->get() as $plan) {
                    $this->benefits->enrol($plan, $employee, $effective, actor: $request->user());
                }
            }

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

    /**
     * Aggregate workforce statistics for the Employees dashboard band.
     * Honours the same RBAC visibility scope as list(), so a dept_head sees
     * their slice and HR/super_admin see the whole institute.
     */
    public function stats(Request $request): array
    {
        $base = fn () => Employee::query()->visibleTo($request->user());

        $statusRows = $base()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $statusBreakdown = [
            'active'     => (int) ($statusRows[EmployeeStatus::Active->value]     ?? 0),
            'on_leave'   => (int) ($statusRows[EmployeeStatus::OnLeave->value]    ?? 0),
            'inactive'   => (int) ($statusRows[EmployeeStatus::Inactive->value]   ?? 0),
            'terminated' => (int) ($statusRows[EmployeeStatus::Terminated->value] ?? 0),
        ];
        $total = array_sum($statusBreakdown);

        $genderRows = $base()
            ->selectRaw("COALESCE(NULLIF(LOWER(gender), ''), 'unspecified') as g, COUNT(*) as count")
            ->groupBy('g')
            ->pluck('count', 'g');

        $genderBreakdown = [
            'male'        => (int) ($genderRows['male']   ?? $genderRows['m'] ?? 0),
            'female'      => (int) ($genderRows['female'] ?? $genderRows['f'] ?? 0),
            'unspecified' => $total
                - (int) ($genderRows['male']   ?? $genderRows['m'] ?? 0)
                - (int) ($genderRows['female'] ?? $genderRows['f'] ?? 0),
        ];

        $topRows = $base()
            ->selectRaw('department_id, COUNT(*) as count')
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->orderByDesc('count')
            ->limit(6)
            ->get();

        $deptLookup = Department::whereIn('id', $topRows->pluck('department_id'))
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        $topDepartments = $topRows->map(fn ($row) => [
            'id'    => $row->department_id,
            'name'  => optional($deptLookup[$row->department_id] ?? null)->name ?? '—',
            'code'  => optional($deptLookup[$row->department_id] ?? null)->code ?? '',
            'count' => (int) $row->count,
        ])->values()->all();

        $assignedToTop  = array_sum(array_column($topDepartments, 'count'));
        $remainder      = max(0, $total - $assignedToTop);

        $now = now();
        $tenureBreakdown = [
            'under_1y'        => (clone $base())->where('hire_date', '>=', $now->copy()->subYear())->count(),
            'one_to_three'    => (clone $base())->whereBetween('hire_date', [$now->copy()->subYears(3), $now->copy()->subYear()])->count(),
            'three_to_five'   => (clone $base())->whereBetween('hire_date', [$now->copy()->subYears(5), $now->copy()->subYears(3)])->count(),
            'over_five'       => (clone $base())->where('hire_date', '<', $now->copy()->subYears(5))->count(),
        ];

        $recentHires30d = $base()->where('hire_date', '>=', $now->copy()->subDays(30))->count();

        return [
            'total'             => $total,
            'status'            => $statusBreakdown,
            'gender'            => $genderBreakdown,
            'top_departments'   => $topDepartments,
            'other_departments' => $remainder,
            'departments_count' => Department::count(),
            'tenure'            => $tenureBreakdown,
            'recent_hires_30d'  => $recentHires30d,
        ];
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
            'benefitEnrolments.plan',
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
