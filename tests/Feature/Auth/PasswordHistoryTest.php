<?php

use App\Models\User;
use App\Services\Auth\PasswordHistoryService;
use Illuminate\Support\Facades\Hash;

it('treats the current password as a recent password', function () {
    $u = User::factory()->create(['password' => bcrypt('OldPass!1')]);
    expect(app(PasswordHistoryService::class)->isRecent($u, 'OldPass!1'))->toBeTrue();
});

it('rejects a password that matches a recorded historical hash', function () {
    $u = User::factory()->create(['password' => bcrypt('Current!1')]);
    $svc = app(PasswordHistoryService::class);
    $svc->record($u, Hash::make('Previous!1'));

    expect($svc->isRecent($u, 'Previous!1'))->toBeTrue();
});

it('allows a genuinely new password', function () {
    $u = User::factory()->create(['password' => bcrypt('Current!1')]);
    $svc = app(PasswordHistoryService::class);
    $svc->record($u, Hash::make('Previous!1'));

    expect($svc->isRecent($u, 'BrandNew!9'))->toBeFalse();
});

it('keeps only HISTORY_DEPTH most recent entries after record() trims', function () {
    $u = User::factory()->create();
    $svc = app(PasswordHistoryService::class);
    for ($i = 1; $i <= 10; $i++) {
        $svc->record($u, Hash::make("pw{$i}!"));
    }
    expect(\DB::table('password_histories')->where('user_id', $u->id)->count())
        ->toBe(PasswordHistoryService::HISTORY_DEPTH);
});

it('rejects a profile password update that re-uses a prior password (controller wiring)', function () {
    $u = User::factory()->create([
        'role'                    => 'employee',
        'permissions'             => [],
        'password'                => bcrypt('Current!1'),
        'two_factor_required'     => false,
        'two_factor_confirmed_at' => now(),
    ]);
    app(PasswordHistoryService::class)->record($u, Hash::make('Previous!1'));

    $this->actingAs($u)
        ->from(route('profile.edit'))
        ->put(route('password.update'), [
            'current_password'      => 'Current!1',
            'password'              => 'Previous!1',
            'password_confirmation' => 'Previous!1',
        ])
        ->assertSessionHasErrors('password');
});
