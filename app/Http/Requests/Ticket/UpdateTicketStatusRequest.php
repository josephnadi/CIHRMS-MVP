<?php

namespace App\Http\Requests\Ticket;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts a partial ticket update from the agent's queue — status, priority,
 * or assignee may be changed independently. At least one must be present.
 */
class UpdateTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('tickets.manage');
    }

    public function rules(): array
    {
        return [
            'status'      => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority'    => ['sometimes', Rule::enum(TicketPriority::class)],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->hasAny(['status', 'priority', 'assigned_to'])) {
                $v->errors()->add('status', 'At least one of status, priority, or assigned_to must be supplied.');
            }
        });
    }
}
