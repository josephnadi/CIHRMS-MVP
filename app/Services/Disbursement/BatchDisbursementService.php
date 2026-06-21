<?php

namespace App\Services\Disbursement;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Enums\JournalSourceType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\Disbursement;
use App\Models\OrgBankAccount;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StatutoryRate;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly array $providers,
        private readonly PostingService $posting,
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
                $employee = $line->employee;
                $channel  = $this->resolveChannel($employee);

                $gross   = (float) $line->net;
                $eLevy   = $channel->attractsELevy() ? round($gross * $eLevyRate, 2) : 0.0;
                $netRcv  = round($gross - $eLevy, 2);

                // firstOrCreate keys on (run, line) so a re-run is idempotent;
                // the partial unique index is the hard backstop against the
                // check-then-create race. A genuinely concurrent insert loses
                // the race and throws — treat that as "already materialised".
                try {
                    $disbursement = Disbursement::firstOrCreate(
                        ['payroll_run_id' => $run->id, 'payroll_line_id' => $line->id],
                        [
                            'employee_id'         => $employee?->id,
                            'channel'             => $channel->value,
                            'status'              => DisbursementStatus::Pending->value,
                            'gross_amount'        => $gross,
                            'e_levy'              => $eLevy,
                            'provider_fee'        => 0,
                            'net_to_recipient'    => $netRcv,
                            'beneficiary_account' => $this->resolveBeneficiaryAccount($employee, $channel),
                            'beneficiary_name'    => $employee?->user?->name,
                        ]
                    );
                } catch (UniqueConstraintViolationException $e) {
                    continue;
                }

                if ($disbursement->wasRecentlyCreated) {
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Build a Pending disbursement for a paid final settlement's net (additive
     * tracking — the GL was already cleared by paySettlement). Returns null when
     * the settlement has no payment JE (nothing was disbursed, e.g. net zero).
     */
    public function createForSettlement(\App\Models\FinalSettlement $settlement): ?Disbursement
    {
        $paymentJe = \App\Models\JournalEntry::where('source_type', \App\Enums\JournalSourceType::FinalSettlement->value)
            ->where('source_id', $settlement->id)
            ->where('source_purpose', 'payment')
            ->first();

        if (! $paymentJe) {
            return null;
        }

        $paidNet = round((float) \App\Models\JournalLine::where('journal_entry_id', $paymentJe->id)->sum('credit_amount'), 2);
        if ($paidNet <= 0.0) {
            return null;
        }

        $case     = \App\Models\OffboardingCase::find($settlement->offboarding_case_id);
        $employee = $case?->employee_id ? \App\Models\Employee::find($case->employee_id) : null;
        $channel  = $this->resolveChannel($employee);

        $eLevy  = $channel->attractsELevy() ? round($paidNet * $this->eLevyRateOn(now()), 2) : 0.0;
        $netRcv = round($paidNet - $eLevy, 2);

        return Disbursement::create([
            'final_settlement_id' => $settlement->id,
            'payroll_run_id'      => null,
            'payroll_line_id'     => null,
            'employee_id'         => $employee?->id,
            'channel'             => $channel->value,
            'status'              => DisbursementStatus::Pending->value,
            'gross_amount'        => $paidNet,
            'e_levy'              => $eLevy,
            'provider_fee'        => 0,
            'net_to_recipient'    => $netRcv,
            'beneficiary_account' => $this->resolveBeneficiaryAccount($employee, $channel),
            'beneficiary_name'    => $employee?->user?->name,
        ]);
    }

    private function eLevyRateOn(\DateTimeInterface|string $date): float
    {
        try {
            return StatutoryRate::lookup('E_LEVY_RATE', $date);
        } catch (\Throwable $e) {
            return self::E_LEVY_FALLBACK_RATE;
        }
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
        foreach (Disbursement::where('payroll_run_id', $run->id)->pending()->get() as $d) {
            match ($this->dispatchOne($d)) {
                'sent'   => $sent++,
                'failed' => $failed++,
                default  => $skipped++,
            };
        }
        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Send one pending disbursement to its provider. Returns 'sent'|'failed'|'skipped'. */
    public function dispatchOne(Disbursement $d): string
    {
        $provider = $this->providers[$d->channel->value] ?? null;
        if (! $provider) {
            return 'skipped'; // e.g. cash/cheque — handled manually
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

            if ($result->status === DisbursementStatus::Settled) {
                $this->settle($d);
            }
        });

        return $result->status === DisbursementStatus::Failed ? 'failed' : 'sent';
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
            if ($this->reconcileOne($d)) $touched++;
        }
        return $touched;
    }

    /** Poll one sent disbursement; returns true if its status changed. */
    public function reconcileOne(Disbursement $d): bool
    {
        $provider = $this->providers[$d->channel->value] ?? null;
        if (! $provider) return false;

        $result = $provider->refreshStatus($d);
        if ($result->status === $d->status) return false;

        DB::transaction(function () use ($d, $result) {
            $d->update([
                'status'            => $result->status->value,
                'provider_response' => $result->raw,
                'settled_at'        => $result->status === DisbursementStatus::Settled ? now() : $d->settled_at,
                'failed_at'         => $result->status === DisbursementStatus::Failed ? now() : $d->failed_at,
                'failure_reason'    => $result->failureReason,
            ]);

            if ($result->status === DisbursementStatus::Settled) {
                $this->settle($d);
            }
        });

        return true;
    }

    /**
     * GhIPSS is a bulk-file rail with no per-row status API — `reconcile()`
     * polling can never flip those rows to Settled, so without this the
     * net-pay-payable they cleared at accrual would grow unbounded. After the
     * sponsor bank confirms the overnight batch landed, an operator calls this:
     * each Sent GhIPSS row for the run flips to Settled and posts its settlement
     * JE (DR net-pay payable / CR bank). Idempotent — already-Settled rows are
     * not in the Sent set, and settle()'s PostingService key blocks re-posting.
     */
    public function confirmGhipssSettlement(PayrollRun $run): int
    {
        $rows = Disbursement::where('payroll_run_id', $run->id)
            ->where('channel', DisbursementChannel::GhipssAch->value)
            ->where('status', DisbursementStatus::Sent->value)
            ->get();

        $settled = 0;
        foreach ($rows as $d) {
            DB::transaction(function () use ($d) {
                $d->update([
                    'status'     => DisbursementStatus::Settled->value,
                    'settled_at' => now(),
                ]);
                $this->settle($d);
            });
            $settled++;
        }

        return $settled;
    }

    private function settle(Disbursement $d): void
    {
        // Settlement disbursements are additive tracking only — the final-settlement
        // payment JE already cleared net-pay payable, so posting here would double-clear.
        if ($d->final_settlement_id !== null) {
            return;
        }

        $bankGlId = $this->resolveSettlementBankGlId();

        $this->posting->post(new PostingDocument(
            sourceType: JournalSourceType::Disbursement,
            sourceId: $d->id,
            purpose: 'settlement',
            date: now()->toDateString(),
            narration: "Disbursement settlement: #{$d->id}",
            lines: [
                PostingLine::debit(slug: 'payroll.net_pay_payable', amount: (float) $d->gross_amount, narration: 'Clear net pay payable'),
                PostingLine::credit(accountId: $bankGlId, amount: (float) $d->gross_amount, narration: 'Cash out to recipient'),
            ],
        ));
    }

    private function resolveSettlementBankGlId(): int
    {
        $bank = OrgBankAccount::query()
            ->where('purpose', OrgBankAccountPurpose::Payroll->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $bank || ! $bank->gl_account_id) {
            throw new \DomainException('No active payroll bank account is configured; cannot post disbursement settlement.');
        }

        return (int) $bank->gl_account_id;
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
        return $this->eLevyRateOn($run->period_end);
    }
}
