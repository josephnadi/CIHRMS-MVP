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
            $rows      = $pending->get();
            $total     = (float) $rows->sum(fn ($d) => (float) $d->net_to_recipient);
            $threshold = (float) config('finance.payouts.high_approval_threshold', 0);

            $batch = PayoutBatch::create([
                'reference'              => $this->reference(),
                'source_type'            => $sourceType,
                'source_id'              => $sourceId,
                'status'                 => PayoutBatchStatus::PendingRelease->value,
                'total_amount'           => $total,
                'currency'               => 'GHS',
                'requires_high_approval' => $threshold > 0 && $total >= $threshold,
                'created_by'             => $makerId,
            ]);

            Disbursement::whereIn('id', $rows->pluck('id'))->update(['payout_batch_id' => $batch->id]);

            return $batch->fresh();
        });
    }

    private function reference(): string
    {
        $year = Carbon::now()->year;

        return sprintf('POUT-%s-%04d', $year, $this->sequences->next("payout_batch:{$year}"));
    }
}
