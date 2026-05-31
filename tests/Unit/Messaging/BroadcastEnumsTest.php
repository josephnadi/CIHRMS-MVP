<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\Permission;

it('BroadcastStatus has the 7 expected states', function () {
    $values = array_column(BroadcastStatus::cases(), 'value');
    expect($values)->toEqualCanonicalizing([
        'draft', 'scheduled', 'queued', 'sending', 'completed', 'failed', 'cancelled',
    ]);
});

it('BroadcastChannel covers sms + mail', function () {
    $values = array_column(BroadcastChannel::cases(), 'value');
    expect($values)->toEqualCanonicalizing(['sms', 'mail']);
});

it('BroadcastAudienceType covers all 6 audience types', function () {
    $values = array_column(BroadcastAudienceType::cases(), 'value');
    expect($values)->toEqualCanonicalizing([
        'all_active_members',
        'members_by_class',
        'members_with_outstanding_fees',
        'all_active_employees',
        'employees_by_department',
        'users_by_permission',
    ]);
});

it('Permission enum exposes the 3 new broadcast slugs', function () {
    expect(Permission::BroadcastsView->value)->toBe('broadcasts.view');
    expect(Permission::BroadcastsManage->value)->toBe('broadcasts.manage');
    expect(Permission::BroadcastsBypassThrottle->value)->toBe('broadcasts.bypass_throttle');
});
