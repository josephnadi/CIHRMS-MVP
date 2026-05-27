<?php

use App\Enums\MemberClass;
use App\Models\Customer;
use App\Models\Member;
use App\Services\Billing\MemberRegistrationService;

it('creates a linked Customer 1:1 with the Member', function () {
    $svc = app(MemberRegistrationService::class);

    $member = $svc->register([
        'class' => MemberClass::Professional->value,
        'name'  => 'Adwoa Mensah',
        'email' => 'adwoa@example.gh',
        'phone' => '+233200000111',
    ]);

    expect($member->member_no)->toMatch('/^CIHRM-M-\d{4}-\d{5}$/');
    expect($member->customer_id)->not->toBeNull();

    $customer = Customer::find($member->customer_id);
    expect($customer)->not->toBeNull();
    expect($customer->code)->toBe($member->member_no);
    expect($customer->name)->toBe('Adwoa Mensah');
    expect($customer->email)->toBe('adwoa@example.gh');
});

it('uses the student prefix for student-class members', function () {
    $member = app(MemberRegistrationService::class)->register([
        'class' => MemberClass::Student,
        'name'  => 'Kwame Studentus',
    ]);
    expect($member->member_no)->toMatch('/^CIHRM-S-\d{4}-\d{5}$/');
});

it('hashes the Ghana Card before storing — never the raw value', function () {
    $member = app(MemberRegistrationService::class)->register([
        'class'             => MemberClass::Professional->value,
        'name'              => 'Yaa Hashed',
        'ghana_card_number' => 'GHA-123456789-0',
    ]);

    // Raw is never stored; only the SHA-256 hash.
    $stored = \DB::table('members')->where('id', $member->id)->value('ghana_card_number_hash');
    expect($stored)->toBe(hash('sha256', 'GHA-123456789-0'));
    expect($stored)->not->toBe('GHA-123456789-0');
});

it('is idempotent on email — returns the existing member instead of creating a duplicate', function () {
    $svc = app(MemberRegistrationService::class);
    $first  = $svc->register(['class' => MemberClass::Professional->value, 'name' => 'Once', 'email' => 'once@example.gh']);
    $second = $svc->register(['class' => MemberClass::Professional->value, 'name' => 'Twice', 'email' => 'once@example.gh']);

    expect($second->id)->toBe($first->id);
    expect(Member::where('email', 'once@example.gh')->count())->toBe(1);
});
