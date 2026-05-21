<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\PendingBankChange;
use App\Models\StaffPhonePin;
use App\Models\User;
use App\Services\BankChangeRequestService;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\Providers\LogSmsProvider;
use App\Services\Messaging\Ussd\UssdSessionHandler;
use Illuminate\Support\Facades\Hash;

/**
 * Bank-change confirmation flow tests. The fraud scenario is the most
 * important assertion here: a code-rejected USSD attempt must NEVER apply
 * the change to the employee's bank_account, even if the attacker keeps
 * trying. Locking out after 5 failures + the SMS to the employee's own
 * phone are the defences in depth.
 */

beforeEach(function () {
    // Use the log provider so the SMS doesn't try to hit a real Hubtel
    // endpoint, but still satisfies the SmsProvider contract.
    $this->app->bind(SmsProvider::class, fn () => new LogSmsProvider());

    $dept = Department::factory()->create();
    $user = User::factory()->create(['name' => 'Kofi Asante']);
    $this->employee = Employee::factory()->create([
        'department_id'  => $dept->id,
        'user_id'        => $user->id,
        'employee_no'    => 'TEST-BANK-1',
        'status'         => 'active',
        'phone'          => '233244000001',
        'bank_name'      => 'Old Bank',
        'bank_account'   => '0000000001',
        'bank_sort_code' => '300100',
    ]);

    StaffPhonePin::create([
        'employee_id'     => $this->employee->id,
        'phone'           => '233244000001',
        'pin_hash'        => Hash::make('1234'),
        'failed_attempts' => 0,
    ]);

    $this->handler = app(UssdSessionHandler::class);
    $this->bankChanges = app(BankChangeRequestService::class);
});

it('request() creates a pending row and snapshots the old bank values', function () {
    $pending = $this->bankChanges->request($this->employee, [
        'bank_name'      => 'New Bank',
        'bank_account'   => '9999999999',
        'bank_sort_code' => '400200',
    ]);

    expect($pending->status)->toBe('pending');
    expect($pending->old_bank_account)->toBe('0000000001');
    expect($pending->new_bank_account)->toBe('9999999999');
    expect($pending->code_hash)->not->toBeEmpty();
    expect($pending->code_expires_at->isFuture())->toBeTrue();
    // employee's bank account is NOT yet changed
    expect($this->employee->fresh()->bank_account)->toBe('0000000001');
});

it('request() supersedes any previous pending change for the same employee', function () {
    $first  = $this->bankChanges->request($this->employee, ['bank_account' => '1111111111']);
    $second = $this->bankChanges->request($this->employee, ['bank_account' => '2222222222']);

    expect($first->fresh()->status)->toBe('rejected');
    expect($first->fresh()->rejection_reason)->toContain('Superseded');
    expect($second->status)->toBe('pending');
});

it('confirm() with the right code applies the change to the employee', function () {
    // Generate a known code by stubbing the service so we know what to enter.
    $pending = PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'old_bank_account' => '0000000001',
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('654321'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    $result = $this->bankChanges->confirm($pending, '654321');

    expect($result->status)->toBe('applied');
    expect($result->applied_at)->not->toBeNull();
    expect($this->employee->fresh()->bank_account)->toBe('9999999999');
});

it('confirm() with the wrong code does NOT apply and increments the failure counter', function () {
    $pending = PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('111111'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    expect(fn () => $this->bankChanges->confirm($pending, '999999'))
        ->toThrow(DomainException::class, 'Wrong code');

    expect($pending->fresh()->failed_attempts)->toBe(1);
    expect($this->employee->fresh()->bank_account)->toBe('0000000001'); // unchanged
});

it('confirm() rejects an expired code', function () {
    $pending = PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('123456'),
        'code_expires_at'  => now()->subMinute(),
        'status'           => 'pending',
    ]);

    expect(fn () => $this->bankChanges->confirm($pending, '123456'))
        ->toThrow(DomainException::class, 'expired');

    expect($pending->fresh()->status)->toBe('expired');
});

it('confirm() locks out after 5 failed attempts and blocks further tries', function () {
    $pending = PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('123456'),
        'code_expires_at'  => now()->addMinutes(30),
        'failed_attempts'  => 5,
        'status'           => 'pending',
    ]);

    expect(fn () => $this->bankChanges->confirm($pending, '123456'))
        ->toThrow(DomainException::class, 'Too many failed');

    expect($pending->fresh()->status)->toBe('rejected');
    expect($this->employee->fresh()->bank_account)->toBe('0000000001');
});

it('reject() leaves bank fields untouched and records the reason', function () {
    $pending = PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('xxx'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    $this->bankChanges->reject($pending, 'I did not request this');

    expect($pending->fresh()->status)->toBe('rejected');
    expect($pending->fresh()->rejection_reason)->toBe('I did not request this');
    expect($this->employee->fresh()->bank_account)->toBe('0000000001');
});

// ── USSD-flow integration tests ────────────────────────────────────────────

function ussdAuth(\App\Models\Employee $employee, string $sessionId, $handler): void
{
    $handler->handle($sessionId, '233244000001', '*920*HR#', '');
    $handler->handle($sessionId, '233244000001', '*920*HR#', $employee->employee_no);
    $handler->handle($sessionId, '233244000001', '*920*HR#', "{$employee->employee_no}*1234");
}

it('option 5 on the main menu offers bank-change confirmation when one exists', function () {
    PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_name'    => 'New Bank',
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('424242'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    ussdAuth($this->employee, 'sess-1', $this->handler);

    $out = $this->handler->handle('sess-1', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5');
    expect($out)->toStartWith('CON ');
    expect($out)->toContain('Enter the 6-digit code');
});

it('option 5 ends the session if no bank-change is pending', function () {
    ussdAuth($this->employee, 'sess-2', $this->handler);

    $out = $this->handler->handle('sess-2', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5');
    expect($out)->toStartWith('END ');
    expect($out)->toContain('No pending bank change');
});

it('USSD code-entry with the right code prompts for approve/reject', function () {
    PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_name'    => 'New Bank',
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('424242'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    ussdAuth($this->employee, 'sess-3', $this->handler);
    $this->handler->handle('sess-3', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5');

    $out = $this->handler->handle('sess-3', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5*424242');
    expect($out)->toStartWith('CON ');
    expect($out)->toContain('Apply bank change to New Bank');
    expect($out)->toContain('1. Approve');
    expect($out)->toContain('2. Reject');
    // Masked account — only last 4 digits visible
    expect($out)->toContain('9999');
    expect($out)->not->toContain('9999999999');
});

it('USSD approve applies the change to the employee', function () {
    PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_name'    => 'New Bank',
        'new_bank_account' => '9999999999',
        'new_bank_sort_code' => '400200',
        'code_hash'        => Hash::make('424242'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    ussdAuth($this->employee, 'sess-4', $this->handler);
    $this->handler->handle('sess-4', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5');
    $this->handler->handle('sess-4', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5*424242');

    $out = $this->handler->handle('sess-4', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5*424242*1');
    expect($out)->toStartWith('END ');
    expect($out)->toContain('Bank change applied');

    $fresh = $this->employee->fresh();
    expect($fresh->bank_account)->toBe('9999999999');
    expect($fresh->bank_name)->toBe('New Bank');
    expect($fresh->bank_sort_code)->toBe('400200');
});

it('USSD reject leaves bank fields untouched', function () {
    PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_name'    => 'Attacker Bank',
        'new_bank_account' => '6666666666',
        'code_hash'        => Hash::make('424242'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    ussdAuth($this->employee, 'sess-5', $this->handler);
    $this->handler->handle('sess-5', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5');
    $this->handler->handle('sess-5', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5*424242');

    $out = $this->handler->handle('sess-5', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5*424242*2');
    expect($out)->toStartWith('END ');
    expect($out)->toContain('rejected');

    expect($this->employee->fresh()->bank_account)->toBe('0000000001');
});

it('USSD wrong-code attempt ends with "Wrong code" and increments failures', function () {
    $pending = PendingBankChange::create([
        'employee_id'      => $this->employee->id,
        'new_bank_account' => '9999999999',
        'code_hash'        => Hash::make('424242'),
        'code_expires_at'  => now()->addMinutes(30),
        'status'           => 'pending',
    ]);

    ussdAuth($this->employee, 'sess-6', $this->handler);
    $this->handler->handle('sess-6', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5');

    $out = $this->handler->handle('sess-6', '233244000001', '*920*HR#', 'TEST-BANK-1*1234*5*111111');
    expect($out)->toStartWith('END ');
    expect($out)->toContain('Wrong code');

    expect($pending->fresh()->failed_attempts)->toBe(1);
    expect($this->employee->fresh()->bank_account)->toBe('0000000001');
});
