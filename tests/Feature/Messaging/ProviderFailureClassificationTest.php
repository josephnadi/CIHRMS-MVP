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

it('classifies Twilio transport exception as transient failure', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('cURL timeout');
    });

    $r = (new TwilioSmsProvider('SID', 'token', '+15551234567'))->send('+233200000099', 'hi');
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
