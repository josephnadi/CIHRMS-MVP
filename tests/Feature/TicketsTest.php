<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;

beforeEach(function () {
    $this->dept = Department::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr_admin']);
    $this->employeeUser = User::factory()->create(['role' => 'employee']);
    $this->employee = Employee::factory()->active()->create([
        'user_id'       => $this->employeeUser->id,
        'department_id' => $this->dept->id,
    ]);
});

test('employee can open a ticket', function () {
    $response = $this->actingAs($this->employeeUser)
        ->post(route('tickets.store'), [
            'title'       => 'VPN broken',
            'description' => 'Cannot reach internal services from home network.',
            'priority'    => TicketPriority::High->value,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('tickets', [
        'title'       => 'VPN broken',
        'priority'    => TicketPriority::High->value,
        'status'      => TicketStatus::Open->value,
        'employee_id' => $this->employee->id,
    ]);
});

test('manager can mark a ticket resolved and resolved_at is stamped', function () {
    $ticket = Ticket::factory()->open()->create(['employee_id' => $this->employee->id]);

    $response = $this->actingAs($this->hr)
        ->patch(route('tickets.update', $ticket), [
            'status' => TicketStatus::Resolved->value,
        ]);

    $response->assertRedirect();

    $ticket->refresh();
    expect($ticket->status->value)->toBe(TicketStatus::Resolved->value);
    expect($ticket->resolved_at)->not->toBeNull();
});

test('manager can assign a ticket', function () {
    $ticket = Ticket::factory()->open()->create(['employee_id' => $this->employee->id]);
    $assignee = User::factory()->create(['role' => 'it_support']);

    $this->actingAs($this->hr)
        ->patch(route('tickets.update', $ticket), [
            'status'      => TicketStatus::InProgress->value,
            'assigned_to' => $assignee->id,
        ])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->assigned_to)->toBe($assignee->id);
    expect($ticket->status->value)->toBe(TicketStatus::InProgress->value);
});

test('tickets index renders for users with tickets permission', function () {
    Ticket::factory()->count(3)->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->hr)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tickets/Index')
            ->has('tickets.data', 3)
            ->has('staff')
        );
});

test('manager can delete a ticket', function () {
    $ticket = Ticket::factory()->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->hr)
        ->delete(route('tickets.destroy', $ticket))
        ->assertRedirect();

    $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
});

test('employee cannot delete a ticket', function () {
    $ticket = Ticket::factory()->create(['employee_id' => $this->employee->id]);

    // The route requires `tickets.manage` permission; employees only have `tickets.create`.
    $this->actingAs($this->employeeUser)
        ->delete(route('tickets.destroy', $ticket))
        ->assertForbidden();

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'deleted_at' => null]);
});
