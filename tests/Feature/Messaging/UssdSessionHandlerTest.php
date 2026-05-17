<?php

use App\Enums\UssdState;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\StaffPhonePin;
use App\Models\User;
use App\Services\Messaging\Ussd\UssdSessionHandler;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['messaging.ussd.session_ttl_seconds' => 180]);

    $dept = Department::factory()->create();
    $userModel = User::factory()->create(['name' => 'Akua Mensah']);
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'user_id'       => $userModel->id,
        'employee_no'   => 'TEST-001',
        'status'        => 'active',
    ]);

    StaffPhonePin::create([
        'employee_id'    => $this->employee->id,
        'phone'          => '233200000099',
        'pin_hash'       => Hash::make('1234'),
        'failed_attempts'=> 0,
    ]);

    LeaveBalance::create([
        'employee_id' => $this->employee->id,
        'type'        => 'annual',
        'year'        => now()->year,
        'total_days'  => 15,
        'used_days'   => 3,
    ]);

    $this->handler = app(UssdSessionHandler::class);
});

it('shows the welcome screen when the session is brand new', function () {
    $out = $this->handler->handle('s-1', '233200000099', '*920*HR#', '');

    expect($out)->toStartWith('CON ');
    expect($out)->toContain('CIHRMS Self-Service');
    expect($out)->toContain('Enter your Staff ID');
});

it('walks through staff-id → PIN → main menu on correct PIN', function () {
    $this->handler->handle('s-2', '233200000099', '*920*HR#', '');             // welcome
    $r1 = $this->handler->handle('s-2', '233200000099', '*920*HR#', 'TEST-001'); // staff id
    expect($r1)->toContain('Enter your 4-digit PIN');

    $r2 = $this->handler->handle('s-2', '233200000099', '*920*HR#', 'TEST-001*1234'); // pin
    expect($r2)->toContain('Hi Akua Mensah');
    expect($r2)->toContain('Latest payslip');
});

it('rejects an unknown staff id', function () {
    $this->handler->handle('s-3', '233200000099', '*920*HR#', '');
    $r = $this->handler->handle('s-3', '233200000099', '*920*HR#', 'GHOST-999');

    expect($r)->toStartWith('END ');
    expect($r)->toContain('Unknown Staff ID');
});

it('rejects a wrong PIN and increments the failure counter', function () {
    $this->handler->handle('s-4', '233200000099', '*920*HR#', '');
    $this->handler->handle('s-4', '233200000099', '*920*HR#', 'TEST-001');
    $r = $this->handler->handle('s-4', '233200000099', '*920*HR#', 'TEST-001*0000');

    expect($r)->toStartWith('END ');
    expect($r)->toContain('Wrong PIN');
    expect(StaffPhonePin::where('employee_id', $this->employee->id)->value('failed_attempts'))->toBe(1);
});

it('serves leave balance on menu option 2', function () {
    $this->handler->handle('s-5', '233200000099', '*920*HR#', '');
    $this->handler->handle('s-5', '233200000099', '*920*HR#', 'TEST-001');
    $this->handler->handle('s-5', '233200000099', '*920*HR#', 'TEST-001*1234');
    $r = $this->handler->handle('s-5', '233200000099', '*920*HR#', 'TEST-001*1234*2');

    expect($r)->toStartWith('END ');
    expect($r)->toContain('Annual');
    // 15 total - 3 used = 12 remaining
    expect($r)->toContain('12');
});

it('jumps to whistleblower-tracking from the welcome screen without auth', function () {
    $this->handler->handle('s-6', '233200000099', '*920*HR#', '');
    $r = $this->handler->handle('s-6', '233200000099', '*920*HR#', '0');

    expect($r)->toStartWith('CON ');
    expect($r)->toContain('whistleblower tracking code');
});
