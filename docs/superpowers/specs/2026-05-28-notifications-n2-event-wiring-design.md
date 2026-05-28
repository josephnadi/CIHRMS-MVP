# Notifications N2 — Wire Missing Event Notifications

## Context

Notifications v2 phase 1 (N1, PR #71) shipped the reliability foundation: async-by-default SMS, retry classification, stuck-row sweep, exhausted-retry admin alerts, and rate limiters registered for N3 to inherit.

The audit completed during N1's brainstorming flagged ~15 domain events that fire but reach nobody — users in the affected modules learn about loan approvals, attendance corrections, benefit decisions, payroll milestones, offboarding, asset moves, and document-signing only by checking the UI manually. The infrastructure (Laravel notifications, mail driver, SMS dispatcher, queued delivery) is mature; the wiring isn't.

A re-audit at the start of N2 brainstorming confirmed:

- 14 events with no notification listener (some are analytics-only; some have no listener at all).
- 1 event (`DocumentSigned`) whose dispatch site doesn't exist yet — `DocumentRoutingService` transitions a signature to signed but never fires the event. The dispatch needs to be added.
- Some events the prior gap list mentioned (`DocumentCompleted`, `DocumentRejected`, `TicketAssigned`) already dispatch notifications directly from their services and don't need new wiring.

This spec covers a single shippable phase: wire all 15 events, picking up the N1 dispatcher for SMS where it's worth it.

## Goals

1. Every event in the table below dispatches a notification to its declared recipients.
2. The work ships as one cohesive PR — patterns repeat across modules and reading them side-by-side helps reviewers.
3. No new abstractions beyond a thin "one listener per module" convention. Each notification is a standard Laravel `Notification` subclass.
4. SMS use is selective (allowlist of 4 events) so Hubtel spend stays predictable and low-signal channels don't compete with high-signal ones.

## Non-goals

- Per-event user opt-out preferences. Deferred.
- Admin broadcast / template editor. That's N3.
- Mail Blade templates / branded HTML. Keep `MailMessage` fluent chain consistent with the existing 12 notification classes.
- Provider failover, WhatsApp/Slack/Teams parity with SMS. Not in scope.

## Recipient model

| Event | Recipients | Source |
|---|---|---|
| `LoanApproved` | Applicant (employee) + their line manager | `LoanService::approve()` |
| `LoanDisbursed` | Applicant + users with `loans.disburse` | `LoanService::disburse()` |
| `LoanFullyRepaid` | Applicant | `LoanService::recordRepayment()` |
| `AttendanceCorrectionRequested` | Line manager + users with `attendance.approve` | `AttendanceService::requestCorrection()` |
| `AttendanceCorrectionDecided` | Requester | `AttendanceService::approveCorrection/rejectCorrection()` |
| `BenefitClaimSubmitted` | Users with `benefits.manage` | `BenefitsService::submitClaim()` |
| `BenefitClaimDecided` | Claimant | `BenefitsService::decideClaim()` |
| `PayrollRunApproved` | Users with `payroll.manage` + users with `finance.hub` | `PayrollService::approve()` |
| `PayrollRunCalculated` | The user who triggered the run (`$event->triggeredBy`) | `PayrollService::calculate()` |
| `PayrollRunPaid` | All employees on the run (via `$run->payslips`) | `PayrollService::markPaid()` |
| `OffboardingInitiated` | Departing employee + line manager + users with `employees.manage` + users with `assets.manage` | `OffboardingService::initiate()` |
| `OffboardingCompleted` | Users with `employees.manage` | `OffboardingService::complete()` |
| `AssetAssigned` | Assignee | `AssetService::assign()` |
| `AssetReturned` | Assignee + users with `assets.manage` | `AssetService::return()` |
| `DocumentSigned` | Document originator + next signer in workflow | `DocumentRoutingService::recordSignature()` (new dispatch) |

## Channel policy

Default for every event: `database` (in-app bell + inbox) + `mail` (only if recipient has an email address).

SMS is in addition, for these 4 events only:

- `LoanDisbursed`
- `LoanFullyRepaid`
- `PayrollRunPaid`
- `DocumentSigned`

These are the "money in / money committed / legal binding" moments. SMS for everything else would compete with these high-signal messages.

SMS dispatch path: inside the listener, **not** via a Laravel channel class. The N1 dispatcher (`App\Services\Messaging\Sms\SmsDispatcher`) is invoked directly with the notification's `toSmsBody($notifiable)` result. Matches the existing `SendPaymentReceiptNotification` pattern.

## Architecture

```
domain service emits event
        │
        ▼
AppServiceProvider::boot() registers Event::listen(EventClass, ListenerClass)
        │  (15 register calls, one per event)
        ▼
SendModuleNotifications::handle($event)     ← module-scoped (7 listener classes)
    ├─ branch on $event instanceof to determine recipients + notification
    └─ foreach recipient:
        ├─ $recipient->notify(new EventNotification($event))   (DB + mail)
        └─ if event class ∈ SMS_EVENTS AND $recipient->phone:
            app(SmsDispatcher::class)->send(
                toPhone:     $recipient->phone,
                body:        $notification->toSmsBody($recipient),
                contextType: 'loan' | 'payroll' | 'document',
                contextId:   $event->subject->id,
            )

EventNotification extends Notification implements ShouldQueue
    via($notifiable): array
        → ['database', 'mail'] if $notifiable->email, else ['database']
    toDatabase($notifiable): array
        → {kind, message, link}
    toMail($notifiable): MailMessage
        → MailMessage chain; branches actor vs audience phrasing where both sides receive
    toSmsBody($notifiable): string
        → short SMS body (~140 chars). Only invoked from the listener for SMS_EVENTS.
```

The dispatcher's async behaviour (from N1) means every SMS becomes a `SendSmsJob` on the database queue. Laravel notifications with `ShouldQueue` already ride the same queue. Both ride through N1's retry, exhaustion-alert, and sweep machinery.

## Components

### Listeners (new, 7 files under `app/Listeners/Notifications/`)

- `SendLoanNotifications` — LoanApproved, LoanDisbursed, LoanFullyRepaid
- `SendBenefitsNotifications` — BenefitClaimSubmitted, BenefitClaimDecided
- `SendAttendanceCorrectionNotifications` — AttendanceCorrectionRequested, AttendanceCorrectionDecided
- `SendPayrollNotifications` — PayrollRunApproved, PayrollRunCalculated, PayrollRunPaid
- `SendOffboardingNotifications` — OffboardingInitiated, OffboardingCompleted
- `SendAssetNotifications` — AssetAssigned, AssetReturned
- `SendDocumentNotifications` — DocumentSigned

Listener shape (illustrative for the loan module):

```php
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
    private const SMS_EVENTS = [LoanDisbursed::class, LoanFullyRepaid::class];

    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof LoanApproved      => $this->onApproved($event),
            $event instanceof LoanDisbursed     => $this->onDisbursed($event),
            $event instanceof LoanFullyRepaid   => $this->onRepaid($event),
            default                              => null,
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

    private function onDisbursed(LoanDisbursed $event): void { /* same pattern + SMS */ }
    private function onRepaid(LoanFullyRepaid $event): void { /* same pattern + SMS */ }

    private function sendSmsIf(string $eventClass, $recipient, $notification, $contextType, int $contextId): void
    {
        if (! in_array($eventClass, self::SMS_EVENTS, true)) return;
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

Each module listener follows the same skeleton: `match` on event type, route to a private method per event, send the notification to each recipient, optionally fire an SMS.

### Notifications (new, 15 files under `app/Notifications/`)

- `LoanApprovedNotification`, `LoanDisbursedNotification`, `LoanFullyRepaidNotification`
- `AttendanceCorrectionRequestedNotification`, `AttendanceCorrectionDecidedNotification`
- `BenefitClaimSubmittedNotification`, `BenefitClaimDecidedNotification`
- `PayrollRunApprovedNotification`, `PayrollRunCalculatedNotification`, `PayrollRunPaidNotification`
- `OffboardingInitiatedNotification`, `OffboardingCompletedNotification`
- `AssetAssignedNotification`, `AssetReturnedNotification`
- `DocumentSignedNotification`

Shape (illustrative — `LoanApprovedNotification`):

```php
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
                ? "Your loan request of GHS {$this->loan->principal} has been approved."
                : "{$this->loan->employee->user->name}'s loan request of GHS {$this->loan->principal} has been approved.",
            'link'    => route('loans.show', $this->loan->id),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $applicant = $this->isApplicant($notifiable);
        $subject   = $applicant ? 'Your loan request has been approved' : 'Loan approval notice';
        $line1     = $applicant
            ? "Your loan request of GHS {$this->loan->principal} has been approved."
            : "{$this->loan->employee->user->name}'s loan request of GHS {$this->loan->principal} has been approved.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line1)
            ->line("Reference: {$this->loan->reference}")
            ->action('View loan', route('loans.show', $this->loan->id));
    }

    /** For events on the SMS_EVENTS allowlist. Returns null otherwise. */
    public function toSmsBody(mixed $notifiable): string
    {
        return "Your loan {$this->loan->reference} has been approved.";
    }

    private function isApplicant(mixed $notifiable): bool
    {
        return $notifiable?->id === $this->loan->employee?->user_id;
    }
}
```

The actor-vs-audience switch lives entirely inside `toMail()` and `toDatabase()`, gated by the simple `isApplicant()` helper that compares notifiable id to the event subject. No notification subclasses per audience needed.

### Event registration

`app/Providers/AppServiceProvider.php` `boot()` gains 15 `Event::listen(...)` registrations, grouped by module with a `// ── X notifications ──` comment header per group. Mirrors the existing `Event::listen(LeaveStatusUpdated::class, SendNotifications::class)` pattern around lines 307–355.

### Service-layer dispatch (1 add)

`DocumentRoutingService::recordSignature()` (or whatever method transitions a signature row to signed — confirm during implementation by grepping for the signature transition) adds a single line:

```php
event(new DocumentSigned($document, $signer));
```

`App\Events\DocumentSigned` is created with `__construct(public readonly Document $document, public readonly User $signer)`. The existing `Document` and `User` models already cover the payload.

### Tests (new, ~22 files under `tests/Feature/Notifications/`)

One test file per module listener, with multiple test cases per file:

- `LoanNotificationsTest.php` — 5 cases: each event × each recipient role, plus the SMS-allowlist branch
- `BenefitNotificationsTest.php` — 3 cases
- `AttendanceCorrectionNotificationsTest.php` — 3 cases
- `PayrollNotificationsTest.php` — 4 cases (PayrollRunPaid fan-out gets a multi-employee assertion)
- `OffboardingNotificationsTest.php` — 5 cases
- `AssetNotificationsTest.php` — 3 cases
- `DocumentNotificationsTest.php` — 2 cases (dispatch verification + recipient routing)

Pattern:

```php
beforeEach(function () {
    Notification::fake();
    Bus::fake();          // catch the SmsDispatcher's SendSmsJob from N1
});

it('notifies the applicant when LoanApproved fires', function () {
    $applicant = User::factory()->create(['role' => 'employee']);
    $employee  = Employee::factory()->for($applicant, 'user')->create();
    $loan      = LoanAccount::factory()->for($employee)->create();

    event(new LoanApproved($loan));

    Notification::assertSentTo($applicant, LoanApprovedNotification::class);
});
```

The 22 tests will lift the suite from 1131 to ~1153.

## Data flow examples

**LoanDisbursed (SMS allowlist):**

1. `LoanService::disburse($loan)` calls `event(new LoanDisbursed($loan))`.
2. `SendLoanNotifications::handle()` matches the type, routes to `onDisbursed()`.
3. Resolves applicant (employee → user) and disbursers (`users` with `loans.disburse` perm).
4. For each recipient: `notify(new LoanDisbursedNotification($loan))` (DB + mail if email).
5. For each recipient with a phone: `app(SmsDispatcher::class)->send($recipient->phone, $notification->toSmsBody($recipient), contextType: 'loan', contextId: $loan->id)`.
6. SmsDispatcher (N1 async-by-default) inserts an `SmsMessage` row Queued + dispatches `SendSmsJob`. Worker delivers async.
7. If Hubtel returns transient failure, N1's retry kicks in (up to 3 attempts, [60s, 5m, 15m] backoff). If exhausted, N1's `SmsDispatchExhausted` alert fires to `messaging.manage` holders.

**PayrollRunPaid (fan-out):**

1. `PayrollService::markPaid($run)` calls `event(new PayrollRunPaid($run))`.
2. `SendPayrollNotifications::handle()` routes to `onPaid()`.
3. Iterates `$run->payslips` (could be hundreds), resolves each `$payslip->employee->user`.
4. For each: `notify(new PayrollRunPaidNotification($payslip))` (DB + mail).
5. For each with a phone: SMS body "Your {month} payslip is available. Net: GHS {amount}."
6. The fan-out is naturally serialised by Laravel's queue — each notification becomes a queued job; each SMS becomes a `SendSmsJob`. Workers drain at their own pace; no spike on the request thread.

## Error handling

- **Recipient has no user/email**: notification class's `via()` falls back to `['database']`. No mail attempted.
- **Recipient has no phone**: listener's `sendSmsIf()` returns early before calling the dispatcher. No row created.
- **Notification job fails**: Laravel's queue retries the notification job per Laravel's defaults (no custom retry policy added here). Mail provider failures are Laravel's concern.
- **SMS dispatch fails**: N1 machinery handles it. The listener doesn't need to catch.
- **Event dispatched in a `DB::transaction` that rolls back**: Laravel events still fire even if the wrapping transaction rolls back (no transactional event listener support in stock Laravel). This is consistent with how the existing notifications behave (e.g. `LeaveStatusUpdated`). Documented as a known limitation.

## Migration / rollout

- **No DB migrations.** All recipients exist in `users`, `employees`, `loan_accounts`, `payslips`, etc.
- **No breaking changes.** The 14 events that already fire continue to fire; we add listeners. The 1 new dispatch site (`DocumentSigned`) adds a fire that has no downstream consumers until the listener also lands. Safe to deploy.
- **Queue worker required.** Already a production requirement (N1 + the existing 11 queued notifications). No change.
- **Rollout order:** listeners + notifications + tests + service dispatch land together in one PR. No feature flag — these are user-visible improvements that don't need a gradual rollout.

## Risks

- **`PayrollRunPaid` fan-out volume.** A run with 500 employees produces 500 DB notifications + 500 mail jobs + ~500 SMS jobs (assuming most have phones). The worker drains at queue speed. Pre-flight: confirm queue worker concurrency in supervisor config supports the burst, OR accept that drain takes ~minutes (acceptable for payroll). Mitigated naturally by N1's async-by-default behaviour.
- **Notification-class identity vs notifiable type.** A few of the audience-vs-actor branches compare `$notifiable->id` to `$event->loan->employee->user_id`. Implicit assumption: the notifiable is always a `User`. Verified during implementation — every recipient resolver returns `User` instances.
- **Test pollution.** The 7 new listeners listen to events that other tests dispatch (e.g. payroll tests fire `PayrollRunApproved` and don't currently expect a notification side-effect). `Notification::fake()` per test prevents pollution. `Bus::fake()` per test catches the dispatcher's SendSmsJob. Confirmed against the existing `LeaveStatusChanged` test patterns.

## Verification

End-to-end tests under `tests/Feature/Notifications/*`. Pattern shown above. Cover:

1. Each event → each declared recipient gets the right notification class.
2. SMS-allowlist events dispatch a `SendSmsJob` for recipients with phones (asserted via `Bus::assertDispatched(SendSmsJob::class, ...)`).
3. Recipients without a phone don't trigger a SMS dispatch.
4. Actor-vs-audience phrasing branch produces the right `toMail()` subject/body (asserted via `$notification->toMail($notifiable)->subject`).
5. `DocumentSigned` event dispatches on `recordSignature()` (asserted via `Event::assertDispatched(...)` in `DocumentRoutingTest`).

After the suite is green, run `php artisan test --parallel` — expect ~1153 tests (currently 1131 + ~22 new).

## Out of scope reminder

- **N3 admin broadcast tools** (next phase). N2 deliberately uses the `transactional` SMS bypass, not the `marketing` limiter — broadcasts will be the only path that hits the `sms:marketing` rate limit.
- **Per-event opt-out.** Not adding `notification_preferences` granularity per event in N2. The existing channel-level toggle on `users.notification_channels` still applies (skipping mail/SMS for users who turned them off globally is honoured by the existing channel adapters).
- **MessagingDispatcher (Slack/Teams/WhatsApp) parity.** N2 ships DB+mail+selective SMS. Slack/Teams/WhatsApp routing for these events is a separate piece of work.
