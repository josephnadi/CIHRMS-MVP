<?php

declare(strict_types=1);

use App\Enums\IdentityVerificationStatus;
use App\Models\Employee;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Notifications\IdentityExpiringReminder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user     = User::factory()->create(['name' => 'Yaa Mensah']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

it('notifies the employee whose verification expires inside the window', function () {
    Notification::fake();

    IdentityVerification::create([
        'employee_id'        => $this->employee->id,
        'provider'           => 'manual_upload',
        'ghana_card_number'  => 'GHA-111111111-1',
        'ghana_card_hash'    => IdentityVerification::hashCardNumber('GHA-111111111-1'),
        'status'             => IdentityVerificationStatus::Verified->value,
        'verified_at'        => now()->subMonths(11),
        'expires_at'         => now()->addDays(10),
    ]);

    Artisan::call('identity:expiring', ['--window' => 30]);

    Notification::assertSentTo($this->user, IdentityExpiringReminder::class);
});

it('does NOT notify when expiry is outside the window', function () {
    Notification::fake();

    IdentityVerification::create([
        'employee_id'        => $this->employee->id,
        'provider'           => 'manual_upload',
        'ghana_card_number'  => 'GHA-222222222-2',
        'ghana_card_hash'    => IdentityVerification::hashCardNumber('GHA-222222222-2'),
        'status'             => IdentityVerificationStatus::Verified->value,
        'expires_at'         => now()->addDays(120),
    ]);

    Artisan::call('identity:expiring', ['--window' => 30]);

    Notification::assertNothingSent();
});

it('does NOT notify when verification is already expired', function () {
    Notification::fake();

    IdentityVerification::create([
        'employee_id'        => $this->employee->id,
        'provider'           => 'manual_upload',
        'ghana_card_number'  => 'GHA-333333333-3',
        'ghana_card_hash'    => IdentityVerification::hashCardNumber('GHA-333333333-3'),
        'status'             => IdentityVerificationStatus::Verified->value,
        'expires_at'         => now()->subDays(1),
    ]);

    Artisan::call('identity:expiring', ['--window' => 30]);

    Notification::assertNothingSent();
});

it('does NOT notify on failed verifications', function () {
    Notification::fake();

    IdentityVerification::create([
        'employee_id'        => $this->employee->id,
        'provider'           => 'manual_upload',
        'ghana_card_number'  => 'GHA-444444444-4',
        'ghana_card_hash'    => IdentityVerification::hashCardNumber('GHA-444444444-4'),
        'status'             => IdentityVerificationStatus::Failed->value,
        'expires_at'         => now()->addDays(10),
    ]);

    Artisan::call('identity:expiring', ['--window' => 30]);

    Notification::assertNothingSent();
});

it('honours a custom window', function () {
    Notification::fake();

    IdentityVerification::create([
        'employee_id'        => $this->employee->id,
        'provider'           => 'manual_upload',
        'ghana_card_number'  => 'GHA-555555555-5',
        'ghana_card_hash'    => IdentityVerification::hashCardNumber('GHA-555555555-5'),
        'status'             => IdentityVerificationStatus::Verified->value,
        'expires_at'         => now()->addDays(45),
    ]);

    // Default 30-day window: nothing.
    Artisan::call('identity:expiring', ['--window' => 30]);
    Notification::assertNothingSent();

    // Wider window: caught.
    Artisan::call('identity:expiring', ['--window' => 60]);
    Notification::assertSentTo($this->user, IdentityExpiringReminder::class);
});

it('exits 0 with a no-op message when nothing is expiring', function () {
    $exit = Artisan::call('identity:expiring', ['--window' => 30]);
    expect($exit)->toBe(0);
});
