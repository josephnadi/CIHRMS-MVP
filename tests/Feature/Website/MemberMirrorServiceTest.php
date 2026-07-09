<?php

declare(strict_types=1);

use App\Enums\MemberClass;
use App\Models\Member;
use App\Services\Website\MemberMirrorService;

it('creates a member mirror keyed by external_user_id, mapping website class to a valid MemberClass', function () {
    $svc = app(MemberMirrorService::class);

    $m = $svc->upsert([
        'external_user_id' => 4821, 'member_number' => 'CIHRM/2021/00456', 'student_number' => null,
        'user_type' => 'member', 'class' => 'full', 'status' => 'active',
        'name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => '0244000000',
    ]);

    expect((int) $m->external_user_id)->toBe(4821)
        ->and($m->customer_id)->not->toBeNull()
        ->and($m->class)->toBe(MemberClass::Professional)
        ->and($m->class->value)->toBe('professional');
});

it('updates an existing mirror instead of duplicating', function () {
    $svc = app(MemberMirrorService::class);

    $svc->upsert(['external_user_id' => 4821, 'name' => 'Old', 'email' => 'a@x.com', 'user_type' => 'member', 'class' => 'full', 'status' => 'active']);
    $svc->upsert(['external_user_id' => 4821, 'name' => 'New', 'email' => 'a@x.com', 'user_type' => 'member', 'class' => 'full', 'status' => 'active']);

    expect(Member::where('external_user_id', 4821)->count())->toBe(1)
        ->and(Member::where('external_user_id', 4821)->first()->name)->toBe('New');
});

it('maps an unrecognized website status like expired to lapsed', function () {
    $svc = app(MemberMirrorService::class);

    $m = $svc->upsert([
        'external_user_id' => 9001, 'name' => 'Kofi Boateng', 'email' => 'kofi@example.com',
        'user_type' => 'member', 'class' => 'student', 'status' => 'expired',
    ]);

    expect($m->status->value)->toBe('lapsed');
});

it('preserves existing class and status when a partial sync omits them', function () {
    $svc = app(MemberMirrorService::class);

    $svc->upsert([
        'external_user_id' => 5555, 'name' => 'Yaw Owusu', 'email' => 'yaw@example.com',
        'user_type' => 'member', 'class' => 'fellow', 'status' => 'suspended',
    ]);

    // Partial sync: no class/status supplied — should NOT downgrade to student/active.
    $m = $svc->upsert([
        'external_user_id' => 5555, 'name' => 'Yaw K. Owusu', 'email' => 'yaw@example.com',
    ]);

    expect($m->class->value)->toBe('fellow')
        ->and($m->status->value)->toBe('suspended');
});
