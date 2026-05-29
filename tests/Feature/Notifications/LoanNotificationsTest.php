<?php

use App\Events\LoanApproved;
use App\Events\LoanDisbursed;
use App\Events\LoanFullyRepaid;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\User;
use App\Notifications\LoanApprovedNotification;
use App\Notifications\LoanDisbursedNotification;
use App\Notifications\LoanFullyRepaidNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    // Fake only SendSmsJob so queued event listeners (CallQueuedListener) still
    // run on the sync queue driver — Bus::fake() with no args intercepts ALL
    // jobs including listener wrappers, silently preventing listeners from firing.
    Bus::fake([SendSmsJob::class]);
});

it('notifies the applicant and their line manager when LoanApproved fires', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $managerEmployee = Employee::factory()->for($manager, 'user')->create();

    $applicant = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()
        ->for($applicant, 'user')
        ->state(['manager_id' => $managerEmployee->id])
        ->create();

    $loan = LoanAccount::factory()->for($employee)->create();

    event(new LoanApproved($loan));

    Notification::assertSentTo($applicant, LoanApprovedNotification::class);
    Notification::assertSentTo($manager, LoanApprovedNotification::class);
});

it('notifies the applicant + loans.disburse holders when LoanDisbursed fires, sending SMS to phoned recipients', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()
        ->for($applicant, 'user')
        ->state(['phone' => '+233200000099'])
        ->create();
    $loan = LoanAccount::factory()->for($employee)->create();

    $disburser = User::factory()->create(['role' => 'employee']);
    $disburser->permissions = ['loans.disburse'];
    $disburser->save();
    Employee::factory()
        ->for($disburser, 'user')
        ->state(['phone' => '+233200000088'])
        ->create();

    event(new LoanDisbursed($loan));

    Notification::assertSentTo($applicant, LoanDisbursedNotification::class);
    Notification::assertSentTo($disburser, LoanDisbursedNotification::class);
    Bus::assertDispatchedTimes(SendSmsJob::class, 2);
});

it('skips SMS for recipients without a phone (LoanDisbursed)', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()
        ->for($applicant, 'user')
        ->state(['phone' => null])
        ->create();
    $loan = LoanAccount::factory()->for($employee)->create();

    event(new LoanDisbursed($loan));

    Notification::assertSentTo($applicant, LoanDisbursedNotification::class);
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('notifies only the applicant when LoanFullyRepaid fires, with SMS', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()
        ->for($applicant, 'user')
        ->state(['phone' => '+233200000099'])
        ->create();
    $loan = LoanAccount::factory()->for($employee)->create();

    event(new LoanFullyRepaid($loan));

    Notification::assertSentTo($applicant, LoanFullyRepaidNotification::class);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1);
});

it('does not notify the manager twice if applicant has no manager', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()
        ->for($applicant, 'user')
        ->state(['manager_id' => null])
        ->create();
    $loan = LoanAccount::factory()->for($employee)->create();

    event(new LoanApproved($loan));

    Notification::assertSentTo($applicant, LoanApprovedNotification::class);
    Notification::assertSentToTimes($applicant, LoanApprovedNotification::class, 1);
});
