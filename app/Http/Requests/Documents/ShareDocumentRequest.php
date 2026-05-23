<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\DocumentShareAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ShareDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('share', $this->route('document')) === true;
    }

    public function rules(): array
    {
        return [
            'audience_type' => ['required', new Enum(DocumentShareAudience::class)],
            'audience_id'   => [
                Rule::requiredIf(fn () => $this->input('audience_type') !== DocumentShareAudience::Organization->value),
                'nullable',
                'integer',
            ],
            'expires_at'    => ['nullable', 'date', 'after:now'],
        ];
    }
}
