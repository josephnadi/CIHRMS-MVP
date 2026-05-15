<?php

declare(strict_types=1);

use App\Models\Certification;
use App\Models\Employee;
use App\Services\GovernanceService;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('dispatches reminders for certs expiring within 30 days and stamps reminder_sent_at', function () {
    Carbon::setTestNow('2026-06-15 08:00:00');

    $emp = Employee::factory()->create();
    $expiring = Certification::create([
        'employee_id' => $emp->id,
        'name' => 'Safety Cert',
        'issued_at' => '2025-06-15',
        'expires_at' => '2026-07-01',
    ]);
    $faraway = Certification::create([
        'employee_id' => $emp->id,
        'name' => 'Long Cert',
        'issued_at' => '2025-06-15',
        'expires_at' => '2030-01-01',
    ]);

    $count = app(GovernanceService::class)->dispatchExpiryReminders(30);

    expect($count)->toBe(1);
    expect($expiring->fresh()->reminder_sent_at)->not->toBeNull();
    expect($faraway->fresh()->reminder_sent_at)->toBeNull();

    Carbon::setTestNow();
});

it('does not re-send reminder when reminder_sent_at is already set', function () {
    Carbon::setTestNow('2026-06-15 08:00:00');
    $emp = Employee::factory()->create();
    Certification::create([
        'employee_id' => $emp->id, 'name' => 'X', 'expires_at' => '2026-07-01',
        'reminder_sent_at' => now()->subDays(2),
    ]);

    $count = app(GovernanceService::class)->dispatchExpiryReminders(30);
    expect($count)->toBe(0);

    Carbon::setTestNow();
});
