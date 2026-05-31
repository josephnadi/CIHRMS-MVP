<?php

use App\Enums\BroadcastAudienceType;
use App\Models\BroadcastTemplate;
use App\Models\User;

it('requires broadcasts.view to index templates', function () {
    $user = User::factory()->create(['role' => 'employee']);
    $this->actingAs($user)->get(route('messaging.templates.index'))->assertForbidden();
});

it('store creates a template', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.templates.store'), [
        'name'          => 'Annual Dues Notice',
        'audience_type' => BroadcastAudienceType::AllActiveMembers->value,
        'sms_body'      => 'Hi {{member.name}}',
        'mail_subject'  => 'Dues',
        'mail_body'     => 'Hi {{member.name}}',
        'is_active'     => true,
    ])->assertRedirect();

    expect(BroadcastTemplate::count())->toBe(1);
});

it('rejects template with empty bodies', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.templates.store'), [
        'name'          => 'Empty',
        'audience_type' => BroadcastAudienceType::AllActiveMembers->value,
        'is_active'     => true,
    ])->assertSessionHasErrors(['sms_body', 'mail_subject', 'mail_body']);
});

it('update mutates an existing template', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $t = BroadcastTemplate::factory()->state(['created_by' => $admin->id])->create();

    $this->actingAs($admin)->patch(route('messaging.templates.update', $t), [
        'name'          => 'Renamed',
        'audience_type' => $t->audience_type->value,
        'sms_body'      => $t->sms_body,
        'mail_subject'  => $t->mail_subject,
        'mail_body'     => $t->mail_body,
        'is_active'     => false,
    ])->assertRedirect();

    expect($t->fresh()->name)->toBe('Renamed');
    expect($t->fresh()->is_active)->toBeFalse();
});
