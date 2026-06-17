<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Finance\PostingActorResolver;

it('prefers an explicit actor over the authenticated user', function () {
    $auth = User::factory()->create();
    $explicit = User::factory()->create();
    $this->actingAs($auth);

    expect(app(PostingActorResolver::class)->resolveId($explicit))->toBe($explicit->id);
});

it('falls back to the authenticated user when no actor is given', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    expect(app(PostingActorResolver::class)->resolveId())->toBe($auth->id);
});

it('falls back to the configured system user when there is no auth and no actor', function () {
    $system = User::factory()->create(['role' => 'finance_officer']);
    config(['services.billing.system_user_id' => $system->id]);

    expect(app(PostingActorResolver::class)->resolveId())->toBe($system->id);
});

it('falls back to the first super_admin when no actor, no auth, and no valid configured system user', function () {
    config(['services.billing.system_user_id' => null]);
    $admin = User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['role' => 'super_admin']); // a later one — first should win

    expect(app(PostingActorResolver::class)->resolveId())->toBe($admin->id);
});

it('ignores a configured system user id that does not exist', function () {
    config(['services.billing.system_user_id' => 999999]);
    $admin = User::factory()->create(['role' => 'super_admin']);

    expect(app(PostingActorResolver::class)->resolveId())->toBe($admin->id);
});
