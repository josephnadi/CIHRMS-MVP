<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\JournalSourceType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\OrgBankAccount;
use App\Models\StatutoryRate;
use App\Models\StatutoryReturn;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Statutory remittance posture: when a return is due (period end + the
 * effective remittance-deadline days, default 14), whether it has been filed,
 * and the write path that records a filing. There is no status column — state
 * is derived from submitted_at and the computed due date.
 */
class RemittanceService
{
    private const DEFAULT_DEADLINE_DAYS = 14;

    public function __construct(private readonly PostingService $posting) {}

    public function deadlineDays(CarbonInterface $periodEnd): int
    {
        try {
            return (int) StatutoryRate::lookup(StatutoryRate::REMITTANCE_DEADLINE_DAYS, $periodEnd);
        } catch (\Throwable $e) {
            return self::DEFAULT_DEADLINE_DAYS;
        }
    }

    public function dueDate(StatutoryReturn $return): ?CarbonImmutable
    {
        $return->loadMissing('run');
        $periodEnd = $return->run?->period_end;
        if ($periodEnd === null) {
            return null;
        }

        $end = CarbonImmutable::parse($periodEnd instanceof \DateTimeInterface ? $periodEnd->format('Y-m-d') : (string) $periodEnd);

        return $end->addDays($this->deadlineDays($end));
    }

    public function isOverdue(StatutoryReturn $return, ?CarbonInterface $now = null): bool
    {
        if ($return->submitted_at !== null) {
            return false;
        }
        $due = $this->dueDate($return);
        if ($due === null) {
            return false;
        }

        return ($now ?? CarbonImmutable::now())->greaterThan($due);
    }

    public function status(StatutoryReturn $return, ?CarbonInterface $now = null): string
    {
        if ($return->submitted_at !== null) {
            return 'submitted';
        }

        return $this->isOverdue($return, $now) ? 'overdue' : 'pending';
    }

    public function markSubmitted(StatutoryReturn $return, User $by, string $reference, ?CarbonInterface $submittedAt = null): StatutoryReturn
    {
        if ($return->submitted_at !== null) {
            throw new DomainException('This return has already been recorded as filed.');
        }

        return DB::transaction(function () use ($return, $by, $reference, $submittedAt) {
            $return->update([
                'submitted_at'         => $submittedAt ?? CarbonImmutable::now(),
                'submitted_by'         => $by->id,
                'submission_reference' => $reference,
            ]);

            // Clear the accrued liability and move the cash out. If posting is
            // blocked (closed period, missing bank/account map), the exception
            // rolls the whole filing back — fail-closed, never off-ledger.
            $this->postRemittance($return->fresh(), $by);

            return $return->fresh();
        });
    }

    /**
     * DR the statutory liability this return clears, CR the statutory-escrow
     * bank. Skipped for informational returns (NHIA/bank file) and zero-value
     * returns. Idempotent via the PostingService source key.
     */
    private function postRemittance(StatutoryReturn $return, User $by): void
    {
        $slug   = $return->kind->liabilitySlug();
        $amount = round((float) $return->total_amount, 2);

        if ($slug === null || $amount <= 0.0) {
            return;
        }

        $bankGlId = $this->resolveRemittanceBankGlId();

        $this->posting->post(new PostingDocument(
            sourceType: JournalSourceType::StatutoryRemittance,
            sourceId: $return->id,
            purpose: 'remittance',
            date: CarbonImmutable::now()->toDateString(),
            narration: "Statutory remittance: {$return->kind->label()} ({$return->submission_reference})",
            lines: [
                PostingLine::debit(slug: $slug, amount: $amount, narration: 'Clear statutory liability'),
                PostingLine::credit(accountId: $bankGlId, amount: $amount, narration: 'Cash out to authority'),
            ],
        ), $by);
    }

    /**
     * The bank the statutory payment leaves from — the dedicated statutory
     * escrow account if configured, else the payroll account.
     */
    private function resolveRemittanceBankGlId(): int
    {
        $bank = OrgBankAccount::query()
            ->whereIn('purpose', [
                OrgBankAccountPurpose::StatutoryEscrow->value,
                OrgBankAccountPurpose::Payroll->value,
            ])
            ->where('is_active', true)
            // Prefer the statutory-escrow account when both exist.
            ->orderByRaw("CASE WHEN purpose = ? THEN 0 ELSE 1 END", [OrgBankAccountPurpose::StatutoryEscrow->value])
            ->orderBy('id')
            ->first();

        if (! $bank || ! $bank->gl_account_id) {
            throw new DomainException('No active statutory-escrow or payroll bank account is configured; cannot post the remittance.');
        }

        return (int) $bank->gl_account_id;
    }

    /** @return array{generated:int, submitted:int, overdue:int} */
    public function posture(?CarbonInterface $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $total     = StatutoryReturn::count();
        $submitted = StatutoryReturn::query()->whereNotNull('submitted_at')->count();

        // Overdue: unsubmitted, and now is past period_end + default deadline days.
        // (A single current deadline value is sufficient for this aggregate nag.)
        $cutoff = $now->subDays(self::DEFAULT_DEADLINE_DAYS)->toDateString();
        $overdue = StatutoryReturn::query()
            ->whereNull('statutory_returns.submitted_at')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'statutory_returns.payroll_run_id')
            ->whereNotNull('payroll_runs.period_end')
            ->where('payroll_runs.period_end', '<', $cutoff)
            ->count();

        return [
            'generated' => $total - $submitted,
            'submitted' => $submitted,
            'overdue'   => $overdue,
        ];
    }
}
