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
