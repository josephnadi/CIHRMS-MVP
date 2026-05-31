<?php

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;
use App\Models\User;
use App\Services\Messaging\Broadcasts\BroadcastService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->service = app(BroadcastService::class);
    Bus::fake([DispatchBroadcastJob::class]);
});

it('immediately dispatches DispatchBroadcastJob when no scheduled_at set', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => null,
        'status'       => BroadcastStatus::Queued,
    ])->create();

    $this->service->queue($b);

    Bus::assertDispatched(DispatchBroadcastJob::class, fn ($j) => $j->broadcastId === $b->id);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Queued);
});

it('does NOT dispatch when scheduled_at is in the future', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'status'       => BroadcastStatus::Scheduled,
    ])->create();

    $this->service->queue($b);

    Bus::assertNotDispatched(DispatchBroadcastJob::class);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Scheduled);
});

it('cancel flips Scheduled to Cancelled', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'status'       => BroadcastStatus::Scheduled,
    ])->create();

    $this->service->cancel($b);

    expect($b->fresh()->status)->toBe(BroadcastStatus::Cancelled);
});

it('cancel refuses to cancel a Completed broadcast', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by' => $admin->id,
        'status'     => BroadcastStatus::Completed,
    ])->create();

    expect(fn () => $this->service->cancel($b))->toThrow(\DomainException::class);
});
