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
