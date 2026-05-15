<?php

namespace App\Http\Requests\Employee;

use App\Models\EmployeeSkill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $user     = $this->user();

        if ($user->hasPermission('employees.manage'))            return true;
        if ($user->managesDepartment($employee?->department_id)) return true;
        return $employee?->user_id === $user->id;
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:128'],
            'level'      => ['nullable', Rule::in(EmployeeSkill::LEVELS)],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
