<?php

namespace App\Http\Requests\Complaint;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('complaints.create');
    }

    public function rules(): array
    {
        return [
            'submitted_by' => ['nullable', 'string', 'max:255'],
            'details'      => ['required', 'string', 'max:5000'],
        ];
    }
}
