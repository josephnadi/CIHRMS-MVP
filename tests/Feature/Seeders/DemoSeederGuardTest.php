<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('does not seed fixed-password demo accounts in production', function () {
    config(['app.env' => 'production']);
    app()->detectEnvironment(fn () => 'production');

    User::query()->delete();

    (new DatabaseSeeder())->run();

    expect(User::where('email', 'admin@cihrms.local')->exists())->toBeFalse();
    expect(User::where('email', 'finance@cihrms.local')->exists())->toBeFalse();
});

it('still seeds reference data (roles + chart of accounts) in production', function () {
    config(['app.env' => 'production']);
    app()->detectEnvironment(fn () => 'production');

    (new DatabaseSeeder())->run();

    expect(\DB::table('roles')->count())->toBeGreaterThan(0);
    expect(\DB::table('gl_accounts')->count())->toBeGreaterThan(0);
});

it('seeds demo accounts in local/dev env (sanity check)', function () {
    config(['app.env' => 'local']);
    app()->detectEnvironment(fn () => 'local');

    User::query()->delete();
    (new DatabaseSeeder())->run();

    expect(User::where('email', 'admin@cihrms.local')->exists())->toBeTrue();
});
