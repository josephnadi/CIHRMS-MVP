<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Exceptions\Finance\PayoutAuthorizationException;
use App\Models\Disbursement;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Services\Disbursement\PayoutReleaseService;

function releaser(array $perms): User
{
    // Test grant pattern: per-user JSON `permissions` column (see project_test_patterns).
    // `role` is pinned to 'employee' (rather than left to UserFactory's random
    // default) so this test's grants are deterministic: UserFactory randomly
    // picks among employee/manager/hr_admin/finance_officer, and finance_officer
    // now carries `payouts.initiate`/`payouts.release` via ROLE_PERMISSIONS —
    // an un-pinned role would make the "blocks release without payouts.release"
    // case flaky (~1-in-4 false pass).
    return User::factory()->create(['role' => 'employee', 'permissions' => $perms]);
}

function pendingBatch(User $maker, bool $high = false): PayoutBatch
{
    $batch = PayoutBatch::factory()->create([
        'created_by'             => $maker->id,
        'status'                 => PayoutBatchStatus::PendingRelease->value,
        'requires_high_approval' => $high,
    ]);
    Disbursement::factory()->count(2)->create([
        'payout_batch_id' => $batch->id,
        'channel'         => 'cash', // no provider → dispatchOne returns 'skipped', keeps test provider-free
        'status'          => DisbursementStatus::Pending->value,
    ]);
    return $batch;
}

it('blocks release by the maker (segregation of duties)', function () {
    $maker = releaser(['payouts.release']);
    $batch = pendingBatch($maker);

    app(PayoutReleaseService::class)->release($batch, $maker);
})->throws(PayoutAuthorizationException::class);

it('blocks release without payouts.release', function () {
    $maker    = releaser([]);
    $releaser = releaser([]);
    $batch    = pendingBatch($maker);

    app(PayoutReleaseService::class)->release($batch, $releaser);
})->throws(PayoutAuthorizationException::class);

it('blocks a high-value batch without payouts.release_high', function () {
    $maker    = releaser([]);
    $releaser = releaser(['payouts.release']); // lacks release_high
    $batch    = pendingBatch($maker, high: true);

    app(PayoutReleaseService::class)->release($batch, $releaser);
})->throws(PayoutAuthorizationException::class);

it('releases when a different authorized user acts', function () {
    $maker    = releaser([]);
    $releaser = releaser(['payouts.release']);
    $batch    = pendingBatch($maker);

    $result = app(PayoutReleaseService::class)->release($batch, $releaser);

    expect($batch->fresh()->status)->toBe(PayoutBatchStatus::Released)
        ->and($batch->fresh()->released_by)->toBe($releaser->id)
        ->and($result['sent'] + $result['skipped'])->toBe(2);
});

it('is a no-op when the batch is already released', function () {
    $maker    = releaser([]);
    $releaser = releaser(['payouts.release']);
    $batch    = pendingBatch($maker);
    app(PayoutReleaseService::class)->release($batch, $releaser);

    $again = app(PayoutReleaseService::class)->release($batch->fresh(), $releaser);
    expect($again)->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 0]);
});

it('atomically claims the release so a concurrent second call cannot double-send', function () {
    $maker       = releaser([]);
    $releaser    = releaser(['payouts.release']);
    $secondActor = releaser(['payouts.release']);
    $batch       = pendingBatch($maker);

    // Simulate the winner of a race: first call flips PendingRelease -> Released
    // and dispatches the disbursements exactly once.
    $first = app(PayoutReleaseService::class)->release($batch, $releaser);
    expect($first['sent'] + $first['skipped'])->toBe(2);

    $freshAfterFirst = $batch->fresh();
    expect($freshAfterFirst->status)->toBe(PayoutBatchStatus::Released)
        ->and($freshAfterFirst->released_by)->toBe($releaser->id);

    // A second, differently-authorized, non-maker caller racing in after the
    // claim has already succeeded must lose the atomic claim: the conditional
    // UPDATE's WHERE status=PendingRelease now matches zero rows, so it must
    // return all-zero totals and must not touch the batch or re-dispatch.
    $second = app(PayoutReleaseService::class)->release($batch->fresh(), $secondActor);

    expect($second)->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 0]);

    $freshAfterSecond = $batch->fresh();
    expect($freshAfterSecond->status)->toBe(PayoutBatchStatus::Released)
        ->and($freshAfterSecond->released_by)->toBe($releaser->id) // unchanged - not overwritten by second actor
        ->and($freshAfterSecond->released_at->equalTo($freshAfterFirst->released_at))->toBeTrue();
});
