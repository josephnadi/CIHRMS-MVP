<?php

use App\Events\BenefitClaimDecided;
use App\Events\BenefitClaimSubmitted;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BenefitClaimDecidedNotification;
use App\Notifications\BenefitClaimSubmittedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

// Bus::fake([SendSmsJob::class]) is the scoped pattern established in Task 1.
// Bare Bus::fake() would intercept the CallQueuedListener jobs that wrap
// our event listeners on the database queue driver, silently breaking the
// notification chain.
beforeEach(function () {
    Notification::fake();
    Bus::fake([SendSmsJob::class]);
});

it('notifies benefits.manage holders when BenefitClaimSubmitted fires', function () {
    $reviewer = User::factory()->create(['role' => 'employee']);
    $reviewer->permissions = ['benefits.manage'];
    $reviewer->save();

    $claimantUser = User::factory()->create(['role' => 'employee']);
    $claimantEmployee = Employee::factory()->for($claimantUser, 'user')->create();
    $plan = BenefitPlan::factory()->create();
    $enrolment = BenefitEnrolment::factory()->for($claimantEmployee)->for($plan, 'plan')->create();
    $claim = BenefitClaim::factory()->for($enrolment, 'enrolment')->create();

    event(new BenefitClaimSubmitted($claim));

    Notification::assertSentTo($reviewer, BenefitClaimSubmittedNotification::class);
});

it('notifies the claimant when BenefitClaimDecided fires', function () {
    $claimantUser = User::factory()->create(['role' => 'employee']);
    $claimantEmployee = Employee::factory()->for($claimantUser, 'user')->create();
    $plan = BenefitPlan::factory()->create();
    $enrolment = BenefitEnrolment::factory()->for($claimantEmployee)->for($plan, 'plan')->create();
    $claim = BenefitClaim::factory()->for($enrolment, 'enrolment')->create();

    event(new BenefitClaimDecided($claim));

    Notification::assertSentTo($claimantUser, BenefitClaimDecidedNotification::class);
});

it('does nothing when there are no benefits.manage holders (BenefitClaimSubmitted)', function () {
    $claimantUser = User::factory()->create(['role' => 'employee']);
    $claimantEmployee = Employee::factory()->for($claimantUser, 'user')->create();
    $plan = BenefitPlan::factory()->create();
    $enrolment = BenefitEnrolment::factory()->for($claimantEmployee)->for($plan, 'plan')->create();
    $claim = BenefitClaim::factory()->for($enrolment, 'enrolment')->create();

    event(new BenefitClaimSubmitted($claim));

    Notification::assertNothingSent();
});
