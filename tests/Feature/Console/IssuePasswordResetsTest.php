<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

function insertLockedOutUser(string $email, string $staffId, string $password = ''): User
{
    DB::table('users')->insert([
        'name'                 => 'User '.$staffId,
        'email'                => $email,
        'staff_id'             => $staffId,
        'role'                 => 'employee',
        'password'             => $password,
        'password_must_change' => false,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    return User::where('email', $email)->firstOrFail();
}

it('lists users with no password in dry-run mode and writes nothing', function () {
    User::factory()->create([
        'email'                => 'normal@cihrm.local',
        'staff_id'             => 'NORM-001',
        'password_must_change' => false,
    ]);

    insertLockedOutUser('legacy@cihrm.local', 'LEG-001');

    $this->artisan('users:issue-password-resets', ['--dry-run' => true])
        ->expectsOutputToContain('Affected users: 1')
        ->expectsOutputToContain('LEG-001')
        ->expectsOutputToContain('Dry-run only')
        ->assertSuccessful();

    $legacy = User::where('email', 'legacy@cihrm.local')->first();
    expect((bool) $legacy->password_must_change)->toBeFalse();
});

it('flips password_must_change and creates a reset token when run for real', function () {
    insertLockedOutUser('nopw@cihrm.local', 'NOPW-001');

    $this->artisan('users:issue-password-resets')
        ->expectsOutputToContain('NOPW-001')
        ->assertSuccessful();

    $user = User::where('email', 'nopw@cihrm.local')->first();
    expect((bool) $user->password_must_change)->toBeTrue();

    // Laravel's password broker writes a row to password_reset_tokens.
    expect(DB::table('password_reset_tokens')->where('email', 'nopw@cihrm.local')->exists())->toBeTrue();
});

it('targets dev-default password users when --include-default-password is set', function () {
    User::factory()->create([
        'email'                => 'devdefault@cihrm.local',
        'staff_id'             => 'DEV-001',
        'password'             => Hash::make('password'),
        'password_must_change' => false,
    ]);

    $this->artisan('users:issue-password-resets', ['--dry-run' => true])
        ->expectsOutputToContain('No users need a reset')
        ->assertSuccessful();

    $this->artisan('users:issue-password-resets', [
        '--dry-run'                  => true,
        '--include-default-password' => true,
    ])
        ->expectsOutputToContain('DEV-001')
        ->expectsOutputToContain('dev-default')
        ->assertSuccessful();
});

it('honours --exclude to skip listed staff IDs', function () {
    insertLockedOutUser('a@cihrm.local', 'EXCL-A');
    insertLockedOutUser('b@cihrm.local', 'EXCL-B');

    $this->artisan('users:issue-password-resets', [
        '--dry-run' => true,
        '--exclude' => 'EXCL-A',
    ])
        ->expectsOutputToContain('Affected users: 1')
        ->expectsOutputToContain('EXCL-B')
        ->doesntExpectOutputToContain('EXCL-A')
        ->assertSuccessful();
});

it('sends Laravel password-reset emails when --email is passed', function () {
    Notification::fake();

    insertLockedOutUser('reset@cihrm.local', 'RESET-001');

    $this->artisan('users:issue-password-resets', ['--email' => true])
        ->assertSuccessful();

    $user = User::where('email', 'reset@cihrm.local')->first();
    expect((bool) $user->password_must_change)->toBeTrue();
    Notification::assertSentTo($user, ResetPassword::class);
});

it('exits cleanly when there is nothing to do', function () {
    User::factory()->create();

    $this->artisan('users:issue-password-resets')
        ->expectsOutputToContain('No users need a reset')
        ->assertSuccessful();
});
