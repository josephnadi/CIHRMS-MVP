<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Exceptions\Finance\PayoutAuthorizationException;
use App\Models\Disbursement;
use App\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PayoutReleaseService
{
    public function __construct(private readonly BatchDisbursementService $batch) {}

    /**
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function release(PayoutBatch $batch, User $releaser): array
    {
        if ($batch->status !== PayoutBatchStatus::PendingRelease) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        if (! $releaser->hasPermission('payouts.release')) {
            throw new PayoutAuthorizationException('You do not have permission to release payouts.');
        }
        if ($batch->requires_high_approval && ! $releaser->hasPermission('payouts.release_high')) {
            throw new PayoutAuthorizationException('This batch exceeds the threshold and requires a higher approver.');
        }
        if ((int) $batch->created_by === (int) $releaser->id) {
            throw new PayoutAuthorizationException('The maker of a batch cannot release it (segregation of duties).');
        }

        DB::transaction(function () use ($batch, $releaser) {
            $batch->update([
                'status'      => PayoutBatchStatus::Released->value,
                'released_by' => $releaser->id,
                'approved_by' => $releaser->id,
                'released_at' => now(),
            ]);
        });

        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($batch->disbursements()->where('status', DisbursementStatus::Pending->value)->get() as $d) {
            $totals[$this->batch->dispatchOne($d)]++;
        }

        return $totals;
    }
}
