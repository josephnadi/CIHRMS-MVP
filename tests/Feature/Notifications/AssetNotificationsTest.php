<?php

use App\Events\AssetAssigned;
use App\Events\AssetReturned;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AssetAssignedNotification;
use App\Notifications\AssetReturnedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake([SendSmsJob::class]);
});

it('notifies the assignee when AssetAssigned fires', function () {
    $userA = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()->for($userA, 'user')->create();
    $asset = Asset::factory()->create();
    $assignment = AssetAssignment::factory()
        ->for($asset)
        ->for($employee)
        ->create();

    event(new AssetAssigned($assignment));

    Notification::assertSentTo($userA, AssetAssignedNotification::class);
});

it('notifies assignee + assets.manage when AssetReturned fires', function () {
    $itManager = User::factory()->create(['role' => 'employee']);
    $itManager->permissions = ['assets.manage'];
    $itManager->save();

    $userA = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()->for($userA, 'user')->create();
    $asset = Asset::factory()->create();
    $assignment = AssetAssignment::factory()
        ->for($asset)
        ->for($employee)
        ->create();

    event(new AssetReturned($assignment));

    Notification::assertSentTo($userA, AssetReturnedNotification::class);
    Notification::assertSentTo($itManager, AssetReturnedNotification::class);
});
