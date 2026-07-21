<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
use App\Models\FinalSettlement;
use App\Models\PayoutBatch;
use App\Models\PayrollRun;
use App\Services\Finance\SequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayoutBatchService
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function createForPayrollRun(PayrollRun $run, int $makerId): PayoutBatch
    {
        return $this->wrap(
            Disbursement::query()->where('payroll_run_id', $run->id)
                ->where('status', DisbursementStatus::Pending->value)
                ->whereNull('payout_batch_id'),
            $makerId,
            PayrollRun::class,
            $run->id,
        );
    }

    public function createForSettlement(FinalSettlement $settlement, int $makerId): PayoutBatch
    {
        return $this->wrap(
            Disbursement::query()->where('final_settlement_id', $settlement->id)
                ->where('status', DisbursementStatus::Pending->value)
                ->whereNull('payout_batch_id'),
            $makerId,
            FinalSettlement::class,
            $settlement->id,
        );
    }

    private function wrap(Builder $pending, int $makerId, string $sourceType, int $sourceId): PayoutBatch
    {
        return DB::transaction(function () use ($pending, $makerId, $sourceType, $sourceId) {
            $rows      = $pending->lockForUpdate()->get();
            $threshold = (float) config('finance.payouts.high_approval_threshold', 0);

            $batch = PayoutBatch::create([
                'reference'              => $this->reference(),
                'source_type'            => $sourceType,
                'source_id'              => $sourceId,
                'status'                 => PayoutBatchStatus::PendingRelease->value,
                'total_amount'           => 0,
                'currency'               => 'GHS',
                'requires_high_approval' => false,
                'created_by'             => $makerId,
            ]);

            Disbursement::whereIn('id', $rows->pluck('id'))
                ->whereNull('payout_batch_id')
                ->update(['payout_batch_id' => $batch->id]);

            // Recompute the total from the rows actually linked (not the pre-update
            // snapshot) so it can never disagree with what wound up in the batch —
            // a concurrent wrap() may have already claimed some of the pre-locked rows.
            $linked = Disbursement::where('payout_batch_id', $batch->id)->get();
            $total  = round($linked->sum(fn ($d) => (float) $d->net_to_recipient), 2);

            $batch->update([
                'total_amount'           => $total,
                'requires_high_approval' => $threshold > 0 && $total >= $threshold,
            ]);

            return $batch->fresh();
        });
    }

    private function reference(): string
    {
        $year = Carbon::now()->year;

        return sprintf('POUT-%s-%04d', $year, $this->sequences->next("payout_batch:{$year}"));
    }
}
