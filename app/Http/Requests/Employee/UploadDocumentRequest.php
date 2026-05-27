<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $user     = $this->user();
        if (! $user || ! $employee) return false;

        // HR can upload to anyone; a dept head to anyone in their department;
        // an employee to their own record. No more "any user with
        // employees.manage can impersonate any employee" — H9 audit fix.
        if ($user->hasPermission('employees.manage'))             return true;
        if ($user->managesDepartment($employee->department_id))   return true;
        return $employee->user_id === $user->id;
    }

    public function rules(): array
    {
        return [
            'title'    => ['required', 'string', 'max:255'],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ];
    }
}
