<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');
        return $member !== null && $this->user()?->can('update', $member) === true;
    }

    public function rules(): array
    {
        $member = $this->route('member');
        $id     = is_object($member) ? $member->id : $member;

        return [
            'class'             => ['sometimes', new Enum(MemberClass::class)],
            'status'            => ['sometimes', new Enum(MemberStatus::class)],
            'name'              => ['sometimes', 'string', 'max:200'],
            'email'             => [
                'sometimes', 'nullable', 'email', 'max:200',
                Rule::unique('members', 'email')->ignore($id)->whereNull('deleted_at'),
            ],
            'phone'             => ['sometimes', 'nullable', 'string', 'max:30'],
            'address'           => ['sometimes', 'nullable', 'string', 'max:1000'],
            'date_of_birth'     => ['sometimes', 'nullable', 'date', 'before:today'],
            'chartered_at'      => ['sometimes', 'nullable', 'date'],
            'lapsed_at'         => ['sometimes', 'nullable', 'date'],
            'notes'             => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
