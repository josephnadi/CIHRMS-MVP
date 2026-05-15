<?php

namespace App\Http\Controllers;

use App\Http\Requests\Employee\StoreDepartmentRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\StoreSkillRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Employee\UploadAvatarRequest;
use App\Http\Requests\Employee\UploadDocumentRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Employees/Index', [
            'employees'    => EmployeeResource::collection($this->employees->list($request)),
            'departments'  => DepartmentResource::collection($this->employees->listDepartments()),
            'filters'      => $request->only(['search', 'department_id', 'status']),
            'activeModule' => 'employees',
        ]);
    }

    public function show(Employee $employee): Response
    {
        $this->authorize('view', $employee);

        return Inertia::render('Employees/Show', [
            'employee'     => new EmployeeResource($this->employees->find($employee->id)),
            'activeModule' => 'employees',
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $this->employees->create($request);

        return back()->with('success', 'Employee created successfully.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($request, $employee);

        return back()->with('success', 'Employee updated.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);
        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'Employee removed.');
    }

    public function uploadDocument(UploadDocumentRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->uploadDocument($request, $employee);

        return back()->with('success', 'Document uploaded successfully.');
    }

    public function uploadAvatar(UploadAvatarRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->uploadAvatar($employee, $request->file('avatar'));

        return back()->with('success', 'Profile photo updated.');
    }

    public function storeSkill(StoreSkillRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->addSkill($employee, $request->validated());

        return back()->with('success', 'Skill added.');
    }

    public function destroySkill(Employee $employee, EmployeeSkill $skill): RedirectResponse
    {
        abort_unless($skill->employee_id === $employee->id, 404);

        $this->employees->removeSkill($skill);

        return back()->with('success', 'Skill removed.');
    }

    public function storeDepartment(StoreDepartmentRequest $request): RedirectResponse
    {
        $this->employees->createDepartment($request);

        return back()->with('success', 'Department created successfully.');
    }

    public function departments(Request $request): Response
    {
        return Inertia::render('Departments/Index', [
            'departments'  => DepartmentResource::collection($this->employees->listDepartments()),
            'activeModule' => 'employees',
        ]);
    }
}
