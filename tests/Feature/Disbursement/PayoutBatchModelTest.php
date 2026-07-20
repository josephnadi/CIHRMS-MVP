<?php

declare(strict_types=1);

use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
use App\Models\PayoutBatch;

it('creates a batch, links disbursements, and casts status', function () {
    $batch = PayoutBatch::factory()->create([
        'status'         => PayoutBatchStatus::PendingRelease->value,
        'total_amount'   => 5000.00,
        'currency'       => 'GHS',
    ]);

    Disbursement::factory()->count(2)->create(['payout_batch_id' => $batch->id]);

    expect($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and($batch->disbursements)->toHaveCount(2)
        ->and((float) $batch->total_amount)->toBe(5000.00);
});
