<?php

declare(strict_types=1);

use App\Enums\PayoutBatchStatus;
use App\Models\PayoutBatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the payouts list for a permissioned user', function () {
    $user = User::factory()->create(['permissions' => ['payouts.initiate', 'payouts.release']]);
    PayoutBatch::factory()->count(2)->create();

    $this->actingAs($user)->get(route('finance.payouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Finance/Payouts/Index')->has('batches'));
});

it('403s the list for a user without payout permissions', function () {
    // Explicit `role: employee` — the factory's default role is random across
    // employee/manager/hr_admin/finance_officer, and finance_officer legacy-
    // grants payouts.initiate/release via User::ROLE_PERMISSIONS, which would
    // flake this assertion ~1-in-4 runs (same class of bug fixed in
    // EmployeeSelfEditRestrictionTest / DocumentUploadOwnershipTest).
    $user = User::factory()->create(['role' => 'employee', 'permissions' => []]);
    $this->actingAs($user)->get(route('finance.payouts.index'))->assertForbidden();
});

it('blocks the maker from releasing via the endpoint', function () {
    $maker = User::factory()->create(['permissions' => ['payouts.release']]);
    $batch = PayoutBatch::factory()->create(['created_by' => $maker->id, 'status' => PayoutBatchStatus::PendingRelease->value]);

    $this->actingAs($maker)->post(route('finance.payouts.release', $batch))
        ->assertForbidden(); // PayoutAuthorizationException → 403
});
