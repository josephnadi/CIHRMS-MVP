<?php

namespace Database\Seeders;

use App\Enums\ComplaintStatus;
use App\Enums\LeaveStatus;
use App\Enums\PaymentStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Applicant;
use App\Models\Complaint;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PayrollItem;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Roles + permissions catalog must exist before users are backfilled with role pivots.
        $this->call(RolePermissionSeeder::class);

        // Finance F1 — chart of accounts, bank accounts, zero balances.
        // Order matters: chart first (bank accounts reference GL codes), balances last.
        $this->call(ChartOfAccountsSeeder::class);
        $this->call(OrgBankAccountSeeder::class);
        $this->call(GlAccountBalanceSeeder::class);
        $this->call(VendorSeeder::class);

        // Documents module permissions (must run AFTER RolePermissionSeeder so the
        // canonical role rows exist — this seeder attaches documents.* to them).
        $this->call(DocumentPermissionsSeeder::class);

        // Incidents module permissions (must run AFTER RolePermissionSeeder).
        $this->call(IncidentPermissionsSeeder::class);

        // Phase 1 — Ghana statutory reference data + grades/positions + trustees.
        $this->call(GhanaStatutoryReferenceSeeder::class);
        $this->call(PensionTrusteeSeeder::class);
        $this->call(DemoLoanProductSeeder::class);

        // Phase 2 — Ghana 2026 statutory holidays.
        $this->call(GhanaPublicHolidaySeeder::class);

        $this->seedFixedAccounts();
        $this->seedDepartmentsAndEmployees();
        $this->seedLeave();
        $this->seedTickets();
        $this->seedPayroll();
        $this->seedRecruitment();
        $this->seedComplaints();

        // Establishment demo data has to run AFTER departments exist.
        $this->call(EstablishmentDemoSeeder::class);
        $this->call(BiometricDeviceDemoSeeder::class);

        // Communications: seed sample notices for the top-of-page ticker.
        $this->call(AnnouncementSeeder::class);

        // Re-run RBAC sync so newly created users (factory-created) pick up role pivots,
        // and so any new permissions land on existing roles.
        $this->call(RolePermissionSeeder::class);
    }

    private function seedFixedAccounts(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@cihrms.local'],
            [
                'name'        => 'Super Admin',
                'staff_id'    => 'ADMIN-001',
                'role'        => 'super_admin',
                'permissions' => ['*'],
                'password'    => bcrypt('password'),
            ]
        );

        $hrAdmin = User::updateOrCreate(
            ['email' => 'hr@cihrms.local'],
            [
                'name'        => 'HR Manager',
                'staff_id'    => 'HR-001',
                'role'        => 'hr_admin',
                'permissions' => User::ROLE_PERMISSIONS['hr_admin'],
                'password'    => bcrypt('password'),
            ]
        );

        $employeeUser = User::updateOrCreate(
            ['email' => 'employee@cihrms.local'],
            [
                'name'        => 'Akua Mensah',
                'staff_id'    => 'GH-HR-821',
                'role'        => 'employee',
                'permissions' => User::ROLE_PERMISSIONS['employee'],
                'password'    => bcrypt('password'),
            ]
        );

        $finance = User::updateOrCreate(
            ['email' => 'finance@cihrms.local'],
            [
                'name'        => 'Kofi Asante',
                'staff_id'    => 'FIN-001',
                'role'        => 'finance_officer',
                'permissions' => User::ROLE_PERMISSIONS['finance_officer'],
                'password'    => bcrypt('password'),
            ]
        );

        $it = User::updateOrCreate(
            ['email' => 'it@cihrms.local'],
            [
                'name'        => 'Yaw Boateng',
                'staff_id'    => 'IT-001',
                'role'        => 'it_support',
                'permissions' => User::ROLE_PERMISSIONS['it_support'],
                'password'    => bcrypt('password'),
            ]
        );

        $marketing = User::updateOrCreate(
            ['email' => 'marketing@cihrms.local'],
            [
                'name'        => 'Ama Owusu',
                'staff_id'    => 'MKT-001',
                'role'        => 'marketing',
                'permissions' => User::ROLE_PERMISSIONS['marketing'],
                'password'    => bcrypt('password'),
            ]
        );

        $hr = Department::firstOrCreate(
            ['code' => 'HR'],
            ['name' => 'Human Resources', 'description' => 'Core HR operations.']
        );

        Employee::firstOrCreate(
            ['employee_no' => 'CIHRM-0001'],
            [
                'department_id' => $hr->id,
                'user_id'       => $admin->id,
                'position'      => 'HR Director',
                'hire_date'     => now()->subYears(2)->toDateString(),
                'phone'         => '+233200000001',
                'status'        => 'active',
            ]
        );

        Employee::firstOrCreate(
            ['employee_no' => 'CIHRM-0002'],
            [
                'department_id' => $hr->id,
                'user_id'       => $employeeUser->id,
                'position'      => 'Solutions Architect',
                'hire_date'     => now()->subYear()->toDateString(),
                'phone'         => '+233200000002',
                'status'        => 'active',
            ]
        );

        Employee::firstOrCreate(
            ['employee_no' => 'CIHRM-0003'],
            [
                'department_id' => $hr->id,
                'user_id'       => $hrAdmin->id,
                'position'      => 'HR Manager',
                'hire_date'     => now()->subYears(3)->toDateString(),
                'phone'         => '+233200000003',
                'status'        => 'active',
            ]
        );

        Employee::firstOrCreate(
            ['employee_no' => 'CIHRM-0004'],
            [
                'department_id' => $hr->id,
                'user_id'       => $finance->id,
                'position'      => 'Finance Officer',
                'hire_date'     => now()->subYears(2)->toDateString(),
                'phone'         => '+233200000004',
                'status'        => 'active',
            ]
        );

        Employee::firstOrCreate(
            ['employee_no' => 'CIHRM-0005'],
            [
                'department_id' => $hr->id,
                'user_id'       => $it->id,
                'position'      => 'IT Support Lead',
                'hire_date'     => now()->subYear()->toDateString(),
                'phone'         => '+233200000005',
                'status'        => 'active',
            ]
        );

        $marketingDept = Department::firstOrCreate(
            ['code' => 'MKT'],
            ['name' => 'Marketing', 'description' => 'Brand, communications, and campaign delivery.']
        );

        Employee::firstOrCreate(
            ['employee_no' => 'CIHRM-0006'],
            [
                'department_id' => $marketingDept->id,
                'user_id'       => $marketing->id,
                'position'      => 'Marketing Lead',
                'hire_date'     => now()->subYears(2)->toDateString(),
                'phone'         => '+233200000006',
                'status'        => 'active',
            ]
        );
    }

    private function seedDepartmentsAndEmployees(): void
    {
        if (Department::count() >= 6) {
            return;
        }

        Department::factory()->count(5)->create()->each(function (Department $dept) {
            User::factory()
                ->count(fake()->numberBetween(4, 8))
                ->create()
                ->each(function (User $user) use ($dept) {
                    Employee::factory()->create([
                        'user_id'       => $user->id,
                        'department_id' => $dept->id,
                    ]);
                });
        });
    }

    private function seedLeave(): void
    {
        $employees = Employee::all();

        foreach ($employees as $employee) {
            foreach (['annual' => 21, 'sick' => 14] as $type => $total) {
                LeaveBalance::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'type'        => $type,
                        'year'        => now()->year,
                    ],
                    [
                        'total_days' => $total,
                        'used_days'  => fake()->randomFloat(1, 0, $total / 2),
                    ]
                );
            }
        }

        if (LeaveRequest::count() === 0) {
            LeaveRequest::factory()
                ->count(40)
                ->recycle($employees)
                ->create();

            LeaveRequest::factory()
                ->count(10)
                ->pending()
                ->recycle($employees)
                ->create();
        }
    }

    private function seedTickets(): void
    {
        if (Ticket::count() > 0) {
            return;
        }

        $employees = Employee::all();
        $supportStaff = User::whereIn('role', ['it_support', 'super_admin', 'hr_admin'])->get();

        Ticket::factory()
            ->count(25)
            ->recycle($employees)
            ->create()
            ->each(function (Ticket $ticket) use ($supportStaff) {
                if ($ticket->status !== TicketStatus::Open && $supportStaff->isNotEmpty()) {
                    $ticket->update(['assigned_to' => $supportStaff->random()->id]);
                }
            });

        Ticket::factory()->overdue()->count(5)->recycle($employees)->create();
        Ticket::factory()->open()->count(8)->recycle($employees)->state([
            'priority' => TicketPriority::High->value,
        ])->create();
    }

    private function seedPayroll(): void
    {
        if (Payment::count() > 0) {
            return;
        }

        $employees = Employee::all();
        $processor = User::where('role', 'finance_officer')->first();

        Payment::factory()
            ->count(50)
            ->recycle($employees)
            ->create(['processed_by' => $processor?->id])
            ->each(function (Payment $payment) {
                $basic = (float) $payment->amount * 0.7;
                $allowance = (float) $payment->amount * 0.2;
                $tax = (float) $payment->amount * -0.1;

                PayrollItem::create(['payment_id' => $payment->id, 'label' => 'Basic Salary',         'type' => 'earning',   'amount' => round($basic, 2)]);
                PayrollItem::create(['payment_id' => $payment->id, 'label' => 'Transport Allowance',  'type' => 'earning',   'amount' => round($allowance, 2)]);
                PayrollItem::create(['payment_id' => $payment->id, 'label' => 'Income Tax',           'type' => 'deduction', 'amount' => round($tax, 2)]);
            });

        Payment::factory()->pending()->count(8)->recycle($employees)->create([
            'processed_by' => $processor?->id,
        ]);
    }

    private function seedRecruitment(): void
    {
        if (JobPosting::count() > 0) {
            return;
        }

        JobPosting::factory()->open()->count(6)->create()->each(function (JobPosting $job) {
            Applicant::factory()->count(fake()->numberBetween(3, 12))->create([
                'job_posting_id' => $job->id,
            ]);
        });

        JobPosting::factory()->closed()->count(2)->create();
    }

    private function seedComplaints(): void
    {
        if (Complaint::count() === 0) {
            Complaint::factory()->count(15)->create();
        }
    }
}
