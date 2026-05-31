<?php

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([DispatchBroadcastJob::class]);
});

it('fires a Scheduled broadcast whose scheduled_at has passed', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'status'       => BroadcastStatus::Scheduled,
    ])->create();
    $b->scheduled_at = now()->subMinutes(2);
    $b->save();

    $this->artisan('messaging:fire-due-broadcasts')->assertSuccessful();

    Bus::assertDispatched(DispatchBroadcastJob::class, fn ($j) => $j->broadcastId === $b->id);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Queued);
});

it('does not fire a future-scheduled broadcast', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'status'       => BroadcastStatus::Scheduled,
    ])->create();

    $this->artisan('messaging:fire-due-broadcasts')->assertSuccessful();

    Bus::assertNotDispatched(DispatchBroadcastJob::class);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Scheduled);
});
