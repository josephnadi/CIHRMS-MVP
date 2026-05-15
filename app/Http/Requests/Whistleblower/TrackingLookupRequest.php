<?php

namespace App\Http\Requests\Whistleblower;

use Illuminate\Foundation\Http\FormRequest;

class TrackingLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Allow either with or without separators; service normalises before hashing.
            'tracking_code' => ['required', 'string', 'min:8', 'max:32'],
        ];
    }
}
