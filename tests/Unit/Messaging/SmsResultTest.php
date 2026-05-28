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
