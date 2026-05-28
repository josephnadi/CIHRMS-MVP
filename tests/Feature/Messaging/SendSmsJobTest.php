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
