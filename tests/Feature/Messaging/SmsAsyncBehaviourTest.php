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
