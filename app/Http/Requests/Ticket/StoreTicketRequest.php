<?php

namespace App\Http\Requests\Ticket;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('tickets.create');
    }

    public function rules(): array
    {
        $rules = [
            'employee_id' => ['nullable', 'exists:employees,id'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority'    => ['required', Rule::enum(TicketPriority::class)],
            'due_at'      => ['nullable', 'date', 'after:now'],
        ];

        // Routing a ticket to a colleague is a `tickets.manage` action. Only
        // accept `assigned_to` from users who hold that permission — for
        // everyone else the field is dropped from validated() and ignored,
        // so a plain requester can never self-assign.
        if ($this->user()->hasPermission('tickets.manage')) {
            $rules['assigned_to'] = ['nullable', 'exists:users,id'];
        }

        return $rules;
    }
}
