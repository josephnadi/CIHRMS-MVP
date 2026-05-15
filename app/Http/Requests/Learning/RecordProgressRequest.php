<?php

namespace App\Http\Requests\Learning;

use App\Models\Enrolment;
use Illuminate\Foundation\Http\FormRequest;

class RecordProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enrolment = $this->route('enrolment');
        if (! $enrolment instanceof Enrolment) return false;

        // Employees update their own enrolment progress; HR/LD can update any.
        return $this->user()->hasPermission('learning.manage')
            || $this->user()->employee?->id === $enrolment->employee_id;
    }

    public function rules(): array
    {
        return [
            'progress_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'final_score'  => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
