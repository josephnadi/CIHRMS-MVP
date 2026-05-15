<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $user     = $this->user();

        // HR can edit anyone; dept heads can edit employees in their dept;
        // an employee can edit a narrow set of their own fields.
        if ($user->hasPermission('employees.manage'))               return true;
        if ($user->managesDepartment($employee?->department_id))    return true;
        if ($employee?->user_id === $user->id)                      return true;

        return false;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'manager_id'    => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'employee_no'   => ['sometimes', 'string', 'max:50',
                                Rule::unique('employees', 'employee_no')->ignore($this->route('employee')?->id)],
            'position'      => ['sometimes', 'string', 'max:255'],
            'hire_date'     => ['sometimes', 'date', 'before_or_equal:today'],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:20'],
            'status'        => ['sometimes', Rule::enum(EmployeeStatus::class)],

            // Personal — self-editable
            'gender'        => ['sometimes', 'nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'national_id'   => ['sometimes', 'nullable', 'string', 'max:64'],
            'address'       => ['sometimes', 'nullable', 'string', 'max:255'],

            // Emergency — self-editable
            'emergency_contact_name'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone'        => ['sometimes', 'nullable', 'string', 'max:32'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:64'],

            // Bank — self-editable
            'bank_name'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account'  => ['sometimes', 'nullable', 'string', 'max:64'],

            // Salary — gated
            'salary' => [
                'sometimes', 'nullable', 'numeric', 'min:0',
                function ($attr, $val, $fail) {
                    if ($val !== null && ! $this->user()->hasPermission('employees.view_salary')) {
                        $fail('You do not have permission to edit salary.');
                    }
                },
            ],
        ];
    }
}
