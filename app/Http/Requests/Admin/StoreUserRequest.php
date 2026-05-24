<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('users.manage');
    }

    public function rules(): array
    {
        return [
            // Account
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'staff_id' => ['required', 'string', 'max:64', Rule::unique(User::class, 'staff_id')],
            'role'     => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            // Privileged roles default to true; the form auto-checks them but
            // the operator can still toggle for non-privileged hires.
            'two_factor_required' => ['nullable', 'boolean'],

            // Employee profile — required so the account can actually use the
            // HR features (attendance, leave, profile). Every controller that
            // touches an HR module reads $user->employee; admin-created users
            // without an Employee row 404 on those pages.
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position'      => ['required', 'string', 'max:120'],
            'hire_date'     => ['required', 'date'],
            'phone'         => ['nullable', 'string', 'max:32'],
            'employee_no'   => ['nullable', 'string', 'max:32', Rule::unique(Employee::class, 'employee_no')],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower($this->email)]);
        }
    }
}
