<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
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
            'avatar' => ['required', 'image', 'max:4096', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}
