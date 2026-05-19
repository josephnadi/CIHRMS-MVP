<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActOnRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('act', [
            $this->route('document'),
            $this->route('route'),
        ]);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['complete', 'reject'])],
            'comment'  => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('decision') === 'reject')],
        ];
    }
}
