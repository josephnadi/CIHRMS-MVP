<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Employee;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        $status = fake()->randomElement([
            TicketStatus::Open, TicketStatus::Open, TicketStatus::InProgress,
            TicketStatus::Resolved, TicketStatus::Closed,
        ]);

        return [
            'employee_id' => Employee::factory(),
            'assigned_to' => null,
            'title'       => fake()->randomElement([
                'Laptop not connecting to VPN',
                'Email account locked out',
                'Request for new keyboard',
                'Printer offline in HR office',
                'Cannot access shared drive',
                'Slack notifications not working',
                'Need access to payroll dashboard',
                'Phone number update required',
            ]),
            'description' => fake()->paragraph(3),
            'priority'    => fake()->randomElement(TicketPriority::cases())->value,
            'status'      => $status->value,
            'due_at'      => fake()->dateTimeBetween('-5 days', '+10 days'),
            'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true)
                ? fake()->dateTimeBetween('-7 days', 'now')
                : null,
        ];
    }

    public function overdue(): static
    {
        return $this->state([
            'due_at' => now()->subDays(fake()->numberBetween(1, 5)),
            'status' => TicketStatus::Open->value,
        ]);
    }

    public function open(): static
    {
        return $this->state(['status' => TicketStatus::Open->value, 'resolved_at' => null]);
    }
}
