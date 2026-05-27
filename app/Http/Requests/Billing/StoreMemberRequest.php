<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Member::class) === true;
    }

    public function rules(): array
    {
        return [
            'class'             => ['required', new Enum(MemberClass::class)],
            'status'            => ['sometimes', new Enum(MemberStatus::class)],
            'name'              => ['required', 'string', 'max:200'],
            'email'             => ['nullable', 'email', 'max:200', Rule::unique('members', 'email')->whereNull('deleted_at')],
            'phone'             => ['nullable', 'string', 'max:30'],
            'address'           => ['nullable', 'string', 'max:1000'],
            'date_of_birth'     => ['nullable', 'date', 'before:today'],
            'ghana_card_number' => ['nullable', 'string', 'max:32'],
            'chartered_at'      => ['nullable', 'date'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ];
    }
}
