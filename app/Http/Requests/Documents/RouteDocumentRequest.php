<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentRouteAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class RouteDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('route', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'recipients'                    => ['required', 'array', 'min:1'],
            'recipients.*.user_id'          => ['required', 'integer', 'exists:users,id', 'different:'.$this->user()->id],
            'recipients.*.action_required'  => ['required', new Enum(DocumentRouteAction::class)],
            'recipients.*.due_at'           => ['nullable', 'date', 'after:now'],
        ];
    }
}
