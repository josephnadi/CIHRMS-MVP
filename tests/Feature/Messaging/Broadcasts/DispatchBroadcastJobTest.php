<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Enums\MemberStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Bus::fake([SendSmsJob::class]);
    Mail::fake();
    RateLimiter::clear('sms:marketing:+233200000099');
    RateLimiter::clear('sms:marketing:+233200000088');
});

function makeMember(?string $phone = null, ?string $email = null): Member
{
    $customer = Customer::factory()->create();
    return Member::factory()->state([
        'status'      => MemberStatus::Active,
        'customer_id' => $customer->id,
        'phone'       => $phone,
        'email'       => $email,
    ])->create();
}

it('dispatches SMS + mail per recipient and records BroadcastRecipient rows', function () {
    $admin = User::factory()->create();
    makeMember(phone: '+233200000099', email: 'a@example.com');
    makeMember(phone: '+233200000088', email: 'b@example.com');

    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => ['sms', 'mail'],
        'status'        => BroadcastStatus::Queued,
        'sms_body'      => 'Hi {{member.name}}',
        'mail_subject'  => 'Test',
        'mail_body'     => 'Hi {{member.name}}',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->status)->toBe(BroadcastStatus::Completed);
    expect($b->recipient_count)->toBe(2);
    expect($b->sms_sent_count)->toBe(2);
    expect($b->mail_sent_count)->toBe(2);
    expect(BroadcastRecipient::where('broadcast_id', $b->id)->count())->toBe(2);
    Bus::assertDispatchedTimes(SendSmsJob::class, 2);
});

it('skips SMS leg for recipients without a phone', function () {
    $admin = User::factory()->create();
    makeMember(phone: null, email: 'noPhone@example.com');

    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => ['sms', 'mail'],
        'status'        => BroadcastStatus::Queued,
        'sms_body'      => 'sms body',
        'mail_subject'  => 'mail subject',
        'mail_body'     => 'mail body',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->sms_sent_count)->toBe(0);
    expect($b->mail_sent_count)->toBe(1);
    $r = BroadcastRecipient::where('broadcast_id', $b->id)->first();
    expect($r->sms_status)->toBe('Skipped');
    expect($r->mail_status)->toBe('Sent');
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('marks SMS leg Throttled when sms:marketing limiter is hit', function () {
    $admin = User::factory()->create();
    $phone = '+233200000099';
    makeMember(phone: $phone, email: 'a@example.com');

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit("sms:marketing:{$phone}", 3600);
    }

    $b = Broadcast::factory()->state([
        'created_by'          => $admin->id,
        'audience_type'       => BroadcastAudienceType::AllActiveMembers,
        'channels'            => ['sms'],
        'status'              => BroadcastStatus::Queued,
        'sms_body'            => 'should not send',
        'throttle_overridden' => false,
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->sms_throttled_count)->toBe(1);
    expect($b->sms_sent_count)->toBe(0);
    expect(BroadcastRecipient::where('broadcast_id', $b->id)->first()->sms_status)->toBe('Throttled');
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('bypasses limiter when throttle_overridden=true', function () {
    $admin = User::factory()->create();
    $phone = '+233200000099';
    makeMember(phone: $phone, email: 'a@example.com');

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit("sms:marketing:{$phone}", 3600);
    }

    $b = Broadcast::factory()->state([
        'created_by'               => $admin->id,
        'audience_type'            => BroadcastAudienceType::AllActiveMembers,
        'channels'                 => ['sms'],
        'status'                   => BroadcastStatus::Queued,
        'sms_body'                 => 'urgent',
        'throttle_overridden'      => true,
        'throttle_override_reason' => 'AGM tomorrow',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->sms_sent_count)->toBe(1);
    expect($b->sms_throttled_count)->toBe(0);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1);
});

it('is idempotent — does not double-send on rerun', function () {
    $admin = User::factory()->create();
    makeMember(phone: '+233200000099', email: 'a@example.com');

    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => ['mail'],
        'status'        => BroadcastStatus::Queued,
        'mail_subject'  => 's',
        'mail_body'     => 'b',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    // Reset status to Queued (simulating a worker crash + redispatch)
    $b->update(['status' => BroadcastStatus::Queued->value]);

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    expect(BroadcastRecipient::where('broadcast_id', $b->id)->count())->toBe(1);

    $b->refresh();
    expect($b->recipient_count)->toBe(1);
    expect($b->mail_sent_count)->toBe(1);
});
