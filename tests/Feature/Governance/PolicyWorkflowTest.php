<?php

declare(strict_types=1);

use App\Models\PolicyAcknowledgement;
use App\Models\User;
use App\Services\GovernanceService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('creates a policy with v1 draft', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Code of Conduct',
        'category' => 'conduct',
        'initial_body' => '# Code of Conduct\n\nEmployees must…',
    ]);

    expect($policy->slug)->toBe('code-of-conduct');
    expect($policy->versions()->count())->toBe(1);
    expect($policy->versions()->first()->published_at)->toBeNull();
});

it('publishes a version and stamps current_version_id', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Travel Policy', 'category' => 'finance', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();

    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    expect($policy->fresh()->current_version_id)->toBe($v1->id);
    expect($v1->fresh()->published_at)->not->toBeNull();
});

it('records acknowledgement with ip + ua + signed name', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $u  = User::factory()->create();
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Ethics', 'category' => 'compliance', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    $ack = app(GovernanceService::class)->acknowledge(
        $v1->fresh(), $u, $u->name, '10.0.0.1', 'TestAgent/1.0'
    );

    expect($ack)->toBeInstanceOf(PolicyAcknowledgement::class);
    expect($ack->ip_address)->toBe('10.0.0.1');
    expect($ack->signed_full_name)->toBe($u->name);
});

it('is idempotent on duplicate acknowledgement', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $u  = User::factory()->create();
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Safety', 'category' => 'safety', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    app(GovernanceService::class)->acknowledge($v1->fresh(), $u, $u->name, '1.1.1.1', 'A');
    app(GovernanceService::class)->acknowledge($v1->fresh(), $u, $u->name, '1.1.1.1', 'A');

    expect(PolicyAcknowledgement::where('policy_version_id', $v1->id)->where('user_id', $u->id)->count())->toBe(1);
});

it('refuses acknowledgement of an unpublished version', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $u  = User::factory()->create();
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Draft Only', 'category' => 'hr', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();

    expect(fn () => app(GovernanceService::class)->acknowledge($v1, $u, $u->name, '1.1.1.1', 'A'))
        ->toThrow(\DomainException::class, 'unpublished');
});

it('supersedes the previous version on publish', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Multi-version', 'category' => 'hr', 'initial_body' => 'v1 body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-01-01'));

    $v2 = app(GovernanceService::class)->addVersion($policy->fresh(), $hr, 'v2 body content here for the test scenario', 'Major revision');
    app(GovernanceService::class)->publish($v2, $hr, new \DateTimeImmutable('2026-06-01'));

    expect($v1->fresh()->effective_to?->toDateString())->toBe('2026-05-31');
    expect($policy->fresh()->current_version_id)->toBe($v2->id);
});
