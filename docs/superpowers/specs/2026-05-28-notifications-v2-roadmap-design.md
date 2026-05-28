# Notifications v2 — Roadmap & N1 (Reliability Hardening) Design

## Context

The messaging stack already in place is more mature than this work might suggest:

- **SMS** — pluggable `SmsDispatcher` with Hubtel (default), Twilio (fallback), and Log providers. Persists every send to `sms_messages` with status enum (Queued/Sent/Delivered/Failed/Expired), cost, segments, provider_message_id, and a polymorphic `context_type`/`context_id` link back to whatever triggered it (`payroll`, `leave`, `ar_receipt`, `pin_issue`).
- **Mail** — Laravel mail with `log` default (dev) and SMTP/SES/Postmark/Resend wired and ready.
- **Laravel Notifications** — 11 classes; all `ShouldQueue`; channels include `database`, `mail`, plus some with custom SMS-via-dispatcher legs. The `notifications` table backs an in-app bell UI (`NotificationBell.vue` + `Pages/Notifications`).
- **Per-user channel preferences** — `/notifications/channels` page toggles email/in-app/WhatsApp/Slack/Teams per-user, stored on `users.notification_channels` (JSON).
- **Admin messaging surface** — `/admin/messaging` shows outbound + inbound SMS history, one-off send, USSD PIN issue.

What's missing is reliability + reach. Specifically:

- `SmsDispatcher::send()` calls Hubtel **synchronously** from the request thread. A typical "approve leave" click waits for the provider round-trip; a Hubtel hiccup leaks 4xx/5xx into the controller's response.
- A code comment promises a retry sweep — none exists.
- ~15 domain events fire and notify nobody (loan approved, attendance correction decided, benefit claim approved/rejected, payroll run approved/paid, offboarding initiated, asset assigned/returned, document signed/completed/rejected, ticket created/resolved).
- There's no admin tool for outreach — broadcast SMS or scheduled "Annual Dues are now billed" announcements.

This work is bundled as **Notifications v2**, decomposed into three sequential phases. The phases have a real dependency order: hardening must precede wiring, which must precede broadcast.

## Roadmap

### N1 — Reliability Hardening (this spec)

Make `SmsDispatcher::send()` async by default with proper retries, a stuck-row sweep, persistent-failure alerting, and registered rate limiters that N3 will use.

**Out:** mail reliability (mail goes through Laravel's existing queued-notification path and is already async — verified at audit time).

### N2 — Wire Missing Event Notifications (future spec)

For each of the ~15 dangling domain events, build a Notification class (DB + mail + optional SMS via the now-reliable dispatcher) and a listener. Each event gets one ~30-line listener and one ~80-line Notification + one test. Aim for a single PR that lands all of them together, since they share patterns.

**Examples of events to wire:**

- `LoanApproved`, `LoanDisbursed`, `LoanFullyRepaid` → applicant + line manager
- `AttendanceCorrectionRequested`, `AttendanceCorrectionDecided` → requester + approver
- `BenefitClaimSubmitted`, `BenefitClaimDecided` → claimant + finance
- `PayrollRunApproved`, `PayrollRunPaid` → employees on the run + HR
- `OffboardingInitiated`, `OffboardingCompleted` → employee + HR + IT
- `AssetAssigned`, `AssetReturned` → assignee
- `DocumentSigned`, `DocumentCompleted`, `DocumentRejected` → next signer + originator
- `TicketCreated`, `TicketResolved` → submitter + assignee

### N3 — Admin Broadcast Tools (future spec)

Build send-all / send-to-class / scheduled-send / saveable-templates on top of N1 + N2. Surface lives under the existing `/admin/messaging` page. Marketing context dispatches go through the `sms:marketing` rate limiter registered in N1.

---

## N1 — Reliability Hardening (this phase)

### Goals

1. Every SMS send is async — the user's HTTP request never waits on Hubtel.
2. Transient provider failures retry automatically with backoff. Permanent failures (bad phone, invalid sender) do not.
3. Rows that get stuck in `Queued` because a worker died mid-job are picked back up.
4. When a send permanently fails (exhausted retries), users holding `messaging.manage` get notified.
5. Rate limiters are registered for the broadcast path that N3 will use, even though N1 itself doesn't hit them on the transactional path.

### Architecture

```
caller code (existing, unchanged signature)
        │
        ▼
SmsDispatcher::send($to, $body, $context)
        │
        ├─ insert SmsMessage row (status=Queued)
        └─ dispatch SendSmsJob → return row
                  │
                  ▼
       SendSmsJob::handle()    ← runs on `database` queue
       ├─ refresh row (idempotency)
       ├─ call provider sync via SmsDispatcher::send(..., sync: true)
       ├─ on TransientSmsFailure → throw → Laravel retries w/ backoff [60s, 5m, 15m]
       ├─ on PermanentSmsFailure → mark Failed, no retry
       └─ on success → row updated to Sent/Delivered

  failed() callback after tries exhausted
       └─ mark row Failed, notify users w/ messaging.manage (SmsDispatchExhausted)

scheduler (every 5m):
    messaging:sweep-stuck-sms
    ├─ find SmsMessage where status=Queued AND created_at < now()-10m
    └─ re-dispatch SendSmsJob (belt-and-braces for crashed workers)

RateLimiters registered (N1 leaves them dormant; N3 uses them):
    'sms:transactional:'.$phone → unlimited (registered for symmetry)
    'sms:marketing:'.$phone → 5 per hour
```

### Components

| File | Change | Responsibility |
|---|---|---|
| `app/Jobs/Messaging/SendSmsJob.php` | **NEW** | Queueable wrapper around the sync send path. `public $tries = 3; public $backoff = [60, 300, 900];`. Implements `handle()` and `failed()`. |
| `app/Console/Commands/SweepStuckSmsCommand.php` | **NEW** | `messaging:sweep-stuck-sms`. Finds rows in `Queued` state older than 10 minutes and re-dispatches `SendSmsJob` for each. Logs counts. |
| `routes/console.php` | MODIFY | Add `Schedule::command('messaging:sweep-stuck-sms')->everyFiveMinutes()->withoutOverlapping();` |
| `app/Notifications/SmsDispatchExhausted.php` | **NEW** | Channels: `database`, `mail`. Recipients: all users with `messaging.manage`. Body: phone, context type/id, last failure reason, link to `/admin/messaging`. |
| `app/Services/Messaging/Sms/Exceptions/PermanentSmsFailure.php` | **NEW** | Thrown on 4xx-with-bad-input from provider. Job sees it and skips retries. |
| `app/Services/Messaging/Sms/Exceptions/TransientSmsFailure.php` | **NEW** | Thrown on 5xx/timeout/network from provider. Job sees it and triggers retry. |
| `app/Services/Messaging/Sms/SmsDispatcher.php` | MODIFY | `send()` becomes async by default. Adds `sync: bool = false` named arg for in-job invocation and tests. Provider exceptions classified into Permanent/Transient before re-throwing. |
| `app/Services/Messaging/Sms/Providers/HubtelSmsProvider.php` | MODIFY | Map Hubtel API response codes to Permanent vs Transient. |
| `app/Services/Messaging/Sms/Providers/TwilioSmsProvider.php` | MODIFY | Map Twilio HTTP statuses to Permanent vs Transient. |
| `app/Providers/AppServiceProvider.php` | MODIFY | Register `RateLimiter::for('sms:transactional', …)` and `RateLimiter::for('sms:marketing', …)`. |
| `tests/Feature/Messaging/SendSmsJobTest.php` | **NEW** | Test dispatch + success + retry behaviour. |
| `tests/Feature/Messaging/SweepStuckSmsTest.php` | **NEW** | Insert stale Queued row; run sweep; assert re-dispatch. |
| `tests/Feature/Messaging/SmsExhaustedAlertTest.php` | **NEW** | Force exhausted retries; assert `SmsDispatchExhausted` sent. |
| `tests/Feature/Messaging/SmsAsyncBehaviourTest.php` | **NEW** | Call `SmsDispatcher::send()`; assert row Queued + job dispatched; run worker; assert flips to Sent. |

### Data flow

1. **Caller invocation (unchanged signature).** Existing code `app(SmsDispatcher::class)->send($to, $body, $ctx)` now:
   - Creates `SmsMessage` row with `status=Queued`.
   - Dispatches `SendSmsJob` onto the default queue (`database`).
   - Returns the `SmsMessage` model. Caller has a handle to track status.

2. **Job execution.** `SendSmsJob::handle()`:
   - Refreshes the `SmsMessage` row in case a parallel worker already processed it; if `status != Queued`, return early (idempotency).
   - Calls `SmsDispatcher::send($to, $body, $ctx, sync: true)` — the synchronous path that talks to the provider directly.
   - On `TransientSmsFailure`: rethrows. Laravel's queue worker catches, increments attempts, and re-enqueues with the next backoff value.
   - On `PermanentSmsFailure`: catches it, marks the row `Failed` with the failure reason, returns (no retry).
   - On success: provider call updates the row to `Sent`, and a later delivery webhook flips it to `Delivered`.

3. **Final-failure path.** `SendSmsJob::failed(Throwable $e)`:
   - Marks the row `Failed` with `$e->getMessage()` as failure reason.
   - Sends `SmsDispatchExhausted` to all users with `messaging.manage`.

4. **Stuck-row sweep.** `messaging:sweep-stuck-sms`:
   - Queries `SmsMessage::where('status', 'Queued')->where('created_at', '<', now()->subMinutes(10))->get()`.
   - For each: dispatches a fresh `SendSmsJob` (which is idempotent via the `status != Queued` early-return).
   - Logs `info("Swept N stuck SMS rows")`.
   - Runs every 5 minutes via the scheduler with `withoutOverlapping()` so a long sweep doesn't double-fire.

### Error handling

| Failure mode | Behaviour |
|---|---|
| Hubtel 4xx with bad-phone error code | `PermanentSmsFailure` → row Failed immediately, no retry |
| Hubtel 4xx with auth/credential error | `PermanentSmsFailure` → row Failed, alert fires (clearly an ops problem) |
| Hubtel 5xx / network timeout | `TransientSmsFailure` → retried at +60s, +5m, +15m |
| Worker process killed during `provider->send()` | Job goes back to queue automatically by Laravel; sweep catches it after 10m if the queue is itself broken |
| All 3 retries exhausted | `failed()` runs, alert fires, row left in Failed for `/admin/messaging` to surface |
| Caller passes invalid input (empty body, malformed phone) | `InvalidArgumentException` from `SmsDispatcher::send()`, surfaced to caller (these are bugs, not delivery failures) |

### Testing

| Test | Asserts |
|---|---|
| `SmsAsyncBehaviourTest` | `SmsDispatcher::send()` queues a job and returns immediately with `status=Queued`; running the worker flips to `Sent`. |
| `SendSmsJobTest` | Mocks provider to throw `TransientSmsFailure`; asserts job retried 3 times; asserts `failed()` runs after exhaustion. Mocks provider to throw `PermanentSmsFailure`; asserts row Failed immediately, no retry. |
| `SweepStuckSmsTest` | Inserts a `Queued` row backdated 15 minutes; calls `Artisan::call('messaging:sweep-stuck-sms')`; asserts `SendSmsJob` re-dispatched. |
| `SmsExhaustedAlertTest` | Triggers 3 transient failures; asserts `SmsDispatchExhausted` sent to all users with `messaging.manage` perm; asserts non-messaging-managers receive nothing. |

Full suite (currently 1099 tests) must remain green. New tests bring total to ~1103.

### Migration / rollout

- **No DB migrations.** The `sms_messages` schema already has every field needed.
- **No breaking API changes.** Callers don't change. The only observable difference is `status=Queued` for ~1s before `Sent`.
- **Queue worker required in production.** This is already true today (the existing 11 queued notifications + `ProcessPaystackWebhook` already depend on it). Verify supervisor is running `php artisan queue:work --queue=default`.
- **Scheduler required in production.** This is already true today (other scheduled commands exist). Verify cron points at `php artisan schedule:run`.

### Out of scope (explicit non-goals)

- **Mail reliability.** All 11 mail-channel notifications already implement `ShouldQueue`. Failure modes there are Laravel's queue retry mechanism, which we're already trusting. Defer any mail-specific work.
- **Per-event notification opt-out.** Channel preferences exist; per-event toggles are a future phase or a deliberate non-feature.
- **Provider failover (Hubtel → Twilio).** Real, but a separate piece of work. The current provider is selected at dispatcher-construction time; runtime failover changes the dispatcher's responsibility and warrants its own design.
- **Marketing throttle enforcement.** The rate limiter is registered in N1 so callers in N3 inherit it ready-to-use. N1 itself only fires through the transactional path which bypasses the limiter.

### Risks

- **Workers must keep running.** If supervisor breaks, SMS silently piles up in `Queued` until the sweep catches it. Mitigation: existing monitoring alerts on queue depth. (Verify the supervisor unit and the queue-depth alert during rollout.)
- **Sweep races with workers.** A row picked up by the sweep at second 599 while the original worker is mid-call could double-send. Mitigation: idempotency check in `handle()` (`if status != Queued return`).
- **`SmsDispatchExhausted` storm.** A Hubtel outage takes every SMS in the system into the failed-alert path; admins get spammed. Mitigation: rate-limit the alert notification itself — at most one `SmsDispatchExhausted` per recipient per 15 minutes (cache key on the notifiable).

---

## Open questions deferred

These don't block N1; they're flagged for N2/N3 design.

- **Per-event opt-out granularity** — does N3 need per-event toggles or does the current per-channel toggle suffice?
- **WhatsApp/Slack/Teams parity** — N2 may want a unified "MessagingDispatcher" abstraction (one already exists for Slack/WhatsApp via `MessagingDispatcher`); whether to fold SMS into it or keep them parallel.
- **Bounce/unsubscribe handling** — none today. Mostly an N3 concern when broadcast volume rises.
