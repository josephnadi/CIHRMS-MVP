<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentAnnotationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AnnotateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('annotate', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'type'     => ['required', new Enum(DocumentAnnotationType::class)],
            'page'     => ['required', 'integer', 'min:1'],
            'x_pct'    => ['required', 'numeric', 'between:0,100'],
            'y_pct'    => ['required', 'numeric', 'between:0,100'],
            'w_pct'    => ['nullable', 'numeric', 'between:0.5,100'],
            'h_pct'    => ['nullable', 'numeric', 'between:0.5,100'],
            'rotation' => ['nullable', 'integer', 'between:-180,180'],
            'data'     => ['required', 'array'],
            'data.png_base64' => ['nullable', 'string'],
            'data.svg'        => ['nullable', 'string'],
            'data.text'       => ['nullable', 'string', 'max:500'],
            'data.color'      => ['nullable', 'string', 'max:9'],
        ];
    }
}
