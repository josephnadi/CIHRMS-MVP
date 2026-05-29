# Notifications N2 — Event Wiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire all 15 dangling domain events (Loans, Benefits, Attendance corrections, Payroll, Offboarding, Assets, Document signed) to per-module notification listeners that send DB + mail notifications, plus SMS for 4 selected events.

**Architecture:** One Laravel `Notification` class per event (15 total), one module-scoped listener per logical group (7 total). Listeners use `Event::listen(...)` registered in `AppServiceProvider::boot()`. SMS dispatch lives inside listeners (not Laravel channels), calling N1's `SmsDispatcher::send()` directly — async, retried, rate-limited.

**Tech Stack:** Laravel 13.8, PHP 8.4, Pest. Builds on N1 reliability (PR #71): `SmsDispatcher::send()` is async-by-default, classifies failures, retries transients, alerts on exhaustion.

---

## File Structure

**New files (22):**

- `app/Listeners/Notifications/SendLoanNotifications.php`
- `app/Listeners/Notifications/SendBenefitsNotifications.php`
- `app/Listeners/Notifications/SendAttendanceCorrectionNotifications.php`
- `app/Listeners/Notifications/SendPayrollNotifications.php`
- `app/Listeners/Notifications/SendOffboardingNotifications.php`
- `app/Listeners/Notifications/SendAssetNotifications.php`
- `app/Listeners/Notifications/SendDocumentNotifications.php`
- `app/Notifications/LoanApprovedNotification.php`
- `app/Notifications/LoanDisbursedNotification.php`
- `app/Notifications/LoanFullyRepaidNotification.php`
- `app/Notifications/AttendanceCorrectionRequestedNotification.php`
- `app/Notifications/AttendanceCorrectionDecidedNotification.php`
- `app/Notifications/BenefitClaimSubmittedNotification.php`
- `app/Notifications/BenefitClaimDecidedNotification.php`
- `app/Notifications/PayrollRunApprovedNotification.php`
- `app/Notifications/PayrollRunCalculatedNotification.php`
- `app/Notifications/PayrollRunPaidNotification.php`
- `app/Notifications/OffboardingInitiatedNotification.php`
- `app/Notifications/OffboardingCompletedNotification.php`
- `app/Notifications/AssetAssignedNotification.php`
- `app/Notifications/AssetReturnedNotification.php`
- `app/Notifications/DocumentSignedNotification.php`

**Modified files (2):**

- `app/Providers/AppServiceProvider.php` — 15 new `Event::listen(...)` registrations in `boot()`
- `app/Services/DocumentService.php` — fire `event(new DocumentSigned($doc, $annotation))` after a signature/initial annotation is created

**New test files (7):**

- `tests/Feature/Notifications/LoanNotificationsTest.php`
- `tests/Feature/Notifications/BenefitNotificationsTest.php`
- `tests/Feature/Notifications/AttendanceCorrectionNotificationsTest.php`
- `tests/Feature/Notifications/PayrollNotificationsTest.php`
- `tests/Feature/Notifications/OffboardingNotificationsTest.php`
- `tests/Feature/Notifications/AssetNotificationsTest.php`
- `tests/Feature/Notifications/DocumentNotificationsTest.php`

**Branch:** `feat/notifications-n2-event-wiring` (off `main` after N1 merge).

---

## Shared conventions used in every task

**Notification class skeleton:**

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class XxxNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(/* event payload */) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return ['kind' => '...', 'message' => '...', 'link' => '...'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('...')
            ->line('...')
            ->action('View', '...');
    }
}
```

SMS-allowlist notifications add `public function toSmsBody(mixed $notifiable): string` returning a short body.

**Listener class skeleton:**

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Models\User;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendXxxNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        // match (true) { ... } and route to private per-event method
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

**AppServiceProvider registration pattern** (add inside `boot()` near existing `Event::listen` lines around 307–355):

```php
Event::listen(\App\Events\XxxEvent::class, \App\Listeners\Notifications\SendXxxNotifications::class);
```

**Test file skeleton:**

```php
<?php

use App\Notifications\XxxNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake();  // Catch SendSmsJob dispatches from the N1 SmsDispatcher
});

it('notifies <recipient> when <event> fires', function () {
    // arrange ...
    event(new \App\Events\XxxEvent($payload));
    Notification::assertSentTo($recipient, XxxNotification::class);
});
```

---

## Task 1: Loan notifications (3 events)

**Files:**
- Create: `app/Notifications/LoanApprovedNotification.php`
- Create: `app/Notifications/LoanDisbursedNotification.php`
- Create: `app/Notifications/LoanFullyRepaidNotification.php`
- Create: `app/Listeners/Notifications/SendLoanNotifications.php`
- Create: `tests/Feature/Notifications/LoanNotificationsTest.php`
- Modify: `app/Providers/AppServiceProvider.php` — register 3 events

### Step 1: Write the failing test

Create `tests/Feature/Notifications/LoanNotificationsTest.php`:

```php
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
    Bus::fake();
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
    $applicant->phone = '+233200000099';
    $applicant->save();
    $employee = Employee::factory()->for($applicant, 'user')->create();
    $loan = LoanAccount::factory()->for($employee)->create();

    $disburser = User::factory()->create(['role' => 'employee']);
    $disburser->permissions = ['loans.disburse'];
    $disburser->phone = '+233200000088';
    $disburser->save();

    event(new LoanDisbursed($loan));

    Notification::assertSentTo($applicant, LoanDisbursedNotification::class);
    Notification::assertSentTo($disburser, LoanDisbursedNotification::class);
    // SMS dispatched for both phoned recipients
    Bus::assertDispatchedTimes(SendSmsJob::class, 2);
});

it('skips SMS for recipients without a phone (LoanDisbursed)', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $applicant->phone = null;
    $applicant->save();
    $employee = Employee::factory()->for($applicant, 'user')->create();
    $loan = LoanAccount::factory()->for($employee)->create();

    event(new LoanDisbursed($loan));

    Notification::assertSentTo($applicant, LoanDisbursedNotification::class);
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('notifies only the applicant when LoanFullyRepaid fires, with SMS', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $applicant->phone = '+233200000099';
    $applicant->save();
    $employee = Employee::factory()->for($applicant, 'user')->create();
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
```

### Step 2: Run test to verify it fails

Run from `d:\CIHRMS\cihrms-mvp`:
```
vendor/bin/pest tests/Feature/Notifications/LoanNotificationsTest.php
```
Expected: FAIL with "class App\Notifications\LoanApprovedNotification not found" (and similar).

### Step 3: Create `LoanApprovedNotification`

Create `app/Notifications/LoanApprovedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoanAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LoanAccount $loan) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'loan_approved',
            'message' => $this->isApplicant($notifiable)
                ? "Your loan of GHS {$this->loan->principal} has been approved."
                : "{$this->loan->employee?->user?->name}'s loan of GHS {$this->loan->principal} has been approved.",
            'link'    => "/loans/{$this->loan->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $applicant = $this->isApplicant($notifiable);
        $subject = $applicant ? 'Your loan has been approved' : 'Loan approval notice';
        $line = $applicant
            ? "Your loan request of GHS {$this->loan->principal} has been approved."
            : "{$this->loan->employee?->user?->name}'s loan request of GHS {$this->loan->principal} has been approved.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->line("Reference: {$this->loan->reference}")
            ->action('View loan', url("/loans/{$this->loan->id}"));
    }

    private function isApplicant(mixed $notifiable): bool
    {
        return $notifiable?->id === $this->loan->employee?->user_id;
    }
}
```

### Step 4: Create `LoanDisbursedNotification`

Create `app/Notifications/LoanDisbursedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoanAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanDisbursedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LoanAccount $loan) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'loan_disbursed',
            'message' => $this->isApplicant($notifiable)
                ? "Your loan of GHS {$this->loan->principal} has been disbursed."
                : "Loan {$this->loan->reference} has been disbursed (GHS {$this->loan->principal}).",
            'link'    => "/loans/{$this->loan->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $applicant = $this->isApplicant($notifiable);
        $subject = $applicant ? 'Your loan has been disbursed' : 'Loan disbursement processed';
        $line = $applicant
            ? "Your loan of GHS {$this->loan->principal} has been disbursed to your account."
            : "Loan {$this->loan->reference} of GHS {$this->loan->principal} has been disbursed.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->line("Reference: {$this->loan->reference}")
            ->action('View loan', url("/loans/{$this->loan->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        return "Your loan {$this->loan->reference} (GHS {$this->loan->principal}) has been disbursed.";
    }

    private function isApplicant(mixed $notifiable): bool
    {
        return $notifiable?->id === $this->loan->employee?->user_id;
    }
}
```

### Step 5: Create `LoanFullyRepaidNotification`

Create `app/Notifications/LoanFullyRepaidNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoanAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanFullyRepaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LoanAccount $loan) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'loan_repaid',
            'message' => "Your loan {$this->loan->reference} is fully repaid.",
            'link'    => "/loans/{$this->loan->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your loan is fully repaid')
            ->line("Your loan {$this->loan->reference} of GHS {$this->loan->principal} is now fully repaid.")
            ->line('Thank you for completing your repayment schedule.')
            ->action('View loan', url("/loans/{$this->loan->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        return "Your loan {$this->loan->reference} is fully repaid. Thank you.";
    }
}
```

### Step 6: Create `SendLoanNotifications` listener

Create `app/Listeners/Notifications/SendLoanNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\LoanApproved;
use App\Events\LoanDisbursed;
use App\Events\LoanFullyRepaid;
use App\Models\User;
use App\Notifications\LoanApprovedNotification;
use App\Notifications\LoanDisbursedNotification;
use App\Notifications\LoanFullyRepaidNotification;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendLoanNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof LoanApproved    => $this->onApproved($event),
            $event instanceof LoanDisbursed   => $this->onDisbursed($event),
            $event instanceof LoanFullyRepaid => $this->onRepaid($event),
            default                           => null,
        };
    }

    private function onApproved(LoanApproved $event): void
    {
        $loan = $event->loan;
        $applicant = $loan->employee?->user;
        $manager   = $loan->employee?->manager?->user;
        $notification = new LoanApprovedNotification($loan);

        foreach (array_filter([$applicant, $manager]) as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function onDisbursed(LoanDisbursed $event): void
    {
        $loan = $event->loan;
        $applicant = $loan->employee?->user;
        $disbursers = $this->holders('loans.disburse');
        $notification = new LoanDisbursedNotification($loan);

        $recipients = collect(array_filter([$applicant]))->concat($disbursers)->unique('id');
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
            $this->sendSmsIfPhoned($recipient, $notification, 'loan', $loan->id);
        }
    }

    private function onRepaid(LoanFullyRepaid $event): void
    {
        $loan = $event->loan;
        $applicant = $loan->employee?->user;
        if (! $applicant) return;
        $notification = new LoanFullyRepaidNotification($loan);
        $applicant->notify($notification);
        $this->sendSmsIfPhoned($applicant, $notification, 'loan', $loan->id);
    }

    private function sendSmsIfPhoned(User $recipient, object $notification, string $contextType, int $contextId): void
    {
        if (! $recipient->phone) return;
        $this->sms->send(
            toPhone:     $recipient->phone,
            body:        $notification->toSmsBody($recipient),
            contextType: $contextType,
            contextId:   $contextId,
        );
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

### Step 7: Register events in AppServiceProvider

Open `app/Providers/AppServiceProvider.php`. Find the existing `Event::listen(LeaveStatusUpdated::class, SendNotifications::class)` block around lines 307–355 (the section that wires events to listeners). After the last existing `Event::listen(...)` call in that block, add:

```php

        // ── N2 notifications: loans ──
        Event::listen(\App\Events\LoanApproved::class,    \App\Listeners\Notifications\SendLoanNotifications::class);
        Event::listen(\App\Events\LoanDisbursed::class,   \App\Listeners\Notifications\SendLoanNotifications::class);
        Event::listen(\App\Events\LoanFullyRepaid::class, \App\Listeners\Notifications\SendLoanNotifications::class);
```

### Step 8: Run test to verify it passes

```
vendor/bin/pest tests/Feature/Notifications/LoanNotificationsTest.php
```
Expected: PASS, 5 tests passed.

### Step 9: Run full messaging + notifications tests as sanity

```
vendor/bin/pest tests/Feature/Messaging/ tests/Feature/Notifications/ tests/Unit/Messaging/
```
Expected: ALL PASS.

### Step 10: Commit

```
git add app/Notifications/LoanApprovedNotification.php \
        app/Notifications/LoanDisbursedNotification.php \
        app/Notifications/LoanFullyRepaidNotification.php \
        app/Listeners/Notifications/SendLoanNotifications.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/LoanNotificationsTest.php
git commit -m "feat(notifications): wire loan events (approved/disbursed/fully repaid)"
```

---

## Task 2: Benefits notifications (2 events)

**Files:**
- Create: `app/Notifications/BenefitClaimSubmittedNotification.php`
- Create: `app/Notifications/BenefitClaimDecidedNotification.php`
- Create: `app/Listeners/Notifications/SendBenefitsNotifications.php`
- Create: `tests/Feature/Notifications/BenefitNotificationsTest.php`
- Modify: `app/Providers/AppServiceProvider.php` — register 2 events

### Step 1: Write the failing test

Create `tests/Feature/Notifications/BenefitNotificationsTest.php`:

```php
<?php

use App\Events\BenefitClaimDecided;
use App\Events\BenefitClaimSubmitted;
use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BenefitClaimDecidedNotification;
use App\Notifications\BenefitClaimSubmittedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake();
});

it('notifies benefits.manage holders when BenefitClaimSubmitted fires', function () {
    $reviewer = User::factory()->create(['role' => 'employee']);
    $reviewer->permissions = ['benefits.manage'];
    $reviewer->save();

    $claimantUser = User::factory()->create(['role' => 'employee']);
    $claimantEmployee = Employee::factory()->for($claimantUser, 'user')->create();
    $plan = BenefitPlan::factory()->create();
    $enrolment = BenefitEnrolment::factory()->for($claimantEmployee)->for($plan)->create();
    $claim = BenefitClaim::factory()->for($enrolment)->create();

    event(new BenefitClaimSubmitted($claim));

    Notification::assertSentTo($reviewer, BenefitClaimSubmittedNotification::class);
});

it('notifies the claimant when BenefitClaimDecided fires', function () {
    $claimantUser = User::factory()->create(['role' => 'employee']);
    $claimantEmployee = Employee::factory()->for($claimantUser, 'user')->create();
    $plan = BenefitPlan::factory()->create();
    $enrolment = BenefitEnrolment::factory()->for($claimantEmployee)->for($plan)->create();
    $claim = BenefitClaim::factory()->for($enrolment)->create();

    event(new BenefitClaimDecided($claim));

    Notification::assertSentTo($claimantUser, BenefitClaimDecidedNotification::class);
});

it('does nothing when there are no benefits.manage holders (BenefitClaimSubmitted)', function () {
    $claimantUser = User::factory()->create(['role' => 'employee']);
    $claimantEmployee = Employee::factory()->for($claimantUser, 'user')->create();
    $plan = BenefitPlan::factory()->create();
    $enrolment = BenefitEnrolment::factory()->for($claimantEmployee)->for($plan)->create();
    $claim = BenefitClaim::factory()->for($enrolment)->create();

    event(new BenefitClaimSubmitted($claim));

    Notification::assertNothingSent();
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Notifications/BenefitNotificationsTest.php
```
Expected: FAIL with "class App\Notifications\BenefitClaimSubmittedNotification not found".

### Step 3: Create `BenefitClaimSubmittedNotification`

Create `app/Notifications/BenefitClaimSubmittedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BenefitClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BenefitClaimSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly BenefitClaim $claim) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $claimant = $this->claim->enrolment?->employee?->user?->name ?? 'A member';
        return [
            'kind'    => 'benefit_claim_submitted',
            'message' => "{$claimant} has submitted a benefit claim.",
            'link'    => "/benefits/claims/{$this->claim->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $claimant = $this->claim->enrolment?->employee?->user?->name ?? 'A member';
        return (new MailMessage())
            ->subject('Benefit claim submitted for review')
            ->line("{$claimant} has submitted a benefit claim.")
            ->action('Review claim', url("/benefits/claims/{$this->claim->id}"));
    }
}
```

### Step 4: Create `BenefitClaimDecidedNotification`

Create `app/Notifications/BenefitClaimDecidedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BenefitClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BenefitClaimDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly BenefitClaim $claim) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'benefit_claim_decided',
            'message' => "Your benefit claim has been {$this->claim->status->value}.",
            'link'    => "/benefits/claims/{$this->claim->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your benefit claim has a decision')
            ->line("Your benefit claim has been {$this->claim->status->value}.")
            ->action('View claim', url("/benefits/claims/{$this->claim->id}"));
    }
}
```

### Step 5: Create `SendBenefitsNotifications` listener

Create `app/Listeners/Notifications/SendBenefitsNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\BenefitClaimDecided;
use App\Events\BenefitClaimSubmitted;
use App\Models\User;
use App\Notifications\BenefitClaimDecidedNotification;
use App\Notifications\BenefitClaimSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendBenefitsNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof BenefitClaimSubmitted => $this->onSubmitted($event),
            $event instanceof BenefitClaimDecided   => $this->onDecided($event),
            default                                 => null,
        };
    }

    private function onSubmitted(BenefitClaimSubmitted $event): void
    {
        $notification = new BenefitClaimSubmittedNotification($event->claim);
        foreach ($this->holders('benefits.manage') as $reviewer) {
            $reviewer->notify($notification);
        }
    }

    private function onDecided(BenefitClaimDecided $event): void
    {
        $claimant = $event->claim->enrolment?->employee?->user;
        if (! $claimant) return;
        $claimant->notify(new BenefitClaimDecidedNotification($event->claim));
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

### Step 6: Register events in AppServiceProvider

In `app/Providers/AppServiceProvider.php`, immediately after the Task 1 loan registrations, add:

```php

        // ── N2 notifications: benefits ──
        Event::listen(\App\Events\BenefitClaimSubmitted::class, \App\Listeners\Notifications\SendBenefitsNotifications::class);
        Event::listen(\App\Events\BenefitClaimDecided::class,   \App\Listeners\Notifications\SendBenefitsNotifications::class);
```

### Step 7: Run test to verify it passes

```
vendor/bin/pest tests/Feature/Notifications/BenefitNotificationsTest.php
```
Expected: PASS, 3 tests passed.

### Step 8: Commit

```
git add app/Notifications/BenefitClaimSubmittedNotification.php \
        app/Notifications/BenefitClaimDecidedNotification.php \
        app/Listeners/Notifications/SendBenefitsNotifications.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/BenefitNotificationsTest.php
git commit -m "feat(notifications): wire benefit claim events (submitted/decided)"
```

---

## Task 3: Attendance correction notifications (2 events)

**Files:**
- Create: `app/Notifications/AttendanceCorrectionRequestedNotification.php`
- Create: `app/Notifications/AttendanceCorrectionDecidedNotification.php`
- Create: `app/Listeners/Notifications/SendAttendanceCorrectionNotifications.php`
- Create: `tests/Feature/Notifications/AttendanceCorrectionNotificationsTest.php`
- Modify: `app/Providers/AppServiceProvider.php` — register 2 events

### Step 1: Write the failing test

Create `tests/Feature/Notifications/AttendanceCorrectionNotificationsTest.php`:

```php
<?php

use App\Events\AttendanceCorrectionDecided;
use App\Events\AttendanceCorrectionRequested;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceCorrectionDecidedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake();
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
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Notifications/AttendanceCorrectionNotificationsTest.php
```
Expected: FAIL.

### Step 3: Create `AttendanceCorrectionRequestedNotification`

Create `app/Notifications/AttendanceCorrectionRequestedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AttendanceCorrection $correction) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $requester = $this->correction->employee?->user?->name ?? 'An employee';
        return [
            'kind'    => 'attendance_correction_requested',
            'message' => "{$requester} requested an attendance correction.",
            'link'    => "/attendance/corrections/{$this->correction->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $requester = $this->correction->employee?->user?->name ?? 'An employee';
        return (new MailMessage())
            ->subject('Attendance correction awaiting approval')
            ->line("{$requester} requested an attendance correction.")
            ->line("Reason: {$this->correction->reason}")
            ->action('Review correction', url("/attendance/corrections/{$this->correction->id}"));
    }
}
```

### Step 4: Create `AttendanceCorrectionDecidedNotification`

Create `app/Notifications/AttendanceCorrectionDecidedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AttendanceCorrection $correction) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'attendance_correction_decided',
            'message' => "Your attendance correction was {$this->correction->status->value}.",
            'link'    => "/attendance/corrections/{$this->correction->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Decision on your attendance correction')
            ->line("Your attendance correction was {$this->correction->status->value}.")
            ->when($this->correction->decision_notes, fn ($m) => $m->line("Notes: {$this->correction->decision_notes}"))
            ->action('View correction', url("/attendance/corrections/{$this->correction->id}"));
    }
}
```

### Step 5: Create `SendAttendanceCorrectionNotifications` listener

Create `app/Listeners/Notifications/SendAttendanceCorrectionNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\AttendanceCorrectionDecided;
use App\Events\AttendanceCorrectionRequested;
use App\Models\User;
use App\Notifications\AttendanceCorrectionDecidedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendAttendanceCorrectionNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof AttendanceCorrectionRequested => $this->onRequested($event),
            $event instanceof AttendanceCorrectionDecided   => $this->onDecided($event),
            default                                         => null,
        };
    }

    private function onRequested(AttendanceCorrectionRequested $event): void
    {
        $correction = $event->correction;
        $manager  = $correction->employee?->manager?->user;
        $approvers = $this->holders('attendance.approve');

        $recipients = collect(array_filter([$manager]))->concat($approvers)->unique('id');
        $notification = new AttendanceCorrectionRequestedNotification($correction);
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function onDecided(AttendanceCorrectionDecided $event): void
    {
        $requester = User::find($event->correction->requester_id);
        if (! $requester) return;
        $requester->notify(new AttendanceCorrectionDecidedNotification($event->correction));
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

### Step 6: Register events in AppServiceProvider

```php

        // ── N2 notifications: attendance corrections ──
        Event::listen(\App\Events\AttendanceCorrectionRequested::class, \App\Listeners\Notifications\SendAttendanceCorrectionNotifications::class);
        Event::listen(\App\Events\AttendanceCorrectionDecided::class,   \App\Listeners\Notifications\SendAttendanceCorrectionNotifications::class);
```

### Step 7: Run test + commit

```
vendor/bin/pest tests/Feature/Notifications/AttendanceCorrectionNotificationsTest.php
```
Expected: PASS, 3 tests.

```
git add app/Notifications/AttendanceCorrectionRequestedNotification.php \
        app/Notifications/AttendanceCorrectionDecidedNotification.php \
        app/Listeners/Notifications/SendAttendanceCorrectionNotifications.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/AttendanceCorrectionNotificationsTest.php
git commit -m "feat(notifications): wire attendance correction events (requested/decided)"
```

---

## Task 4: Payroll notifications (3 events with PayrollRunPaid fan-out)

**Files:**
- Create: `app/Notifications/PayrollRunApprovedNotification.php`
- Create: `app/Notifications/PayrollRunCalculatedNotification.php`
- Create: `app/Notifications/PayrollRunPaidNotification.php`
- Create: `app/Listeners/Notifications/SendPayrollNotifications.php`
- Create: `tests/Feature/Notifications/PayrollNotificationsTest.php`
- Modify: `app/Providers/AppServiceProvider.php` — register 3 events

### Step 1: Write the failing test

Create `tests/Feature/Notifications/PayrollNotificationsTest.php`:

```php
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
    Bus::fake();
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

it('notifies every employee on the run when PayrollRunPaid fires, sending SMS to phoned recipients', function () {
    $run = PayrollRun::factory()->create();

    $userA = User::factory()->create(['role' => 'employee']);
    $userA->phone = '+233200000099';
    $userA->save();
    $employeeA = Employee::factory()->for($userA, 'user')->create();
    PayrollLine::factory()->for($run)->for($employeeA)->create();

    $userB = User::factory()->create(['role' => 'employee']);
    // userB has no phone
    $employeeB = Employee::factory()->for($userB, 'user')->create();
    PayrollLine::factory()->for($run)->for($employeeB)->create();

    event(new PayrollRunPaid($run));

    Notification::assertSentTo($userA, PayrollRunPaidNotification::class);
    Notification::assertSentTo($userB, PayrollRunPaidNotification::class);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1); // only userA has phone
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Notifications/PayrollNotificationsTest.php
```
Expected: FAIL.

### Step 3: Create `PayrollRunApprovedNotification`

Create `app/Notifications/PayrollRunApprovedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PayrollRun $run) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'payroll_run_approved',
            'message' => "Payroll run for {$this->run->periodLabel()} has been approved.",
            'link'    => "/payroll/runs/{$this->run->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Payroll run approved — {$this->run->periodLabel()}")
            ->line("The payroll run for {$this->run->periodLabel()} has been approved and is ready for disbursement.")
            ->action('View run', url("/payroll/runs/{$this->run->id}"));
    }
}
```

### Step 4: Create `PayrollRunCalculatedNotification`

Create `app/Notifications/PayrollRunCalculatedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunCalculatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PayrollRun $run) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'payroll_run_calculated',
            'message' => "Payroll run for {$this->run->periodLabel()} has finished calculating.",
            'link'    => "/payroll/runs/{$this->run->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Payroll run calculated — {$this->run->periodLabel()}")
            ->line("The payroll run for {$this->run->periodLabel()} has finished calculating and is ready for review.")
            ->action('View run', url("/payroll/runs/{$this->run->id}"));
    }
}
```

### Step 5: Create `PayrollRunPaidNotification`

Create `app/Notifications/PayrollRunPaidNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PayrollLine $line) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'payslip_available',
            'message' => "Your payslip for {$this->line->run?->periodLabel()} is available.",
            'link'    => "/payroll/lines/{$this->line->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Your payslip is available — {$this->line->run?->periodLabel()}")
            ->line("Your payslip for {$this->line->run?->periodLabel()} is now available.")
            ->line("Net pay: GHS {$this->line->net_pay}")
            ->action('View payslip', url("/payroll/lines/{$this->line->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        return "Your {$this->line->run?->periodLabel()} payslip is available. Net: GHS {$this->line->net_pay}.";
    }
}
```

### Step 6: Create `SendPayrollNotifications` listener

Create `app/Listeners/Notifications/SendPayrollNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\PayrollRunApproved;
use App\Events\PayrollRunCalculated;
use App\Events\PayrollRunPaid;
use App\Models\User;
use App\Notifications\PayrollRunApprovedNotification;
use App\Notifications\PayrollRunCalculatedNotification;
use App\Notifications\PayrollRunPaidNotification;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendPayrollNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof PayrollRunApproved   => $this->onApproved($event),
            $event instanceof PayrollRunCalculated => $this->onCalculated($event),
            $event instanceof PayrollRunPaid       => $this->onPaid($event),
            default                                => null,
        };
    }

    private function onApproved(PayrollRunApproved $event): void
    {
        $audience = $this->holders('payroll.manage')->concat($this->holders('finance.hub'))->unique('id');
        $notification = new PayrollRunApprovedNotification($event->run);
        foreach ($audience as $user) {
            $user->notify($notification);
        }
    }

    private function onCalculated(PayrollRunCalculated $event): void
    {
        $creator = User::find($event->run->created_by);
        if (! $creator) return;
        $creator->notify(new PayrollRunCalculatedNotification($event->run));
    }

    private function onPaid(PayrollRunPaid $event): void
    {
        $event->run->lines()->with('employee.user')->get()->each(function ($line) {
            $user = $line->employee?->user;
            if (! $user) return;
            $notification = new PayrollRunPaidNotification($line);
            $user->notify($notification);
            if ($user->phone) {
                $this->sms->send(
                    toPhone:     $user->phone,
                    body:        $notification->toSmsBody($user),
                    contextType: 'payroll',
                    contextId:   $event->run->id,
                );
            }
        });
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

### Step 7: Register events in AppServiceProvider

```php

        // ── N2 notifications: payroll ──
        Event::listen(\App\Events\PayrollRunApproved::class,   \App\Listeners\Notifications\SendPayrollNotifications::class);
        Event::listen(\App\Events\PayrollRunCalculated::class, \App\Listeners\Notifications\SendPayrollNotifications::class);
        Event::listen(\App\Events\PayrollRunPaid::class,       \App\Listeners\Notifications\SendPayrollNotifications::class);
```

### Step 8: Run test + commit

```
vendor/bin/pest tests/Feature/Notifications/PayrollNotificationsTest.php
```
Expected: PASS, 3 tests.

```
git add app/Notifications/PayrollRunApprovedNotification.php \
        app/Notifications/PayrollRunCalculatedNotification.php \
        app/Notifications/PayrollRunPaidNotification.php \
        app/Listeners/Notifications/SendPayrollNotifications.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/PayrollNotificationsTest.php
git commit -m "feat(notifications): wire payroll events (approved/calculated/paid + per-employee SMS)"
```

---

## Task 5: Offboarding notifications (2 events)

**Files:**
- Create: `app/Notifications/OffboardingInitiatedNotification.php`
- Create: `app/Notifications/OffboardingCompletedNotification.php`
- Create: `app/Listeners/Notifications/SendOffboardingNotifications.php`
- Create: `tests/Feature/Notifications/OffboardingNotificationsTest.php`
- Modify: `app/Providers/AppServiceProvider.php` — register 2 events

### Step 1: Write the failing test

Create `tests/Feature/Notifications/OffboardingNotificationsTest.php`:

```php
<?php

use App\Events\OffboardingCompleted;
use App\Events\OffboardingInitiated;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Notifications\OffboardingCompletedNotification;
use App\Notifications\OffboardingInitiatedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake();
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
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Notifications/OffboardingNotificationsTest.php
```
Expected: FAIL.

### Step 3: Create `OffboardingInitiatedNotification`

Create `app/Notifications/OffboardingInitiatedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OffboardingCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingInitiatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OffboardingCase $case) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        $isDeparting = $notifiable?->id === $this->case->employee?->user_id;
        return [
            'kind'    => 'offboarding_initiated',
            'message' => $isDeparting
                ? "Your offboarding has been initiated."
                : "Offboarding initiated for {$name}.",
            'link'    => "/offboarding/{$this->case->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        $isDeparting = $notifiable?->id === $this->case->employee?->user_id;

        $subject = $isDeparting ? 'Your offboarding has been initiated' : "Offboarding initiated — {$name}";
        $line = $isDeparting
            ? 'Your offboarding case has been opened. Please complete the clearance items assigned to you.'
            : "Offboarding has been initiated for {$name}. Please review pending clearance items.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->action('View offboarding case', url("/offboarding/{$this->case->id}"));
    }
}
```

### Step 4: Create `OffboardingCompletedNotification`

Create `app/Notifications/OffboardingCompletedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OffboardingCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OffboardingCase $case) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        return [
            'kind'    => 'offboarding_completed',
            'message' => "Offboarding completed for {$name}.",
            'link'    => "/offboarding/{$this->case->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        return (new MailMessage())
            ->subject("Offboarding completed — {$name}")
            ->line("All clearance items have been completed for {$name}.")
            ->action('View case', url("/offboarding/{$this->case->id}"));
    }
}
```

### Step 5: Create `SendOffboardingNotifications` listener

Create `app/Listeners/Notifications/SendOffboardingNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\OffboardingCompleted;
use App\Events\OffboardingInitiated;
use App\Models\User;
use App\Notifications\OffboardingCompletedNotification;
use App\Notifications\OffboardingInitiatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendOffboardingNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof OffboardingInitiated => $this->onInitiated($event),
            $event instanceof OffboardingCompleted => $this->onCompleted($event),
            default                                => null,
        };
    }

    private function onInitiated(OffboardingInitiated $event): void
    {
        $case = $event->case;
        $departing = $case->employee?->user;
        $manager   = $case->employee?->manager?->user;
        $hr  = $this->holders('employees.manage');
        $it  = $this->holders('assets.manage');

        $recipients = collect(array_filter([$departing, $manager]))->concat($hr)->concat($it)->unique('id');
        $notification = new OffboardingInitiatedNotification($case);
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function onCompleted(OffboardingCompleted $event): void
    {
        $notification = new OffboardingCompletedNotification($event->case);
        foreach ($this->holders('employees.manage') as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

### Step 6: Register events in AppServiceProvider

```php

        // ── N2 notifications: offboarding ──
        Event::listen(\App\Events\OffboardingInitiated::class, \App\Listeners\Notifications\SendOffboardingNotifications::class);
        Event::listen(\App\Events\OffboardingCompleted::class, \App\Listeners\Notifications\SendOffboardingNotifications::class);
```

### Step 7: Run test + commit

```
vendor/bin/pest tests/Feature/Notifications/OffboardingNotificationsTest.php
```
Expected: PASS, 2 tests.

```
git add app/Notifications/OffboardingInitiatedNotification.php \
        app/Notifications/OffboardingCompletedNotification.php \
        app/Listeners/Notifications/SendOffboardingNotifications.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/OffboardingNotificationsTest.php
git commit -m "feat(notifications): wire offboarding events (initiated/completed)"
```

---

## Task 6: Asset notifications (2 events)

**Files:**
- Create: `app/Notifications/AssetAssignedNotification.php`
- Create: `app/Notifications/AssetReturnedNotification.php`
- Create: `app/Listeners/Notifications/SendAssetNotifications.php`
- Create: `tests/Feature/Notifications/AssetNotificationsTest.php`
- Modify: `app/Providers/AppServiceProvider.php` — register 2 events

### Step 1: Write the failing test

Create `tests/Feature/Notifications/AssetNotificationsTest.php`:

```php
<?php

use App\Events\AssetAssigned;
use App\Events\AssetReturned;
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
    Bus::fake();
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
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Notifications/AssetNotificationsTest.php
```
Expected: FAIL.

### Step 3: Create `AssetAssignedNotification`

Create `app/Notifications/AssetAssignedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AssetAssignment $assignment) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $tag = $this->assignment->asset?->asset_tag ?? 'asset';
        return [
            'kind'    => 'asset_assigned',
            'message' => "Asset {$tag} has been assigned to you.",
            'link'    => "/assets/{$this->assignment->asset_id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tag  = $this->assignment->asset?->asset_tag ?? 'asset';
        $name = $this->assignment->asset?->name ?? '';
        return (new MailMessage())
            ->subject("Asset assigned — {$tag}")
            ->line("Asset {$tag} ({$name}) has been assigned to you.")
            ->action('View asset', url("/assets/{$this->assignment->asset_id}"));
    }
}
```

### Step 4: Create `AssetReturnedNotification`

Create `app/Notifications/AssetReturnedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetReturnedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AssetAssignment $assignment) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $tag = $this->assignment->asset?->asset_tag ?? 'asset';
        $isAssignee = $notifiable?->id === $this->assignment->employee?->user_id;
        return [
            'kind'    => 'asset_returned',
            'message' => $isAssignee
                ? "You returned asset {$tag}."
                : "Asset {$tag} was returned.",
            'link'    => "/assets/{$this->assignment->asset_id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tag  = $this->assignment->asset?->asset_tag ?? 'asset';
        $name = $this->assignment->asset?->name ?? '';
        $isAssignee = $notifiable?->id === $this->assignment->employee?->user_id;
        $subject = $isAssignee ? "Asset return confirmed — {$tag}" : "Asset returned — {$tag}";
        $line = $isAssignee
            ? "You have returned asset {$tag} ({$name})."
            : "Asset {$tag} ({$name}) has been returned.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->action('View asset', url("/assets/{$this->assignment->asset_id}"));
    }
}
```

### Step 5: Create `SendAssetNotifications` listener

Create `app/Listeners/Notifications/SendAssetNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\AssetAssigned;
use App\Events\AssetReturned;
use App\Models\User;
use App\Notifications\AssetAssignedNotification;
use App\Notifications\AssetReturnedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendAssetNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof AssetAssigned => $this->onAssigned($event),
            $event instanceof AssetReturned => $this->onReturned($event),
            default                         => null,
        };
    }

    private function onAssigned(AssetAssigned $event): void
    {
        $assignee = $event->assignment->employee?->user;
        if (! $assignee) return;
        $assignee->notify(new AssetAssignedNotification($event->assignment));
    }

    private function onReturned(AssetReturned $event): void
    {
        $assignee  = $event->assignment->employee?->user;
        $itManagers = $this->holders('assets.manage');
        $recipients = collect(array_filter([$assignee]))->concat($itManagers)->unique('id');
        $notification = new AssetReturnedNotification($event->assignment);
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
```

### Step 6: Register events in AppServiceProvider

```php

        // ── N2 notifications: assets ──
        Event::listen(\App\Events\AssetAssigned::class, \App\Listeners\Notifications\SendAssetNotifications::class);
        Event::listen(\App\Events\AssetReturned::class, \App\Listeners\Notifications\SendAssetNotifications::class);
```

### Step 7: Run test + commit

```
vendor/bin/pest tests/Feature/Notifications/AssetNotificationsTest.php
```
Expected: PASS, 2 tests.

```
git add app/Notifications/AssetAssignedNotification.php \
        app/Notifications/AssetReturnedNotification.php \
        app/Listeners/Notifications/SendAssetNotifications.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/AssetNotificationsTest.php
git commit -m "feat(notifications): wire asset events (assigned/returned)"
```

---

## Task 7: Document signed (1 event + dispatch site)

This task is special: the `DocumentSigned` event class exists but isn't dispatched. We add the dispatch in `DocumentService::saveAnnotation()` AND wire the listener.

**Files:**
- Create: `app/Notifications/DocumentSignedNotification.php`
- Create: `app/Listeners/Notifications/SendDocumentNotifications.php`
- Create: `tests/Feature/Notifications/DocumentNotificationsTest.php`
- Modify: `app/Services/DocumentService.php` — add `event(new DocumentSigned(...))` after a signature/initial annotation is saved
- Modify: `app/Providers/AppServiceProvider.php` — register 1 event

### Step 1: Write the failing test

Create `tests/Feature/Notifications/DocumentNotificationsTest.php`:

```php
<?php

use App\Events\DocumentSigned;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentRoute;
use App\Models\User;
use App\Notifications\DocumentSignedNotification;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake();
});

it('dispatches DocumentSigned when DocumentService saves a signature annotation', function () {
    Event::fake([DocumentSigned::class]);

    $owner = User::factory()->create(['role' => 'employee']);
    $signer = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();

    app(DocumentService::class)->saveAnnotation($doc, null, $signer, [
        'type'   => 'signature',
        'page'   => 1,
        'x_pct'  => 10.0,
        'y_pct'  => 20.0,
        'data'   => ['kind' => 'inline'],
    ]);

    Event::assertDispatched(DocumentSigned::class);
});

it('does NOT dispatch DocumentSigned for non-signature annotations', function () {
    Event::fake([DocumentSigned::class]);

    $owner = User::factory()->create(['role' => 'employee']);
    $signer = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();

    app(DocumentService::class)->saveAnnotation($doc, null, $signer, [
        'type'   => 'stamp',
        'page'   => 1,
        'x_pct'  => 10.0,
        'y_pct'  => 20.0,
        'data'   => ['kind' => 'inline'],
    ]);

    Event::assertNotDispatched(DocumentSigned::class);
});

it('notifies the document owner with SMS when DocumentSigned fires', function () {
    $owner = User::factory()->create(['role' => 'employee']);
    $owner->phone = '+233200000099';
    $owner->save();

    $signer = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();
    $annotation = DocumentAnnotation::factory()
        ->for($doc, 'document')
        ->for($signer, 'user')
        ->state(['type' => 'signature'])
        ->create();

    event(new DocumentSigned($doc, $annotation));

    Notification::assertSentTo($owner, DocumentSignedNotification::class);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Notifications/DocumentNotificationsTest.php
```
Expected: FAIL (notification class missing + dispatch site missing).

### Step 3: Create `DocumentSignedNotification`

Create `app/Notifications/DocumentSignedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentSignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly DocumentAnnotation $annotation,
    ) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $signer = $this->annotation->user?->name ?? 'A signer';
        return [
            'kind'    => 'document_signed',
            'message' => "{$signer} signed '{$this->document->title}'.",
            'link'    => "/documents/{$this->document->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $signer = $this->annotation->user?->name ?? 'A signer';
        return (new MailMessage())
            ->subject("'{$this->document->title}' has been signed")
            ->line("{$signer} signed '{$this->document->title}'.")
            ->action('View document', url("/documents/{$this->document->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        $signer = $this->annotation->user?->name ?? 'A signer';
        return "{$signer} signed '{$this->document->title}'.";
    }
}
```

### Step 4: Create `SendDocumentNotifications` listener

Create `app/Listeners/Notifications/SendDocumentNotifications.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\DocumentSigned;
use App\Models\User;
use App\Notifications\DocumentSignedNotification;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendDocumentNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        if ($event instanceof DocumentSigned) {
            $this->onSigned($event);
        }
    }

    private function onSigned(DocumentSigned $event): void
    {
        $owner = $event->document->owner;
        if (! $owner) return;

        $notification = new DocumentSignedNotification($event->document, $event->annotation);
        $owner->notify($notification);

        if ($owner->phone) {
            $this->sms->send(
                toPhone:     $owner->phone,
                body:        $notification->toSmsBody($owner),
                contextType: 'document',
                contextId:   $event->document->id,
            );
        }
    }
}
```

### Step 5: Add the event dispatch in `DocumentService`

Open `app/Services/DocumentService.php`. Find the `saveAnnotation()` method (starts around line 68). Around line 85 it has:

```php
$eventType = match ($attrs['type']) {
    'signature', 'initial' => DocumentEventType::Signed,
    'stamp'                => DocumentEventType::Stamped,
    default                => DocumentEventType::Annotated,
};
```

Immediately AFTER the `$this->logEvent(...)` call that follows that match (around line 91-95), but still inside the `DB::transaction(...)` closure and after `$annotation` is created and logged, add:

```php
        if (in_array($attrs['type'], ['signature', 'initial'], true)) {
            event(new \App\Events\DocumentSigned($doc, $annotation));
        }
```

The dispatch is gated on the same set the `Signed` event-type match uses, so the audit log and the domain event always agree.

### Step 6: Register the event in AppServiceProvider

```php

        // ── N2 notifications: documents ──
        Event::listen(\App\Events\DocumentSigned::class, \App\Listeners\Notifications\SendDocumentNotifications::class);
```

### Step 7: Run test + commit

```
vendor/bin/pest tests/Feature/Notifications/DocumentNotificationsTest.php
```
Expected: PASS, 3 tests.

```
git add app/Notifications/DocumentSignedNotification.php \
        app/Listeners/Notifications/SendDocumentNotifications.php \
        app/Services/DocumentService.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Notifications/DocumentNotificationsTest.php
git commit -m "feat(notifications): wire DocumentSigned event (new dispatch in DocumentService)"
```

---

## Task 8: Final full-suite check + PR

**Files:** none changed in this task

### Step 1: Run the full Pest suite

```
vendor/bin/pest --parallel
```
Expected: ALL PASS, ~1153 tests (current 1131 + 18 new across Tasks 1–7).

If any pre-existing test fails, audit whether it now triggers an unexpected notification side-effect:

- The new listeners only listen to events with `Event::listen()` calls we explicitly added. They don't fire on unrelated events.
- However: existing tests that emit `event(new LoanApproved(...))` in their setup (e.g. for analytics-listener verification) will now also trigger our notification listener. Fix by adding `Notification::fake()` and `Bus::fake()` to those tests' `beforeEach` if they don't already have it. List the failing test files in the report.

### Step 2: Run the Vite build

```
npm run build
```
Expected: `✓ built in <Ns>` clean.

### Step 3: Push the branch and open the PR

```
git push -u origin feat/notifications-n2-event-wiring
gh pr create --title "feat(notifications): N2 — wire 15 missing event notifications" \
  --body-file - <<'EOF'
## Summary

Notifications v2 — Phase N2. Wires 15 dangling domain events (Loans, Benefits, Attendance corrections, Payroll, Offboarding, Assets, Document signed) to module-scoped listeners that dispatch Laravel notifications. Reuses N1's async SMS dispatcher for the 4 SMS-allowlisted events.

## Events wired

- **Loans (3):** Approved, Disbursed (SMS), Fully Repaid (SMS) — applicant + manager / disbursers
- **Benefits (2):** Claim Submitted, Claim Decided — reviewers / claimant
- **Attendance corrections (2):** Requested, Decided — approvers / requester
- **Payroll (3):** Approved, Calculated, Paid (per-employee SMS fan-out) — admins + finance / creator / all employees on the run
- **Offboarding (2):** Initiated, Completed — departing employee + manager + HR + IT / HR
- **Assets (2):** Assigned, Returned — assignee / assignee + IT
- **Documents (1):** Signed (SMS) — document owner. Also adds the missing dispatch site in `DocumentService::saveAnnotation()`.

## Test plan

- [x] Full Pest suite green (`vendor/bin/pest --parallel`) — ~1153 tests
- [x] `npm run build` clean
- [ ] Manual: trigger LoanApproved in tinker, confirm applicant + manager each see a notification in `/notifications`
- [ ] Manual: trigger PayrollRunPaid on a small run, confirm each employee notification, check `sms_messages` for queued SMS rows for phoned employees
- [ ] Manual: sign a document with a signature annotation, confirm DocumentSigned fires and owner receives mail + SMS

## Spec + plan

- Spec: `docs/superpowers/specs/2026-05-28-notifications-n2-event-wiring-design.md`
- Plan: `docs/superpowers/plans/2026-05-28-notifications-n2-event-wiring.md`
EOF
```

### Step 4: Merge once CI is green

```
gh pr merge --squash --delete-branch
git checkout main && git pull --ff-only
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Recipients per the 15-row table | Tasks 1–7 (every event implements its declared recipients) |
| Channel policy DB + mail default, SMS for 4 events | Tasks 1, 4, 7 (the 4 SMS events) |
| One module-listener per logical group, 7 total | Tasks 1, 2, 3, 4, 5, 6, 7 (each task creates one listener) |
| 15 notification classes, one per event | Tasks 1–7 (sum of all notification classes created) |
| AppServiceProvider explicit `Event::listen(...)` per event | Each task's "register events" step |
| `DocumentSigned` event dispatch added in `DocumentService` | Task 7 step 5 |
| Test pattern: `Notification::fake()` + `Bus::fake()` per test | Every test file's `beforeEach` block |
| Suite stays green | Task 8 |
| Push + PR | Task 8 |

All spec items covered.

**Placeholder scan:** No "TBD" / "TODO" / "similar to Task N" references. Every Notification class has its full code block. Every listener has its full code block. Every test has its full code block.

**Type consistency:**

- `SmsDispatcher::send(toPhone:..., body:..., contextType:..., contextId:...)` — same named-arg signature used in Tasks 1, 4, 7.
- `User::whereJsonContains('permissions', '...')` helper pattern used identically in every listener.
- `$loan->employee?->user_id` style null-safe navigation consistent across all `isApplicant`/`isAssignee` checks.
- `$notifiable->email` truthy-check identical in every `via()`.
- `?->name ?? 'A {role}'` fallback pattern consistent across all notification message builders.

No drift found.
