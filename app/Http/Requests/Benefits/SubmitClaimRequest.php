<?php

declare(strict_types=1);

namespace App\Http\Requests\Benefits;

use Illuminate\Foundation\Http\FormRequest;

class SubmitClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('benefits.claim') ?? false;
    }

    public function rules(): array
    {
        return [
            'enrolment_id' => ['required', 'integer', 'exists:benefit_enrolments,id'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'currency'     => ['nullable', 'string', 'size:3'],
            'claim_date'   => ['nullable', 'date', 'before_or_equal:today'],
            'description'  => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
