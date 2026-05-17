<?php

use App\Enums\SmsStatus;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\Providers\LogSmsProvider;
use App\Services\Messaging\Sms\SmsDispatcher;
use App\Services\Messaging\Sms\SmsResult;

beforeEach(function () {
    config(['messaging.sms.driver' => 'log']);
    $this->dispatcher = new SmsDispatcher(new LogSmsProvider());
});

it('persists an SMS row and marks it Sent on success', function () {
    $user = User::factory()->create();

    $msg = $this->dispatcher->send(
        toPhone:     '+233200000099',
        body:        'Test message',
        triggeredBy: $user,
    );

    expect($msg->status)->toBe(SmsStatus::Sent);
    expect($msg->provider_message_id)->toStartWith('log-');
    expect($msg->triggered_by)->toBe($user->id);
    expect($msg->segments)->toBe(1);
});

it('computes segments for long messages (over 160 chars)', function () {
    $long = str_repeat('a', 165);
    $msg = $this->dispatcher->send('+233200000099', $long);

    expect($msg->segments)->toBeGreaterThanOrEqual(2);
});

it('marks Sent → Delivered via the delivery-receipt path', function () {
    $msg = $this->dispatcher->send('+233200000099', 'hi');

    $delivered = $this->dispatcher->markDelivered($msg->provider_message_id);

    expect($delivered?->status)->toBe(SmsStatus::Delivered);
    expect($delivered->delivered_at)->not->toBeNull();
});

it('writes a Failed row when the provider rejects', function () {
    $failingProvider = new class implements SmsProvider {
        public function name(): string { return 'mock-fail'; }
        public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult
        {
            return SmsResult::failed('Mock failure for test');
        }
    };

    $dispatcher = new SmsDispatcher($failingProvider);
    $msg = $dispatcher->send('+233200000099', 'will fail');

    expect($msg->status)->toBe(SmsStatus::Failed);
    expect($msg->failure_reason)->toContain('Mock failure');
    expect($msg->sent_at)->toBeNull();
});

it('attaches context metadata for traceability', function () {
    $msg = $this->dispatcher->send(
        toPhone:     '+233200000099',
        body:        'Your payslip for May is ready.',
        contextType: 'payroll',
        contextId:   42,
    );

    expect($msg->context_type)->toBe('payroll');
    expect($msg->context_id)->toBe(42);
});
