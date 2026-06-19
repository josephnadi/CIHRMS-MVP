<?php

declare(strict_types=1);

use App\Enums\OnboardingArea;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('exposes onboarding enums', function () {
    expect(OnboardingStatus::InProgress->value)->toBe('in_progress')
        ->and(OnboardingStatus::Completed->isTerminal())->toBeTrue()
        ->and(OnboardingStatus::InProgress->isTerminal())->toBeFalse()
        ->and(OnboardingArea::ItProvisioning->label())->toBe('IT Provisioning')
        ->and(OnboardingTaskStatus::Pending->value)->toBe('pending');
});

it('grants onboarding permissions to an HR role, not to a plain employee', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    expect($hr->hasPermission('onboarding.initiate'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('onboarding.initiate'))->toBeFalse();
});
