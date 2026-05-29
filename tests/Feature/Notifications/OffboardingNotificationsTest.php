<?php

use App\Events\OffboardingCompleted;
use App\Events\OffboardingInitiated;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Notifications\OffboardingCompletedNotification;
use App\Notifications\OffboardingInitiatedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake([SendSmsJob::class]);
});

it('notifies departing employee + manager + HR + IT when OffboardingInitiated fires', function () {
    $managerUser = User::factory()->create(['role' => 'manager']);
    $managerEmployee = Employee::factory()->for($managerUser, 'user')->create();

    $hr = User::factory()->create(['role' => 'employee']);
    $hr->permissions = ['employees.manage'];
    $hr->save();

    $it = User::factory()->create(['role' => 'employee']);
    $it->permissions = ['assets.manage'];
    $it->save();

    $departingUser = User::factory()->create(['role' => 'employee']);
    $departingEmployee = Employee::factory()
        ->for($departingUser, 'user')
        ->state(['manager_id' => $managerEmployee->id])
        ->create();
    $case = OffboardingCase::factory()->for($departingEmployee, 'employee')->create();

    event(new OffboardingInitiated($case));

    Notification::assertSentTo($departingUser, OffboardingInitiatedNotification::class);
    Notification::assertSentTo($managerUser, OffboardingInitiatedNotification::class);
    Notification::assertSentTo($hr, OffboardingInitiatedNotification::class);
    Notification::assertSentTo($it, OffboardingInitiatedNotification::class);
});

it('notifies employees.manage holders when OffboardingCompleted fires', function () {
    $hr = User::factory()->create(['role' => 'employee']);
    $hr->permissions = ['employees.manage'];
    $hr->save();

    $departingUser = User::factory()->create(['role' => 'employee']);
    $departingEmployee = Employee::factory()->for($departingUser, 'user')->create();
    $case = OffboardingCase::factory()->for($departingEmployee, 'employee')->create();

    event(new OffboardingCompleted($case));

    Notification::assertSentTo($hr, OffboardingCompletedNotification::class);
});
