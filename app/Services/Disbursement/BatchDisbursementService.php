<?php

namespace App\Services\Disbursement;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StatutoryRate;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Materialises disbursement instructions for an approved payroll run and
 * dispatches each one to its provider.
 *
 *   PayrollRun → many PayrollLine → one Disbursement (per attempt)
 *
 * E-Levy is applied on MoMo channels per the Electronic Transfer Levy Act 2022
 * (Act 1075) — currently 1.5% of the transfer amount. Sourced from
 * `statutory_rates.E_LEVY_RATE` if present, otherwise hard-coded fallback.
 */
class BatchDisbursementService
{
    public const E_LEVY_FALLBACK_RATE = 0.015;

    public function __construct(
        /** @var array<string, DisbursementProvider> indexed by channel value */
        private readonly array $providers = [],
    ) {}

    /**
     * Generate Disbursement rows for every PayrollLine in the run. Idempotent:
     * if a Disbursement already exists for a given (run, line), it's skipped.
     *
     * @return int rows created
     */
    public function materialise(PayrollRun $run): int
    {
        $created = 0;
        $eLevyRate = $this->resolveELevyRate($run);

        // Eager-load employee.user to avoid an N+1 burst when reading
        // $employee?->user?->name as `beneficiary_name` further down.
        $run->lines()->calculated()->with('employee.user')->chunk(200, function ($lines) use ($run, $eLevyRate, &$created) {
            foreach ($lines as $line) {
                $exists = Disbursement::where('payroll_run_id', $run->id)
                    ->where('payroll_line_id', $line->id)
                    ->exists();
                if ($exists) continue;

                $employee = $line->employee;
                $channel  = $this->resolveChannel($employee);

                $gross   = (float) $line->net;
                $eLevy   = $channel->attractsELevy() ? round($gross * $eLevyRate, 2) : 0.0;
                $netRcv  = round($gross - $eLevy, 2);

                Disbursement::create([
                    'payroll_run_id'      => $run->id,
                    'payroll_line_id'     => $line->id,
                    'employee_id'         => $employee?->id,
                    'channel'             => $channel->value,
                    'status'              => DisbursementStatus::Pending->value,
                    'gross_amount'        => $gross,
                    'e_levy'              => $eLevy,
                    'provider_fee'        => 0,
                    'net_to_recipient'    => $netRcv,
                    'beneficiary_account' => $this->resolveBeneficiaryAccount($employee, $channel),
                    'beneficiary_name'    => $employee?->user?->name,
                ]);
                $created++;
            }
        });

        return $created;
    }

    /**
     * Dispatch all pending disbursements for a run. Each provider's `send()`
     * either marks the row Sent (success) or Failed (with reason).
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function dispatch(PayrollRun $run): array
    {
        $sent = 0; $failed = 0; $skipped = 0;

        $pending = Disbursement::where('payroll_run_id', $run->id)->pending()->get();

        foreach ($pending as $d) {
            $provider = $this->providers[$d->channel->value] ?? null;
            if (! $provider) {
                $skipped++;   // e.g. cash/cheque — handled manually
                continue;
            }

            $result = $provider->send($d);

            DB::transaction(function () use ($d, $result) {
                $d->update([
                    'status'             => $result->status->value,
                    'provider_reference' => $result->providerReference,
                    'provider_response'  => $result->raw,
                    'sent_at'            => $result->status === DisbursementStatus::Sent ? now() : $d->sent_at,
                    'settled_at'         => $result->status === DisbursementStatus::Settled ? now() : $d->settled_at,
                    'failed_at'          => $result->status === DisbursementStatus::Failed ? now() : null,
                    'failure_reason'     => $result->failureReason,
                ]);
            });

            $result->status === DisbursementStatus::Failed ? $failed++ : $sent++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Reconciliation — poll provider for status of any Sent disbursement older than 5 minutes. */
    public function reconcile(PayrollRun $run): int
    {
        $stale = Disbursement::where('payroll_run_id', $run->id)
            ->where('status', DisbursementStatus::Sent->value)
            ->where('sent_at', '<=', now()->subMinutes(5))
            ->get();

        $touched = 0;
        foreach ($stale as $d) {
            $provider = $this->providers[$d->channel->value] ?? null;
            if (! $provider) continue;

            $result = $provider->refreshStatus($d);
            if ($result->status === $d->status) continue;

            $d->update([
                'status'            => $result->status->value,
                'provider_response' => $result->raw,
                'settled_at'        => $result->status === DisbursementStatus::Settled ? now() : $d->settled_at,
                'failed_at'         => $result->status === DisbursementStatus::Failed ? now() : $d->failed_at,
                'failure_reason'    => $result->failureReason,
            ]);
            $touched++;
        }

        return $touched;
    }

    private function resolveChannel($employee): DisbursementChannel
    {
        $value = $employee?->disbursement_channel;
        if (is_string($value)) {
            return DisbursementChannel::tryFrom($value) ?? DisbursementChannel::GhipssAch;
        }
        return DisbursementChannel::GhipssAch;
    }

    private function resolveBeneficiaryAccount($employee, DisbursementChannel $channel): ?string
    {
        if ($employee === null) return null;
        return match ($channel) {
            DisbursementChannel::MtnMomo,
            DisbursementChannel::VodafoneCash,
            DisbursementChannel::AirtelTigo => $employee->mobile_money_number,
            default => $employee->bank_account,
        };
    }

    private function resolveELevyRate(PayrollRun $run): float
    {
        try {
            return StatutoryRate::lookup('E_LEVY_RATE', $run->period_end);
        } catch (\Throwable $e) {
            return self::E_LEVY_FALLBACK_RATE;
        }
    }
}
