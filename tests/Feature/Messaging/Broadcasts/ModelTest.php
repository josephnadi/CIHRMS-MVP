<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\BroadcastTemplate;
use App\Models\Member;
use App\Models\User;

it('can create a BroadcastTemplate with audience-type cast', function () {
    $admin = User::factory()->create();
    $template = BroadcastTemplate::factory()
        ->state(['created_by' => $admin->id, 'audience_type' => BroadcastAudienceType::AllActiveMembers])
        ->create();

    expect($template->audience_type)->toBe(BroadcastAudienceType::AllActiveMembers);
    expect($template->is_active)->toBeTrue();
});

it('can create a Broadcast with all enums cast', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => [BroadcastChannel::Sms->value, BroadcastChannel::Mail->value],
        'status'        => BroadcastStatus::Queued,
    ])->create();

    expect($b->audience_type)->toBe(BroadcastAudienceType::AllActiveMembers);
    expect($b->status)->toBe(BroadcastStatus::Queued);
    expect($b->channels)->toBe(['sms', 'mail']);
    expect($b->audience_params)->toBeArray();
});

it('Broadcast hasMany BroadcastRecipient', function () {
    $admin = User::factory()->create();
    $member = Member::factory()->create();
    $b = Broadcast::factory()->state(['created_by' => $admin->id])->create();

    BroadcastRecipient::create([
        'broadcast_id'   => $b->id,
        'recipient_type' => Member::class,
        'recipient_id'   => $member->id,
        'sms_status'     => 'Sent',
    ]);

    expect($b->fresh()->recipients)->toHaveCount(1);
});

it('BroadcastRecipient enforces unique (broadcast, recipient_type, recipient_id)', function () {
    $admin = User::factory()->create();
    $member = Member::factory()->create();
    $b = Broadcast::factory()->state(['created_by' => $admin->id])->create();

    BroadcastRecipient::create([
        'broadcast_id' => $b->id, 'recipient_type' => Member::class, 'recipient_id' => $member->id,
    ]);

    expect(fn () => BroadcastRecipient::create([
        'broadcast_id' => $b->id, 'recipient_type' => Member::class, 'recipient_id' => $member->id,
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});
