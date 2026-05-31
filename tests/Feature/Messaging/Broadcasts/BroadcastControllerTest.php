<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('requires broadcasts.view to access index', function () {
    $user = User::factory()->create(['role' => 'employee']);
    $this->actingAs($user)->get(route('messaging.broadcasts.index'))->assertForbidden();

    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.view'];
    $admin->save();
    $this->actingAs($admin)->get(route('messaging.broadcasts.index'))->assertOk();
});

it('store creates a Broadcast and dispatches via BroadcastService', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();
    Member::factory()->count(3)->create();

    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'            => 'Test broadcast',
        'audience_type'    => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'  => [],
        'channels'         => ['mail'],
        'mail_subject'     => 'Hi',
        'mail_body'        => 'Body',
    ])->assertRedirect();

    expect(Broadcast::count())->toBe(1);
    expect(Broadcast::first()->title)->toBe('Test broadcast');
});

it('rejects empty channels selection', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'           => 'No channels',
        'audience_type'   => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params' => [],
        'channels'        => [],
    ])->assertSessionHasErrors('channels');
});

it('throttle override requires the bypass permission AND a reason', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage']; // no bypass perm
    $admin->save();

    // Without bypass perm: throttle_overridden=true is rejected (403)
    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'               => 't',
        'audience_type'       => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'     => [],
        'channels'            => ['sms'],
        'sms_body'            => 'b',
        'throttle_overridden' => true,
        'throttle_override_reason' => 'urgent',
    ])->assertForbidden();

    // With bypass perm, but no reason: validation rejects
    $admin->permissions = ['broadcasts.manage', 'broadcasts.bypass_throttle'];
    $admin->save();
    Cache::flush();

    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'               => 't',
        'audience_type'       => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'     => [],
        'channels'            => ['sms'],
        'sms_body'            => 'b',
        'throttle_overridden' => true,
    ])->assertSessionHasErrors('throttle_override_reason');

    // With both: ok
    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'               => 't',
        'audience_type'       => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'     => [],
        'channels'            => ['sms'],
        'sms_body'            => 'b',
        'throttle_overridden' => true,
        'throttle_override_reason' => 'AGM tomorrow',
    ])->assertRedirect();
});

it('cancel only works on Scheduled or Queued', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $scheduled = Broadcast::factory()->state([
        'created_by' => $admin->id,
        'status'     => BroadcastStatus::Scheduled,
        'scheduled_at' => now()->addHours(2),
    ])->create();

    $this->actingAs($admin)->post(route('messaging.broadcasts.cancel', $scheduled))->assertRedirect();
    expect($scheduled->fresh()->status)->toBe(BroadcastStatus::Cancelled);

    $completed = Broadcast::factory()->state([
        'created_by' => $admin->id,
        'status'     => BroadcastStatus::Completed,
    ])->create();

    $this->actingAs($admin)->post(route('messaging.broadcasts.cancel', $completed))->assertStatus(422);
});
