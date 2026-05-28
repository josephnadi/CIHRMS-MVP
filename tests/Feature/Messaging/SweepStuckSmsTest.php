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

it('uses singular grammar when exactly 1 row is swept', function () {
    Bus::fake();

    $row = SmsMessage::create([
        'to_phone' => '+233200000099',
        'body'     => 'only-one',
        'provider' => 'log',
        'status'   => SmsStatus::Queued->value,
        'segments' => 1,
    ]);
    $row->created_at = now()->subMinutes(20);
    $row->save();

    $this->artisan('messaging:sweep-stuck-sms')
        ->expectsOutputToContain('Re-dispatched 1 stuck SMS row (')
        ->assertSuccessful();
});

it('handles >500 stuck rows without loading them all at once (chunkById)', function () {
    Bus::fake();

    // Insert 1200 stuck rows. chunkById(500) means 3 batches.
    $payloads = [];
    for ($i = 0; $i < 1200; $i++) {
        $payloads[] = [
            'to_phone'   => '+233200000099',
            'body'       => "msg $i",
            'provider'   => 'log',
            'status'     => \App\Enums\SmsStatus::Queued->value,
            'segments'   => 1,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ];
    }
    \App\Models\SmsMessage::insert($payloads);

    $this->artisan('messaging:sweep-stuck-sms')
        ->expectsOutputToContain('Re-dispatched 1200')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SendSmsJob::class, 1200);
});
