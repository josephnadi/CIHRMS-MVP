<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class MoveAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moveAnnotation', $this->route('annotation')) === true;
    }

    public function rules(): array
    {
        return [
            'x_pct'    => ['sometimes', 'numeric', 'between:0,100'],
            'y_pct'    => ['sometimes', 'numeric', 'between:0,100'],
            'w_pct'    => ['sometimes', 'numeric', 'between:4,80'],
            'h_pct'    => ['sometimes', 'numeric', 'between:4,80'],
            'rotation' => ['sometimes', 'integer', 'between:-180,180'],
        ];
    }
}
