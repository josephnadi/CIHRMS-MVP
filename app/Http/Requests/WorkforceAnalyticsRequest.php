<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkforceAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces permission:workforce.analytics.view
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
