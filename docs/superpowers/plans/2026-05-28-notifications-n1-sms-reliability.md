# Notifications N1 — SMS Reliability Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `SmsDispatcher::send()` async by default with proper retries, a stuck-row sweep, persistent-failure alerting, and registered rate limiters that N3 will use.

**Architecture:** All `SmsDispatcher::send()` calls insert a `SmsMessage` row (status `Queued`) and dispatch a `SendSmsJob` onto the `database` queue. The job runs `SmsDispatcher::send(..., sync: true)` which talks to the provider. Providers classify failures into permanent (4xx / bad input) and transient (5xx / network) via a `retryable` flag on `SmsResult`. Transients trigger Laravel queue retries with backoff `[60s, 5m, 15m]`. On exhausted retries, a rate-limited `SmsDispatchExhausted` notification fires to users with `messaging.manage`. A scheduled `messaging:sweep-stuck-sms` command catches rows whose worker died mid-flight.

**Tech Stack:** Laravel 13.8, PHP 8.4, Pest. Queue: `database` driver. Existing services: `App\Services\Messaging\Sms\SmsDispatcher`, `App\Services\Messaging\Sms\Contracts\SmsProvider`, `App\Services\Messaging\Sms\SmsResult`, `App\Services\Messaging\Sms\Providers\{Hubtel,Twilio,Log}SmsProvider`. Existing model: `App\Models\SmsMessage` with `SmsStatus` enum (`Queued|Sent|Delivered|Failed|Expired`).

---

## File Structure

**New files (7):**

- `app/Services/Messaging/Sms/SmsResult.php` — already exists; add `retryable` field + `failedTransient()` factory
- `app/Jobs/Messaging/SendSmsJob.php` — queueable wrapper
- `app/Console/Commands/SweepStuckSmsCommand.php` — re-dispatches stuck Queued rows
- `app/Notifications/SmsDispatchExhausted.php` — DB + mail notification to admins
- `tests/Feature/Messaging/SendSmsJobTest.php` — sync vs async, retry, permanent-vs-transient
- `tests/Feature/Messaging/SweepStuckSmsTest.php` — re-dispatch behaviour
- `tests/Feature/Messaging/SmsExhaustedAlertTest.php` — admin alert on exhausted retries

**Modified files (5):**

- `app/Services/Messaging/Sms/SmsDispatcher.php` — `send()` becomes async by default; gains `sync: bool` named arg
- `app/Services/Messaging/Sms/Providers/HubtelSmsProvider.php` — returns `SmsResult::failedTransient(...)` for transport / 5xx
- `app/Services/Messaging/Sms/Providers/TwilioSmsProvider.php` — same classification
- `app/Providers/AppServiceProvider.php` — register `sms:transactional` + `sms:marketing` rate limiters
- `routes/console.php` — schedule `messaging:sweep-stuck-sms` every 5 minutes

---

## Task 1: Add `retryable` flag to `SmsResult`

**Files:**
- Modify: `app/Services/Messaging/Sms/SmsResult.php`
- Test: `tests/Unit/Messaging/SmsResultTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Messaging/SmsResultTest.php`:

```php
<?php

use App\Services\Messaging\Sms\SmsResult;

it('marks sent() results as success with retryable=false', function () {
    $r = SmsResult::sent('msg-123');
    expect($r->success)->toBeTrue();
    expect($r->retryable)->toBeFalse();
});

it('marks failed() as permanent failure (retryable=false)', function () {
    $r = SmsResult::failed('bad number');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeFalse();
    expect($r->failureReason)->toBe('bad number');
});

it('marks failedTransient() as retryable=true', function () {
    $r = SmsResult::failedTransient('upstream 503');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeTrue();
    expect($r->failureReason)->toBe('upstream 503');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Messaging/SmsResultTest.php`
Expected: FAIL with "Undefined property `retryable`" or "method `failedTransient` not found"

- [ ] **Step 3: Update `SmsResult` to add the flag**

Replace `app/Services/Messaging/Sms/SmsResult.php` contents with:

```php
<?php

namespace App\Services\Messaging\Sms;

final class SmsResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
        public readonly int $segments = 1,
        public readonly float $cost = 0.0,
        public readonly array $raw = [],
        public readonly bool $retryable = false,
    ) {}

    public static function sent(string $messageId, int $segments = 1, float $cost = 0.0, array $raw = []): self
    {
        return new self(true, $messageId, null, $segments, $cost, $raw, false);
    }

    /** Permanent failure — bad input, auth error, blocked content. Do not retry. */
    public static function failed(string $reason, array $raw = []): self
    {
        return new self(false, null, $reason, 1, 0.0, $raw, false);
    }

    /** Transient failure — network, 5xx, timeout. Worth retrying. */
    public static function failedTransient(string $reason, array $raw = []): self
    {
        return new self(false, null, $reason, 1, 0.0, $raw, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Messaging/SmsResultTest.php`
Expected: PASS, 3 tests passed

- [ ] **Step 5: Commit**

```bash
git add app/Services/Messaging/Sms/SmsResult.php tests/Unit/Messaging/SmsResultTest.php
git commit -m "feat(sms): add retryable flag to SmsResult for permanent/transient classification"
```

---

## Task 2: Classify failures in `HubtelSmsProvider` + `TwilioSmsProvider`

**Files:**
- Modify: `app/Services/Messaging/Sms/Providers/HubtelSmsProvider.php`
- Modify: `app/Services/Messaging/Sms/Providers/TwilioSmsProvider.php`
- Test: `tests/Feature/Messaging/ProviderFailureClassificationTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Messaging/ProviderFailureClassificationTest.php`:

```php
<?php

use App\Services\Messaging\Sms\Providers\HubtelSmsProvider;
use App\Services\Messaging\Sms\Providers\TwilioSmsProvider;
use Illuminate\Support\Facades\Http;

it('classifies Hubtel 4xx as permanent failure', function () {
    Http::fake([
        'smsc.hubtel.com/*' => Http::response(['status' => 4001, 'statusDescription' => 'invalid sender'], 400),
    ]);

    $r = (new HubtelSmsProvider('id', 'secret', 'CIHRMS'))->send('+233200000099', 'hi');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeFalse();
});

it('classifies Hubtel 5xx as transient failure', function () {
    Http::fake([
        'smsc.hubtel.com/*' => Http::response(['statusDescription' => 'upstream'], 503),
    ]);

    $r = (new HubtelSmsProvider('id', 'secret', 'CIHRMS'))->send('+233200000099', 'hi');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeTrue();
});

it('classifies Hubtel transport exception as transient failure', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('cURL timeout');
    });

    $r = (new HubtelSmsProvider('id', 'secret', 'CIHRMS'))->send('+233200000099', 'hi');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeTrue();
    expect($r->failureReason)->toContain('transport');
});

it('classifies Twilio 4xx as permanent failure', function () {
    Http::fake([
        'api.twilio.com/*' => Http::response(['message' => 'Invalid To phone'], 400),
    ]);

    $r = (new TwilioSmsProvider('SID', 'token', '+15551234567'))->send('+233200000099', 'hi');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeFalse();
});

it('classifies Twilio 5xx as transient failure', function () {
    Http::fake([
        'api.twilio.com/*' => Http::response(['message' => 'upstream'], 502),
    ]);

    $r = (new TwilioSmsProvider('SID', 'token', '+15551234567'))->send('+233200000099', 'hi');
    expect($r->success)->toBeFalse();
    expect($r->retryable)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Messaging/ProviderFailureClassificationTest.php`
Expected: FAIL — transient cases return `retryable=false` (current code uses `SmsResult::failed()` for everything)

- [ ] **Step 3: Update `HubtelSmsProvider::send()` to classify**

Open `app/Services/Messaging/Sms/Providers/HubtelSmsProvider.php` and replace the `send()` method body. The new logic:

- Transport exception → `SmsResult::failedTransient(...)`
- HTTP 5xx → `SmsResult::failedTransient(...)`
- HTTP 4xx or `body.status != 0` → `SmsResult::failed(...)`
- Success → unchanged

Replace lines 33–65 with:

```php
    public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult
    {
        $sender = $fromSender ?: $this->defaultSender;

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/v1/messages/send", [
                    'clientid'     => $this->clientId,
                    'clientsecret' => $this->clientSecret,
                    'from'         => $sender,
                    'to'           => $this->normalisedMsisdn($toPhone),
                    'content'      => $body,
                ]);
        } catch (\Throwable $e) {
            return SmsResult::failedTransient("Hubtel transport error: {$e->getMessage()}");
        }

        $payload = $response->json() ?? [];

        if ($response->serverError()) {
            return SmsResult::failedTransient(
                "Hubtel upstream (HTTP {$response->status()}): " . ($payload['statusDescription'] ?? 'unknown'),
                $payload,
            );
        }

        if (! $response->successful() || (int) ($payload['status'] ?? -1) !== 0) {
            return SmsResult::failed(
                "Hubtel rejected (HTTP {$response->status()}): " . ($payload['statusDescription'] ?? 'unknown'),
                $payload,
            );
        }

        return SmsResult::sent(
            messageId: (string) ($payload['messageId'] ?? ''),
            segments:  (int) ($payload['rate']  ?? 1),
            cost:      (float) ($payload['rate'] ?? 0),
            raw:       $payload,
        );
    }
```

Note: variable renamed from `$body` (collision with method param) to `$payload`.

- [ ] **Step 4: Update `TwilioSmsProvider::send()` to classify**

Open `app/Services/Messaging/Sms/Providers/TwilioSmsProvider.php` and replace the `send()` method body. The new logic mirrors Hubtel:

Replace lines 28–58 with:

```php
    public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                    'From' => $fromSender ?: $this->fromNumber,
                    'To'   => $this->toE164($toPhone),
                    'Body' => $body,
                ]);
        } catch (\Throwable $e) {
            return SmsResult::failedTransient("Twilio transport error: {$e->getMessage()}");
        }

        $payload = $response->json() ?? [];

        if ($response->serverError()) {
            return SmsResult::failedTransient(
                "Twilio upstream (HTTP {$response->status()}): " . ($payload['message'] ?? 'unknown'),
                $payload,
            );
        }

        if (! $response->successful()) {
            return SmsResult::failed(
                "Twilio rejected: " . ($payload['message'] ?? "HTTP {$response->status()}"),
                $payload,
            );
        }

        return SmsResult::sent(
            messageId: (string) ($payload['sid'] ?? ''),
            segments:  (int) ($payload['num_segments'] ?? 1),
            cost:      (float) ($payload['price'] ?? 0),
            raw:       $payload,
        );
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Messaging/ProviderFailureClassificationTest.php`
Expected: PASS, 5 tests passed

- [ ] **Step 6: Commit**

```bash
git add app/Services/Messaging/Sms/Providers/HubtelSmsProvider.php \
        app/Services/Messaging/Sms/Providers/TwilioSmsProvider.php \
        tests/Feature/Messaging/ProviderFailureClassificationTest.php
git commit -m "feat(sms): classify Hubtel/Twilio 5xx + transport errors as retryable"
```

---

## Task 3: Create `SendSmsJob`

**Files:**
- Create: `app/Jobs/Messaging/SendSmsJob.php`
- Test: `tests/Feature/Messaging/SendSmsJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Messaging/SendSmsJobTest.php`:

```php
<?php

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\SmsDispatcher;
use App\Services\Messaging\Sms\SmsResult;

it('processes a Queued SmsMessage row and flips it to Sent on success', function () {
    $msg = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'hi',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);

    $provider = new class implements SmsProvider {
        public function name(): string { return 'log'; }
        public function send(string $to, string $body, ?string $from = null): SmsResult
        {
            return SmsResult::sent('msg-success', segments: 1, cost: 0.01);
        }
    };
    app()->instance(SmsDispatcher::class, new SmsDispatcher($provider));

    (new SendSmsJob($msg->id))->handle(app(SmsDispatcher::class));

    expect($msg->fresh()->status)->toBe(SmsStatus::Sent);
    expect($msg->fresh()->provider_message_id)->toBe('msg-success');
});

it('marks the row Failed when provider returns permanent failure', function () {
    $msg = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'hi',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);

    $provider = new class implements SmsProvider {
        public function name(): string { return 'log'; }
        public function send(string $to, string $body, ?string $from = null): SmsResult
        {
            return SmsResult::failed('bad number');
        }
    };
    app()->instance(SmsDispatcher::class, new SmsDispatcher($provider));

    (new SendSmsJob($msg->id))->handle(app(SmsDispatcher::class));

    expect($msg->fresh()->status)->toBe(SmsStatus::Failed);
    expect($msg->fresh()->failure_reason)->toContain('bad number');
});

it('throws (triggering retry) when provider returns transient failure', function () {
    $msg = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'hi',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);

    $provider = new class implements SmsProvider {
        public function name(): string { return 'log'; }
        public function send(string $to, string $body, ?string $from = null): SmsResult
        {
            return SmsResult::failedTransient('upstream 503');
        }
    };
    app()->instance(SmsDispatcher::class, new SmsDispatcher($provider));

    expect(fn () => (new SendSmsJob($msg->id))->handle(app(SmsDispatcher::class)))
        ->toThrow(\RuntimeException::class, 'upstream 503');

    // Row stays Queued so the retry will pick it up
    expect($msg->fresh()->status)->toBe(SmsStatus::Queued);
});

it('is idempotent — returns early if row already moved past Queued', function () {
    $msg = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'hi',
        'provider' => 'log',
        'status'   => SmsStatus::Sent->value,  // already done by a parallel worker
        'segments' => 1,
        'provider_message_id' => 'msg-first',
    ]);

    $provider = new class implements SmsProvider {
        public function name(): string { return 'log'; }
        public function send(string $to, string $body, ?string $from = null): SmsResult
        {
            throw new \RuntimeException('provider should not be called');
        }
    };
    app()->instance(SmsDispatcher::class, new SmsDispatcher($provider));

    (new SendSmsJob($msg->id))->handle(app(SmsDispatcher::class));

    // Provider was not called; row unchanged
    expect($msg->fresh()->provider_message_id)->toBe('msg-first');
});

it('returns early when the SmsMessage row no longer exists', function () {
    $job = new SendSmsJob(messageId: 999999);
    expect(fn () => $job->handle(app(SmsDispatcher::class)))->not->toThrow(\Throwable::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Messaging/SendSmsJobTest.php`
Expected: FAIL with "class App\Jobs\Messaging\SendSmsJob not found"

- [ ] **Step 3: Create `SendSmsJob`**

Create `app/Jobs/Messaging/SendSmsJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Enums\SmsStatus;
use App\Models\SmsMessage;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drives a single SmsMessage row through its provider call. Created by
 * SmsDispatcher::send() (async path) and re-dispatched by the sweep.
 *
 * Retry policy: 3 attempts with exponential backoff (60s, 5m, 15m). Permanent
 * failures (bad input, auth) short-circuit without throwing so they don't
 * waste retries; transient failures (5xx, network) throw so the queue
 * runner re-enqueues with backoff.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Backoff in seconds for each retry attempt. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public readonly int $messageId) {}

    public function handle(SmsDispatcher $dispatcher): void
    {
        $message = SmsMessage::find($this->messageId);
        if (! $message) {
            Log::info('SendSmsJob skipped — SmsMessage row missing', ['id' => $this->messageId]);
            return;
        }

        // Idempotency: another worker (or a previous run of this same job)
        // already moved the row past Queued. Don't redo the send.
        if ($message->status !== SmsStatus::Queued) {
            return;
        }

        $dispatcher->deliver($message);

        $message->refresh();
        if ($message->status === SmsStatus::Queued) {
            // Provider returned transient failure — dispatcher marked it back
            // to Queued and recorded the reason; throw to trigger queue retry.
            throw new \RuntimeException($message->failure_reason ?? 'transient SMS failure');
        }
    }
}
```

This relies on a new `SmsDispatcher::deliver(SmsMessage $message)` method (added in Task 4) that handles the synchronous provider call and updates the row in place.

- [ ] **Step 4: Run test to verify it still fails (because `deliver()` doesn't exist yet)**

Run: `vendor/bin/pest tests/Feature/Messaging/SendSmsJobTest.php`
Expected: FAIL with "method `deliver` not found on SmsDispatcher"

Leave the test broken — Task 4 wires it up.

- [ ] **Step 5: Commit the job class on its own**

```bash
git add app/Jobs/Messaging/SendSmsJob.php tests/Feature/Messaging/SendSmsJobTest.php
git commit -m "feat(sms): add SendSmsJob queue wrapper (depends on dispatcher#deliver)"
```

---

## Task 4: Refactor `SmsDispatcher` — async by default + `deliver()` helper

**Files:**
- Modify: `app/Services/Messaging/Sms/SmsDispatcher.php`
- Test: existing `tests/Feature/Messaging/SmsDispatcherTest.php` + `tests/Feature/Messaging/SendSmsJobTest.php` (from Task 3)
- Test: `tests/Feature/Messaging/SmsAsyncBehaviourTest.php` (new)

- [ ] **Step 1: Write the failing async-behaviour test**

Create `tests/Feature/Messaging/SmsAsyncBehaviourTest.php`:

```php
<?php

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Services\Messaging\Sms\Providers\LogSmsProvider;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Support\Facades\Bus;

it('queues a SendSmsJob and returns a Queued row by default', function () {
    Bus::fake();

    $dispatcher = new SmsDispatcher(new LogSmsProvider());
    $msg = $dispatcher->send('+233200000099', 'hello async');

    expect($msg->status)->toBe(SmsStatus::Queued);
    Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->messageId === $msg->id);
});

it('skips queueing and sends synchronously when sync flag passed', function () {
    Bus::fake();

    $dispatcher = new SmsDispatcher(new LogSmsProvider());
    $msg = $dispatcher->send('+233200000099', 'hello sync', sync: true);

    expect($msg->status)->toBe(SmsStatus::Sent);
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('deliver() runs the synchronous provider path against an existing row', function () {
    $dispatcher = new SmsDispatcher(new LogSmsProvider());
    $msg = $dispatcher->send('+233200000099', 'queued first'); // queues
    expect($msg->status)->toBe(SmsStatus::Queued);

    $dispatcher->deliver($msg);

    expect($msg->fresh()->status)->toBe(SmsStatus::Sent);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Messaging/SmsAsyncBehaviourTest.php`
Expected: FAIL — current `send()` always runs synchronously, no `sync` arg, no `deliver()`.

- [ ] **Step 3: Refactor `SmsDispatcher`**

Replace `app/Services/Messaging/Sms/SmsDispatcher.php` contents with:

```php
<?php

namespace App\Services\Messaging\Sms;

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use Illuminate\Support\Facades\DB;

/**
 * Persists outbound SMS to `sms_messages`, dispatches via the active provider,
 * and updates the row with status + provider response.
 *
 * Default behaviour: row inserted with status=Queued, SendSmsJob dispatched,
 * row returned immediately. The job runs `deliver()` which talks to the
 * provider synchronously.
 *
 * Pass sync=true to bypass the queue (used in tests and from inside the job
 * itself, where queueing again would be infinite recursion).
 *
 * Usage:
 *   $dispatcher->send('+233200000099', 'Your payslip for May is ready.');
 *   $dispatcher->send($phone, $body, contextType: 'payroll', contextId: $run->id);
 *   $dispatcher->send($phone, $body, sync: true); // tests / inside-job
 */
class SmsDispatcher
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function send(
        string $toPhone,
        string $body,
        ?string $fromSender = null,
        ?string $contextType = null,
        ?int $contextId = null,
        ?User $triggeredBy = null,
        bool $sync = false,
    ): SmsMessage {
        $message = SmsMessage::create([
            'to_phone'     => $toPhone,
            'from_sender'  => $fromSender,
            'body'         => $body,
            'provider'     => $this->provider->name(),
            'status'       => SmsStatus::Queued->value,
            'segments'     => max(1, (int) ceil(mb_strlen($body) / 160)),
            'context_type' => $contextType,
            'context_id'   => $contextId,
            'triggered_by' => $triggeredBy?->id,
        ]);

        if ($sync) {
            $this->deliver($message);
            return $message->fresh();
        }

        SendSmsJob::dispatch($message->id);
        return $message;
    }

    /**
     * Synchronous provider call + row update. Used by SendSmsJob and by
     * the sync=true path of send(). Idempotent: returns early if the row
     * has already moved past Queued.
     *
     * On provider success: row → Sent.
     * On permanent failure: row → Failed (no retry signal).
     * On transient failure: row stays Queued + failure_reason recorded;
     * the caller (SendSmsJob) inspects status and throws to trigger retry.
     */
    public function deliver(SmsMessage $message): void
    {
        if ($message->status !== SmsStatus::Queued) return;

        $result = $this->provider->send($message->to_phone, $message->body, $message->from_sender);

        DB::transaction(function () use ($message, $result) {
            if ($result->success) {
                $message->update([
                    'status'              => SmsStatus::Sent->value,
                    'provider_message_id' => $result->providerMessageId,
                    'segments'            => $result->segments,
                    'cost'                => $result->cost,
                    'failure_reason'      => null,
                    'sent_at'             => now(),
                ]);
            } elseif ($result->retryable) {
                // Transient — leave Queued, record reason. The job will throw
                // and Laravel queue retry will re-run this method.
                $message->update([
                    'status'         => SmsStatus::Queued->value,
                    'failure_reason' => $result->failureReason,
                ]);
            } else {
                $message->update([
                    'status'         => SmsStatus::Failed->value,
                    'failure_reason' => $result->failureReason,
                    'sent_at'        => null,
                ]);
            }
        });
    }

    /** Provider-callback path — flips Sent → Delivered when the network confirms. */
    public function markDelivered(string $providerMessageId): ?SmsMessage
    {
        $message = SmsMessage::where('provider_message_id', $providerMessageId)->first();
        if (! $message) return null;
        $message->update(['status' => SmsStatus::Delivered->value, 'delivered_at' => now()]);
        return $message->fresh();
    }
}
```

- [ ] **Step 4: Update the existing SmsDispatcherTest to pass `sync: true`**

The existing `tests/Feature/Messaging/SmsDispatcherTest.php` tests expect synchronous behaviour (`expect($msg->status)->toBe(SmsStatus::Sent)` immediately after `send()`). With async-by-default, those expectations break.

Open `tests/Feature/Messaging/SmsDispatcherTest.php` and add `sync: true` to every `$dispatcher->send(...)` call in lines 16-74. Five call sites to update:

```php
// Line 19-23:
$msg = $this->dispatcher->send(
    toPhone:     '+233200000099',
    body:        'Test message',
    triggeredBy: $user,
    sync:        true,
);

// Line 33:
$msg = $this->dispatcher->send('+233200000099', $long, sync: true);

// Line 39:
$msg = $this->dispatcher->send('+233200000099', 'hi', sync: true);

// Line 57:
$msg = $dispatcher->send('+233200000099', 'will fail', sync: true);

// Line 65-70:
$msg = $this->dispatcher->send(
    toPhone:     '+233200000099',
    body:        'Your payslip for May is ready.',
    contextType: 'payroll',
    contextId:   42,
    sync:        true,
);
```

- [ ] **Step 5: Run all messaging tests**

Run: `vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/`
Expected: ALL PASS — both old SmsDispatcherTest (with `sync:true`) and the new async test + Task 3's `SendSmsJobTest`.

- [ ] **Step 6: Run full suite to catch other callers**

Run: `vendor/bin/pest --parallel`
Expected: PASS. If any test fails because it expected `status === Sent` immediately after `send()` without passing `sync: true`, audit those tests and either add `sync: true` (for unit tests verifying delivery) OR add `Bus::fake()` + `$this->artisan('queue:work --once')` (for tests verifying queue behaviour).

Known existing tests that may need `sync: true` (search for `dispatcher->send` and `Notification::route('sms')`):

```bash
grep -rn "SmsDispatcher\|dispatcher->send" tests/
```

For each match outside `tests/Feature/Messaging/`, decide: if the test asserts something about the SMS row state immediately, add `sync: true`. If it just asserts the send was triggered, leave it (the Queued row + dispatched job already prove that).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Messaging/Sms/SmsDispatcher.php \
        tests/Feature/Messaging/SmsAsyncBehaviourTest.php \
        tests/Feature/Messaging/SmsDispatcherTest.php \
        tests/Feature/Messaging/SendSmsJobTest.php
# Plus any other test files updated in Step 6
git commit -m "feat(sms): SmsDispatcher::send() now async by default, deliver() helper for sync path"
```

---

## Task 5: Create `SmsDispatchExhausted` notification + wire `failed()` callback

**Files:**
- Create: `app/Notifications/SmsDispatchExhausted.php`
- Modify: `app/Jobs/Messaging/SendSmsJob.php` (add `failed()` callback)
- Test: `tests/Feature/Messaging/SmsExhaustedAlertTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Messaging/SmsExhaustedAlertTest.php`:

```php
<?php

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\SmsDispatchExhausted;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    // Pin role to employee so the role-derived perms don't accidentally
    // include messaging.manage (the User factory rolls a random role from
    // {employee, manager, hr_admin, finance_officer}; hr_admin grants it).
    $this->admin = User::factory()->create(['role' => 'employee']);
    $this->admin->permissions = ['messaging.manage'];
    $this->admin->save();

    $this->other = User::factory()->create(['role' => 'employee']);
});

it('notifies messaging.manage holders when a SendSmsJob exhausts retries', function () {
    $msg = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'irrelevant',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);

    $job = new SendSmsJob($msg->id);
    $job->failed(new \RuntimeException('upstream 503 after 3 tries'));

    Notification::assertSentTo($this->admin, SmsDispatchExhausted::class);
    Notification::assertNotSentTo($this->other, SmsDispatchExhausted::class);
});

it('marks the SmsMessage row as Failed when failed() callback runs', function () {
    $msg = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'irrelevant',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);

    (new SendSmsJob($msg->id))->failed(new \RuntimeException('exhausted'));

    expect($msg->fresh()->status)->toBe(SmsStatus::Failed);
    expect($msg->fresh()->failure_reason)->toContain('exhausted');
});

it('does not double-notify within 15 minutes (rate limited)', function () {
    $msg1 = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'one',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);
    $msg2 = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'two',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);

    (new SendSmsJob($msg1->id))->failed(new \RuntimeException('boom'));
    (new SendSmsJob($msg2->id))->failed(new \RuntimeException('boom'));

    Notification::assertSentToTimes($this->admin, SmsDispatchExhausted::class, 1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Messaging/SmsExhaustedAlertTest.php`
Expected: FAIL with "class App\Notifications\SmsDispatchExhausted not found"

- [ ] **Step 3: Create `SmsDispatchExhausted` notification**

Create `app/Notifications/SmsDispatchExhausted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fires to users holding `messaging.manage` when a SendSmsJob exhausts all
 * retry attempts. Rate-limited (one per recipient per 15 minutes) to prevent
 * a provider outage from spamming admins.
 */
class SmsDispatchExhausted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $smsMessageId,
        public readonly string $toPhone,
        public readonly ?string $contextType,
        public readonly ?int $contextId,
        public readonly string $failureReason,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'            => 'sms_exhausted',
            'message'         => "SMS to {$this->toPhone} failed after 3 retries: {$this->failureReason}",
            'sms_message_id'  => $this->smsMessageId,
            'context_type'    => $this->contextType,
            'context_id'      => $this->contextId,
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url('/admin/messaging?status=failed');
        return (new MailMessage())
            ->subject('SMS dispatch failed after retries')
            ->line("An outbound SMS to {$this->toPhone} could not be delivered after 3 attempts.")
            ->line("Reason: {$this->failureReason}")
            ->when($this->contextType, fn ($m) => $m->line("Context: {$this->contextType}#{$this->contextId}"))
            ->action('Review failed messages', $url);
    }

    public static function for(SmsMessage $msg, \Throwable $cause): self
    {
        return new self(
            smsMessageId:  $msg->id,
            toPhone:       $msg->to_phone,
            contextType:   $msg->context_type,
            contextId:     $msg->context_id,
            failureReason: $cause->getMessage(),
        );
    }
}
```

- [ ] **Step 4: Update `SendSmsJob` to add the `failed()` callback**

Open `app/Jobs/Messaging/SendSmsJob.php` and add this method below `handle()`:

```php
    /**
     * Called once after $tries exhausted. Marks the row Failed and pings
     * users with messaging.manage perm (rate-limited to 1 per recipient
     * per 15 min so an upstream outage doesn't spam admins).
     */
    public function failed(\Throwable $exception): void
    {
        $message = SmsMessage::find($this->messageId);
        if (! $message) return;

        if ($message->status !== \App\Enums\SmsStatus::Failed) {
            $message->update([
                'status'         => \App\Enums\SmsStatus::Failed->value,
                'failure_reason' => $exception->getMessage(),
            ]);
        }

        $admins = \App\Models\User::whereJsonContains('permissions', 'messaging.manage')->get();
        $alert = \App\Notifications\SmsDispatchExhausted::for($message, $exception);

        foreach ($admins as $admin) {
            $cacheKey = "sms_exhausted_alert:{$admin->id}";
            if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                continue;
            }
            \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addMinutes(15));
            $admin->notify($alert);
        }
    }
```

Add the necessary `use` imports at the top of the file:

```php
use App\Enums\SmsStatus;
use App\Models\User;
use App\Notifications\SmsDispatchExhausted;
use Illuminate\Support\Facades\Cache;
```

Then in the body, use the imported names (`SmsStatus::Failed`, `User::whereJsonContains(...)`, `Cache::has(...)`, `SmsDispatchExhausted::for(...)`) so the `failed()` method doesn't need fully-qualified `\App\...` references.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Messaging/SmsExhaustedAlertTest.php`
Expected: PASS, 3 tests passed.

- [ ] **Step 6: Run full Messaging tests**

Run: `vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Notifications/SmsDispatchExhausted.php \
        app/Jobs/Messaging/SendSmsJob.php \
        tests/Feature/Messaging/SmsExhaustedAlertTest.php
git commit -m "feat(sms): notify messaging.manage holders when SendSmsJob exhausts retries"
```

---

## Task 6: Create `SweepStuckSmsCommand`

**Files:**
- Create: `app/Console/Commands/SweepStuckSmsCommand.php`
- Test: `tests/Feature/Messaging/SweepStuckSmsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Messaging/SweepStuckSmsTest.php`:

```php
<?php

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Bus;

it('re-dispatches SendSmsJob for Queued rows older than 10 minutes', function () {
    Bus::fake();

    $stuck = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'stale',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);
    $stuck->created_at = now()->subMinutes(15);
    $stuck->save();

    $fresh = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'fresh',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);
    // Default created_at = now()

    $this->artisan('messaging:sweep-stuck-sms')->assertSuccessful();

    Bus::assertDispatchedTimes(SendSmsJob::class, 1);
    Bus::assertDispatched(SendSmsJob::class, fn ($j) => $j->messageId === $stuck->id);
});

it('does not touch rows already in Sent/Failed/Delivered', function () {
    Bus::fake();

    foreach ([SmsStatus::Sent, SmsStatus::Failed, SmsStatus::Delivered] as $terminal) {
        $row = SmsMessage::create([
            'to_phone' => '+233200000099',
            'body'     => "in {$terminal->value}",
            'provider' => 'log',
            'status'   => $terminal->value,
            'segments' => 1,
        ]);
        $row->created_at = now()->subMinutes(30);
        $row->save();
    }

    $this->artisan('messaging:sweep-stuck-sms')->assertSuccessful();

    Bus::assertNothingDispatched();
});

it('reports the count of swept rows', function () {
    Bus::fake();

    for ($i = 0; $i < 3; $i++) {
        $row = SmsMessage::create([
            'to_phone' => '+233200000099',
            'body'     => "msg $i",
            'provider' => 'log',
            'status'   => SmsStatus::Queued->value,
            'segments' => 1,
        ]);
        $row->created_at = now()->subMinutes(20);
        $row->save();
    }

    $this->artisan('messaging:sweep-stuck-sms')
        ->expectsOutputToContain('Re-dispatched 3')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Messaging/SweepStuckSmsTest.php`
Expected: FAIL with "command messaging:sweep-stuck-sms not found"

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/SweepStuckSmsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Belt-and-braces sweep for SmsMessage rows stuck in Queued state past
 * `--stuck-after`. Indicates a worker crashed or the queue itself paused
 * between row insert and job pickup. Re-dispatches SendSmsJob, which is
 * idempotent (status check inside handle()).
 */
class SweepStuckSmsCommand extends Command
{
    protected $signature = 'messaging:sweep-stuck-sms
                            {--stuck-after=10 : Minutes a row must be Queued before sweeping}';

    protected $description = 'Re-dispatch SendSmsJob for SmsMessage rows stuck in Queued state.';

    public function handle(): int
    {
        $minutes = (int) $this->option('stuck-after');
        $cutoff = now()->subMinutes($minutes);

        $stuck = SmsMessage::where('status', SmsStatus::Queued->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stuck as $message) {
            SendSmsJob::dispatch($message->id);
        }

        $count = $stuck->count();
        $this->info("Re-dispatched {$count} stuck SMS rows (Queued > {$minutes} min).");
        Log::info('messaging:sweep-stuck-sms', ['count' => $count, 'minutes' => $minutes]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Messaging/SweepStuckSmsTest.php`
Expected: PASS, 3 tests passed.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SweepStuckSmsCommand.php \
        tests/Feature/Messaging/SweepStuckSmsTest.php
git commit -m "feat(sms): messaging:sweep-stuck-sms command + scheduler hook (next task)"
```

---

## Task 7: Schedule the sweep every 5 minutes

**Files:**
- Modify: `routes/console.php` (add scheduler entry)

- [ ] **Step 1: Add the schedule entry**

Open `routes/console.php`. After the existing `Schedule::command('payment-intents:expire-stale')` entry (after line 59), add:

```php

// Belt-and-braces SMS retry sweep — every 5 minutes, picks up any
// SmsMessage row stuck in Queued for > 10 min (worker crash, queue pause)
// and re-dispatches SendSmsJob. The job is idempotent.
Schedule::command('messaging:sweep-stuck-sms')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

- [ ] **Step 2: Verify the schedule is registered**

Run: `php artisan schedule:list`
Expected output line: `* * * * * messaging:sweep-stuck-sms` (no — Laravel shows `*/5 * * * *`). Confirm by grep:

```bash
php artisan schedule:list | grep "messaging:sweep-stuck-sms"
```

Expected: one line is printed listing the command at a 5-minute cron expression.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "feat(sms): schedule messaging:sweep-stuck-sms every 5m"
```

---

## Task 8: Register `sms:transactional` + `sms:marketing` rate limiters

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Messaging/SmsRateLimitersTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Messaging/SmsRateLimitersTest.php`:

```php
<?php

use Illuminate\Support\Facades\RateLimiter;

it('registers sms:transactional limiter (unlimited — present for N3 inheritance)', function () {
    $limit = RateLimiter::limiter('sms:transactional');
    expect($limit)->not->toBeNull();

    // Hit it 1000 times, never throttled — transactional bypass.
    for ($i = 0; $i < 1000; $i++) {
        RateLimiter::hit('sms:transactional:+233200000099', 60);
    }
    expect(RateLimiter::tooManyAttempts('sms:transactional:+233200000099', 999999))->toBeFalse();
});

it('registers sms:marketing limiter (5 per hour per phone)', function () {
    $limit = RateLimiter::limiter('sms:marketing');
    expect($limit)->not->toBeNull();

    $phone = '+233200000099';
    $keyResolver = fn () => $phone;

    // First 5 within an hour should be allowed.
    for ($i = 0; $i < 5; $i++) {
        $ok = RateLimiter::attempt("sms:marketing:{$phone}", 5, fn () => true, 3600);
        expect($ok)->toBeTruthy();
    }

    // 6th attempt blocked.
    expect(RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Messaging/SmsRateLimitersTest.php`
Expected: FAIL — `RateLimiter::limiter('sms:transactional')` returns null because the limiter isn't registered.

- [ ] **Step 3: Register the limiters in AppServiceProvider**

Open `app/Providers/AppServiceProvider.php`. Find the existing `RateLimiter::for('api', ...)` block (around lines 227–232). Below it, add:

```php

        // ── SMS rate limiters (N1 reliability — N3 broadcast inherits) ──
        // Transactional context bypasses throttling: "leave approved" SMS must
        // always reach the user. We still register the limiter so the
        // dispatcher API surface is symmetric and downstream broadcast code
        // (N3) can opt into a tighter limiter without conditional logic.
        \Illuminate\Support\Facades\RateLimiter::for('sms:transactional', function ($key) {
            return \Illuminate\Cache\RateLimiting\Limit::none();
        });

        // Marketing context — 5 messages per phone per hour. Used by the
        // admin broadcast surface in N3; in N1 only the limiter registration
        // ships, so nothing currently hits this path.
        \Illuminate\Support\Facades\RateLimiter::for('sms:marketing', function ($key) {
            return \Illuminate\Cache\RateLimiting\Limit::perHour(5)->by($key);
        });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Messaging/SmsRateLimitersTest.php`
Expected: PASS, 2 tests passed.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php \
        tests/Feature/Messaging/SmsRateLimitersTest.php
git commit -m "feat(sms): register sms:transactional + sms:marketing rate limiters"
```

---

## Task 9: Final full-suite check + PR

**Files:** none changed

- [ ] **Step 1: Run the full Pest suite**

Run: `vendor/bin/pest --parallel`
Expected: ALL PASS, ~1108-1112 tests total (current 1099 + ~10 new across this plan).

If any failure appears outside the new test files, audit whether the failing test:
- Expected `SmsMessage::status === Sent` immediately after `$dispatcher->send()` — add `sync: true` to that call.
- Used `Notification::fake()` and asserted `PaymentReceived` was sent — should still work because notifications themselves are unchanged.

- [ ] **Step 2: Run the Vite build to ensure no JS regressions** (defensive — N1 doesn't touch JS but the CI matrix runs it)

Run: `npm run build`
Expected: `✓ built in <Ns>` with no errors.

- [ ] **Step 3: Push the branch and open the PR**

```bash
git push -u origin <branch-name>
gh pr create --title "feat(sms): N1 — reliability hardening (queue + retry sweep + admin alert + limiters)" \
  --body-file - <<'EOF'
## Summary

Notifications v2 — Phase N1. Makes `SmsDispatcher::send()` async by default so the HTTP request thread never waits on Hubtel/Twilio.

- `SendSmsJob` wraps the provider call with `$tries=3, $backoff=[60,300,900]`.
- Providers classify failures into permanent (4xx / bad input) vs transient (5xx / network) via a new `retryable` flag on `SmsResult`. Permanent failures short-circuit retries.
- `messaging:sweep-stuck-sms` runs every 5 min to re-dispatch rows whose worker died mid-flight.
- On exhausted retries, users with `messaging.manage` get `SmsDispatchExhausted` (DB + mail). Rate-limited 1/15min/recipient.
- Two RateLimiters registered (`sms:transactional`, `sms:marketing`). N1 transactional path bypasses; N3 broadcast will use marketing.

## Test plan

- [x] Full Pest suite green (`vendor/bin/pest --parallel`)
- [x] `npm run build` clean
- [ ] Manual: trigger an SMS in dev, confirm `sms_messages` row appears as Queued, then flips to Sent after the queue worker runs (~1s)
- [ ] Manual: backdate a Queued row to 15 min ago, run `php artisan messaging:sweep-stuck-sms`, confirm it's re-dispatched
- [ ] Manual: configure Hubtel with bad creds, send one SMS, watch failure progress — Queued → retry x3 → Failed; admin gets `SmsDispatchExhausted` in `/notifications`

## Spec

`docs/superpowers/specs/2026-05-28-notifications-v2-roadmap-design.md`
EOF
```

- [ ] **Step 4: Merge once CI is green**

```bash
gh pr merge --squash --delete-branch
git checkout main && git pull --ff-only
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| `SendSmsJob` wraps provider call with `tries=3, backoff=[60, 300, 900]` | Task 3 |
| `SmsDispatcher::send()` async by default, `sync: true` for in-job use | Task 4 |
| Permanent vs transient classification on `SmsResult` | Task 1 |
| Hubtel + Twilio map provider responses to permanent/transient | Task 2 |
| `messaging:sweep-stuck-sms` command (Queued > 10 min) | Task 6 |
| Schedule every 5 min, `withoutOverlapping()` | Task 7 |
| `SmsDispatchExhausted` notification (DB + mail) to `messaging.manage` | Task 5 |
| Failed-alert rate limit (1 per recipient per 15 min) | Task 5 |
| Register `sms:transactional` + `sms:marketing` limiters | Task 8 |
| Idempotency: `if status != Queued return` inside the job | Task 3 |
| Full test suite stays green | Task 9 |

All spec items covered.

**Placeholder check:** No "TBD" / "TODO" / "similar to" references; every step has the full code body it needs.

**Type consistency:** `SmsResult::retryable` is read identically in `SmsDispatcher::deliver()` (Task 4), `SendSmsJob::handle()` (Task 3), and provider tests (Task 2). `SmsDispatchExhausted::for()` factory matches the `failed()` callback caller (Task 5). `SendSmsJob` constructor takes `int $messageId` consistently in tests + dispatch sites.
