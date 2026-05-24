<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Provision an Employee row for an existing User that doesn't have one yet.
 *
 * Use this to backfill accounts created via the admin form before the
 * "Employee profile" fields became required. Without an Employee record,
 * HR-feature pages (Attendance/Me, Leave, Profile) abort with 404.
 *
 *   php artisan users:provision-employee --staff-id=CEO-001 --department=HR --position="Chief Executive Officer"
 *   php artisan users:provision-employee --email=ceo@cihrm.local --department=HR --position="Chief Executive Officer" --hire-date=2026-05-24
 *   php artisan users:provision-employee --all-missing --department=HR     # bulk: every User without an Employee, default position
 */
class ProvisionUserEmployee extends Command
{
    protected $signature = 'users:provision-employee
                            {--staff-id=     : Target user by staff_id}
                            {--email=        : Target user by email}
                            {--all-missing   : Backfill EVERY user that has no Employee row}
                            {--department=   : Department name OR code (required)}
                            {--position=     : Position / title (default = role label)}
                            {--hire-date=    : YYYY-MM-DD (default = today)}';

    protected $description = 'Provision an Employee row for one user (or all users missing one).';

    public function handle(): int
    {
        $deptInput = (string) ($this->option('department') ?? '');
        if ($deptInput === '') {
            $this->error('--department is required (pass the department code or name).');
            return self::FAILURE;
        }

        $department = Department::query()
            ->where('code', $deptInput)
            ->orWhere('name', $deptInput)
            ->first();

        if (! $department) {
            $this->error("No department found matching \"{$deptInput}\".");
            return self::FAILURE;
        }

        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->warn('No users to provision.');
            return self::SUCCESS;
        }

        $position = (string) ($this->option('position') ?? '');
        $hireDate = (string) ($this->option('hire-date') ?? now()->toDateString());

        $created = 0;
        $skipped = 0;

        foreach ($users as $user) {
            if ($user->employee) {
                $this->line("- {$user->staff_id} ({$user->email}): already has Employee #{$user->employee->employee_no}, skipping");
                $skipped++;
                continue;
            }

            $emp = Employee::create([
                'user_id'       => $user->id,
                'department_id' => $department->id,
                'employee_no'   => $this->nextEmployeeNo(),
                'position'      => $position !== '' ? $position : ($user->role?->label() ?? 'Staff'),
                'hire_date'     => $hireDate,
                'status'        => EmployeeStatus::Active->value,
            ]);

            $this->info("+ {$user->staff_id} ({$user->email}) → Employee #{$emp->employee_no} · {$emp->position} · {$department->name}");
            $created++;
        }

        $this->info("Provisioned {$created} employee row(s); skipped {$skipped}.");
        return self::SUCCESS;
    }

    private function resolveUsers()
    {
        if ($this->option('all-missing')) {
            // Eager-load Employee so the loop's `->employee` check doesn't trip
            // Eloquent's strict-mode lazy-load violation in dev/test environments.
            return User::query()->with('employee')->whereDoesntHave('employee')->get();
        }

        $staffId = (string) ($this->option('staff-id') ?? '');
        $email   = (string) ($this->option('email') ?? '');

        if ($staffId === '' && $email === '') {
            $this->error('Pass one of: --staff-id, --email, or --all-missing.');
            return collect();
        }

        $query = User::query()->with('employee');
        if ($staffId !== '') $query->where('staff_id', $staffId);
        if ($email !== '')   $query->where('email', $email);

        $user = $query->first();
        if (! $user) {
            $this->error('No user matched the given filter.');
            return collect();
        }

        return collect([$user]);
    }

    private function nextEmployeeNo(): string
    {
        $last = Employee::query()
            ->where('employee_no', 'like', 'CIHRM-%')
            ->pluck('employee_no')
            ->map(fn ($s) => (int) substr((string) $s, 6))
            ->max() ?? 0;

        return sprintf('CIHRM-%04d', $last + 1);
    }
}
