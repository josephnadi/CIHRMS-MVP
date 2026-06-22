<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssigned;
use Illuminate\Support\Facades\Notification;

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

test('a manager can assign a ticket to support staff while creating it', function () {
    Notification::fake();
    $assignee = User::factory()->create(['role' => 'it_support']);

    $this->actingAs($this->hr)
        ->post(route('tickets.store'), [
            'title'       => 'Printer offline',
            'description' => 'The 3rd floor printer will not come online.',
            'priority'    => TicketPriority::Medium->value,
            'assigned_to' => $assignee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tickets', [
        'title'       => 'Printer offline',
        'assigned_to' => $assignee->id,
        'status'      => TicketStatus::Open->value,
    ]);

    Notification::assertSentTo($assignee, TicketAssigned::class);
});

test('an employee without manage rights cannot self-assign a ticket at creation', function () {
    $assignee = User::factory()->create(['role' => 'it_support']);

    $this->actingAs($this->employeeUser)
        ->post(route('tickets.store'), [
            'title'       => 'Laptop slow',
            'description' => 'My laptop has become very slow this week.',
            'priority'    => TicketPriority::Low->value,
            'assigned_to' => $assignee->id,
        ])
        ->assertRedirect();

    // The assignment is silently ignored — non-managers may not route tickets.
    $this->assertDatabaseHas('tickets', [
        'title'       => 'Laptop slow',
        'assigned_to' => null,
    ]);
});

test('an assignee can change the status of their ticket without manage permission', function () {
    // `marketing` holds tickets.create but NOT tickets.manage — the policy
    // still lets the assignee work their own ticket (drag-to-change-status).
    $agent  = User::factory()->create(['role' => 'marketing']);
    $ticket = Ticket::factory()->open()->create([
        'employee_id' => $this->employee->id,
        'assigned_to' => $agent->id,
    ]);

    $this->actingAs($agent)
        ->patch(route('tickets.update', $ticket), [
            'status' => TicketStatus::InProgress->value,
        ])
        ->assertRedirect();

    expect($ticket->refresh()->status->value)->toBe(TicketStatus::InProgress->value);
});

test('a non-manager who is not the assignee cannot update a ticket', function () {
    $outsider = User::factory()->create(['role' => 'marketing']);
    $ticket   = Ticket::factory()->open()->create(['employee_id' => $this->employee->id]);

    $this->actingAs($outsider)
        ->patch(route('tickets.update', $ticket), [
            'status' => TicketStatus::Resolved->value,
        ])
        ->assertForbidden();

    expect($ticket->refresh()->status->value)->toBe(TicketStatus::Open->value);
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
