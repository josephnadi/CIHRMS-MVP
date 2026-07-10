<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('seeds the invoice-workflow demo accounts', function () {
    $this->seed(DatabaseSeeder::class);

    $expected = [
        'DEPT-001' => 'dept_head',
        'AUD-001'  => 'auditor',
        'CEO-001'  => 'ceo',
    ];

    foreach ($expected as $staffId => $role) {
        $u = User::where('staff_id', $staffId)->first();
        $roleValue = $u->role instanceof \App\Enums\UserRole ? $u->role->value : $u->role;
        expect($u)->not->toBeNull("account {$staffId} missing")
            ->and($roleValue)->toBe($role)
            ->and(\Illuminate\Support\Facades\Hash::check('password', $u->password))->toBeTrue();
    }

    // The dept head actually heads a department (own-department scoping is live).
    $deptHead = User::where('staff_id', 'DEPT-001')->first();
    expect(Department::where('code', 'OPS')->value('head_user_id'))->toBe($deptHead->id);

    // The auditor can vet, the CEO can approve (wildcard), finance can post.
    expect($deptHead->hasPermission('incoming_invoices.submit'))->toBeTrue()
        ->and(User::where('staff_id', 'AUD-001')->first()->hasPermission('incoming_invoices.vet'))->toBeTrue()
        ->and(User::where('staff_id', 'CEO-001')->first()->hasPermission('incoming_invoices.approve'))->toBeTrue()
        ->and(User::where('staff_id', 'FIN-001')->first()->hasPermission('incoming_invoices.post'))->toBeTrue();
});
