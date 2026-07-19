<?php

declare(strict_types=1);

use App\Models\User;

it('grants workforce.analytics.view to hr_admin', function () {
    $user = User::factory()->create(['role' => 'hr_admin']);

    expect($user->hasPermission('workforce.analytics.view'))->toBeTrue();
});

it('denies workforce.analytics.view to a plain employee', function () {
    $user = User::factory()->create(['role' => 'employee']);

    expect($user->hasPermission('workforce.analytics.view'))->toBeFalse();
});
