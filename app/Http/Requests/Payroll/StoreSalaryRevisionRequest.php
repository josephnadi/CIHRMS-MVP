<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalaryRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('payroll.run');
    }

    public function rules(): array
    {
        return [
            'percentage'   => ['required', 'numeric', 'min:-100', 'max:1000'],
            'effective_from' => ['required', 'date'],
            'scope'        => ['required', 'in:institute,grade'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            // Optional per-grade overrides: [{ grade_id, percentage }, ...]
            'overrides'                => ['sometimes', 'array'],
            'overrides.*.grade_id'     => ['required_with:overrides', 'integer', 'exists:grades,id'],
            'overrides.*.percentage'   => ['required_with:overrides', 'numeric', 'min:-100', 'max:1000'],
        ];
    }

    /** @return array<int, float> grade_id => percentage */
    public function overrideMap(): array
    {
        $map = [];
        foreach ($this->input('overrides', []) as $o) {
            if (isset($o['grade_id'])) {
                $map[(int) $o['grade_id']] = (float) ($o['percentage'] ?? 0);
            }
        }

        return $map;
    }
}
