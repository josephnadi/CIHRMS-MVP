<?php

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;

it('creates a GL account and casts enums + booleans', function () {
    $a = GlAccount::create([
        'code' => '1100',
        'name' => 'Bank — GCB Operating',
        'type' => GlAccountType::Asset->value,
        'is_active' => true,
    ]);

    expect($a->type)->toBe(GlAccountType::Asset);
    expect($a->is_active)->toBeTrue();
    expect($a->currency)->toBe('GHS');
});

it('supports parent/children relationship', function () {
    $parent = GlAccount::create(['code' => '1000', 'name' => 'Assets', 'type' => 'asset']);
    $child  = GlAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id);
    expect($parent->children->pluck('id'))->toContain($child->id);
});

it('scopes accounts by activity and type', function () {
    GlAccount::create(['code' => '1100', 'name' => 'A', 'type' => 'asset', 'is_active' => true]);
    GlAccount::create(['code' => '2100', 'name' => 'L', 'type' => 'liability', 'is_active' => true]);
    GlAccount::create(['code' => '4100', 'name' => 'I', 'type' => 'income', 'is_active' => false]);

    expect(GlAccount::active()->count())->toBe(2);
    expect(GlAccount::ofType(GlAccountType::Asset)->count())->toBe(1);
    expect(GlAccount::roots()->count())->toBe(3);
});

it('creates an org bank account linked to a GL account', function () {
    $gl = GlAccount::create(['code' => '1100', 'name' => 'Bank GCB', 'type' => 'asset']);

    $bank = OrgBankAccount::create([
        'gl_account_id'   => $gl->id,
        'bank_name'       => 'GCB',
        'account_name'    => 'CIHRM Operating',
        'account_number'  => '1234567890',
        'purpose'         => OrgBankAccountPurpose::Operating->value,
        'opening_balance' => 50000.00,
    ]);

    expect($bank->purpose)->toBe(OrgBankAccountPurpose::Operating);
    expect((float) $bank->opening_balance)->toBe(50000.00);
    expect($bank->glAccount->id)->toBe($gl->id);
});

it('balance row uses gl_account_id as the primary key', function () {
    $gl = GlAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset']);
    $bal = GlAccountBalance::create(['gl_account_id' => $gl->id, 'balance' => 1234.56]);

    expect($bal->getKeyName())->toBe('gl_account_id');
    expect($bal->incrementing)->toBeFalse();
    expect((float) $bal->balance)->toBe(1234.56);
});
