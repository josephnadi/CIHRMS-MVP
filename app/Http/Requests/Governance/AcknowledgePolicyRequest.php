<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.acknowledge') ?? false;
    }

    public function rules(): array
    {
        return [
            'signed_full_name' => ['required', 'string', 'max:120'],
        ];
    }

    protected function passedValidation(): void
    {
        $expected = strtolower(trim((string) $this->user()->name));
        $signed   = strtolower(trim((string) $this->validated('signed_full_name')));

        if ($expected !== '' && $expected !== $signed) {
            abort(422, "Typed signature does not match the account name on file.");
        }
    }
}
