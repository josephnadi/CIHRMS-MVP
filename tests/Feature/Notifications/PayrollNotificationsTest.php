<?php

use App\Events\PayrollRunApproved;
use App\Events\PayrollRunCalculated;
use App\Events\PayrollRunPaid;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayrollRunApprovedNotification;
use App\Notifications\PayrollRunCalculatedNotification;
use App\Notifications\PayrollRunPaidNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake([SendSmsJob::class]);
});

it('notifies payroll.manage + finance.hub holders when PayrollRunApproved fires', function () {
    $payrollAdmin = User::factory()->create(['role' => 'employee']);
    $payrollAdmin->permissions = ['payroll.manage'];
    $payrollAdmin->save();

    $finance = User::factory()->create(['role' => 'employee']);
    $finance->permissions = ['finance.hub'];
    $finance->save();

    $creator = User::factory()->create(['role' => 'employee']);
    $run = PayrollRun::factory()->state(['created_by' => $creator->id])->create();

    event(new PayrollRunApproved($run));

    Notification::assertSentTo($payrollAdmin, PayrollRunApprovedNotification::class);
    Notification::assertSentTo($finance, PayrollRunApprovedNotification::class);
});

it('notifies only the creator when PayrollRunCalculated fires', function () {
    $creator = User::factory()->create(['role' => 'employee']);
    $run = PayrollRun::factory()->state(['created_by' => $creator->id])->create();

    event(new PayrollRunCalculated($run));

    Notification::assertSentTo($creator, PayrollRunCalculatedNotification::class);
});

it('notifies every employee on the run when PayrollRunPaid fires, sending SMS to phoned employees', function () {
    $run = PayrollRun::factory()->create();

    $userA = User::factory()->create(['role' => 'employee']);
    $employeeA = Employee::factory()
        ->for($userA, 'user')
        ->state(['phone' => '+233200000099'])
        ->create();
    PayrollLine::factory()->for($run, 'run')->for($employeeA)->create();

    $userB = User::factory()->create(['role' => 'employee']);
    $employeeB = Employee::factory()
        ->for($userB, 'user')
        ->state(['phone' => null])
        ->create();
    PayrollLine::factory()->for($run, 'run')->for($employeeB)->create();

    event(new PayrollRunPaid($run));

    Notification::assertSentTo($userA, PayrollRunPaidNotification::class);
    Notification::assertSentTo($userB, PayrollRunPaidNotification::class);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1); // only userA has phone
});
