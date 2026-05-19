<?php

namespace App\Http\Requests\IncidentReport;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignIncidentReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                Rule::exists('users', 'id'),
                function ($attr, $value, $fail) {
                    $u = User::find($value);
                    if (! $u) { $fail('User not found.'); return; }

                    $hasPerm = in_array('incidents.review', (array) ($u->permissions ?? []), true)
                        || $u->roles()->whereHas('permissions', fn ($q) => $q->where('slug', 'incidents.review'))->exists();

                    if (! $hasPerm) {
                        $fail('Selected user does not hold the incidents.review permission.');
                    }
                },
            ],
        ];
    }
}
