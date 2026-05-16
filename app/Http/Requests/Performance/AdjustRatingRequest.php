<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class AdjustRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.calibrate');
    }

    public function rules(): array
    {
        return [
            'review_id'       => ['required', 'integer', 'exists:reviews,id'],
            'adjusted_rating' => ['required', 'numeric', 'min:1', 'max:5'],
            'reason'          => ['nullable', 'string', 'max:1000'],
        ];
    }
}
