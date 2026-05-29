<?php

use App\Events\AttendanceCorrectionDecided;
use App\Events\AttendanceCorrectionRequested;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceCorrectionDecidedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake([SendSmsJob::class]);
});

it('notifies manager + attendance.approve holders when AttendanceCorrectionRequested fires', function () {
    $managerUser = User::factory()->create(['role' => 'manager']);
    $managerEmployee = Employee::factory()->for($managerUser, 'user')->create();

    $requesterUser = User::factory()->create(['role' => 'employee']);
    $requesterEmployee = Employee::factory()
        ->for($requesterUser, 'user')
        ->state(['manager_id' => $managerEmployee->id])
        ->create();

    $hrApprover = User::factory()->create(['role' => 'employee']);
    $hrApprover->permissions = ['attendance.approve'];
    $hrApprover->save();

    $correction = AttendanceCorrection::factory()
        ->for($requesterEmployee, 'employee')
        ->state(['requester_id' => $requesterUser->id])
        ->create();

    event(new AttendanceCorrectionRequested($correction));

    Notification::assertSentTo($managerUser, AttendanceCorrectionRequestedNotification::class);
    Notification::assertSentTo($hrApprover, AttendanceCorrectionRequestedNotification::class);
});

it('notifies the requester when AttendanceCorrectionDecided fires', function () {
    $requesterUser = User::factory()->create(['role' => 'employee']);
    $requesterEmployee = Employee::factory()->for($requesterUser, 'user')->create();

    $correction = AttendanceCorrection::factory()
        ->for($requesterEmployee, 'employee')
        ->state(['requester_id' => $requesterUser->id])
        ->create();

    event(new AttendanceCorrectionDecided($correction));

    Notification::assertSentTo($requesterUser, AttendanceCorrectionDecidedNotification::class);
});

it('does not duplicate when the manager also has attendance.approve', function () {
    $managerUser = User::factory()->create(['role' => 'manager']);
    $managerUser->permissions = ['attendance.approve'];
    $managerUser->save();
    $managerEmployee = Employee::factory()->for($managerUser, 'user')->create();

    $requesterUser = User::factory()->create(['role' => 'employee']);
    $requesterEmployee = Employee::factory()
        ->for($requesterUser, 'user')
        ->state(['manager_id' => $managerEmployee->id])
        ->create();

    $correction = AttendanceCorrection::factory()
        ->for($requesterEmployee, 'employee')
        ->state(['requester_id' => $requesterUser->id])
        ->create();

    event(new AttendanceCorrectionRequested($correction));

    Notification::assertSentToTimes($managerUser, AttendanceCorrectionRequestedNotification::class, 1);
});
