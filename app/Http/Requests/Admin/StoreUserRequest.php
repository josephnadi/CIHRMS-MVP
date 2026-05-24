<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
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
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'staff_id' => ['required', 'string', 'max:64', Rule::unique(User::class, 'staff_id')],
            'role'     => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            // Privileged roles default to true; the form auto-checks them but
            // the operator can still toggle for non-privileged hires.
            'two_factor_required' => ['nullable', 'boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower($this->email)]);
        }
    }
}
