<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\FeeAssignmentStatus;
use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\ArInvoice;
use App\Models\FeeAssignment;
use App\Models\FeeProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\SequenceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Mints AR invoices for a `FeeProduct` against eligible members for a
 * specific period. Idempotent: re-running for the same (member, product,
 * period) is a no-op for already-billed assignments.
 *
 * Invoices are created in Draft state by `ArInvoiceService::create()` —
 * approval is a separate finance step (existing AR workflow). This keeps
 * us out of the "creator cannot self-approve" guard.
 */
class BillingRunService
{
    public function __construct(
        private readonly ArInvoiceService $invoices,
        private readonly SequenceService $sequences,
    ) {
    }

    /**
     * @param  array<int, int>|null  $memberIds  Optional subset filter. When
     *                                            NULL, ALL eligible members
     *                                            (active + matching class)
     *                                            are billed.
     */
    public function run(
        FeeProduct $product,
        string $periodLabel,
        User $operator,
        ?array $memberIds = null,
        ?CarbonImmutable $dueDate = null,
        ?CarbonImmutable $invoiceDate = null,
    ): BillingRunResult {
        if (!$product->is_active) {
            throw new DomainException("Fee product {$product->code} is not active.");
        }
        $periodLabel = trim($periodLabel);
        if ($periodLabel === '') {
            throw new DomainException('A non-empty period label is required.');
        }

        $invoiceDate = $invoiceDate ?? CarbonImmutable::today();

        $reference = $this->mintRunReference();

        $query = Member::query()->where('status', MemberStatus::Active->value);
        if (!empty($memberIds)) {
            $query->whereIn('id', $memberIds);
        }
        $members = $query->get();

        $eligible = $members->filter(
            fn (Member $m) => $product->appliesToClass($m->class instanceof MemberClass ? $m->class : MemberClass::from((string) $m->class))
        );

        $assignmentsCreated = 0;
        $invoicesCreated    = 0;
        $alreadyBilled      = 0;
        $invoiceIds         = [];

        foreach ($eligible as $member) {
            DB::transaction(function () use (
                $member, $product, $periodLabel, $operator, $reference, $invoiceDate, $dueDate,
                &$assignmentsCreated, &$invoicesCreated, &$alreadyBilled, &$invoiceIds,
            ) {
                // Upsert the (member, product, period) row. firstOrCreate is
                // race-safe under the unique index.
                $assignment = FeeAssignment::firstOrCreate(
                    [
                        'member_id'      => $member->id,
                        'fee_product_id' => $product->id,
                        'period_label'   => $periodLabel,
                    ],
                    [
                        'due_date'   => $dueDate?->toDateString(),
                        'status'     => FeeAssignmentStatus::Pending->value,
                        'created_by' => $operator->id,
                    ],
                );

                $isNewAssignment = $assignment->wasRecentlyCreated;
                if ($isNewAssignment) {
                    $assignmentsCreated++;
                }

                // If we already billed this assignment, leave it alone.
                if ($assignment->ar_invoice_id !== null) {
                    $alreadyBilled++;
                    return;
                }

                // Mint a Draft AR invoice via the existing service so the
                // GL accrual journal is auto-minted per F3 conventions.
                $invoice = $this->invoices->create(
                    [
                        'customer_id'  => $member->customer_id,
                        'invoice_date' => $invoiceDate->toDateString(),
                        'due_date'     => $dueDate?->toDateString(),
                        'currency'     => $product->currency,
                        'notes'        => "Billing run {$reference} — {$product->name} ({$periodLabel})",
                        'lines'        => [
                            [
                                'description'   => "{$product->name} — {$periodLabel}",
                                'quantity'      => 1,
                                'unit_price'    => (float) $product->amount,
                                'tax_rate'      => 0,
                                'gl_account_id' => $product->gl_income_account_id,
                            ],
                        ],
                    ],
                    $operator,
                );

                $assignment->update([
                    'ar_invoice_id' => $invoice->id,
                    'status'        => FeeAssignmentStatus::Billed->value,
                ]);

                $invoicesCreated++;
                $invoiceIds[] = $invoice->id;
            });
        }

        return new BillingRunResult(
            reference: $reference,
            eligibleMembers: $eligible->count(),
            assignmentsCreated: $assignmentsCreated,
            invoicesCreated: $invoicesCreated,
            alreadyBilled: $alreadyBilled,
            invoiceIds: $invoiceIds,
        );
    }

    /**
     * Cancel a single fee assignment. If the underlying AR invoice exists
     * and is still in Draft, the caller is expected to cancel it via the
     * existing `ArInvoiceService::cancel()` separately (out of scope here
     * to avoid coupling the two domains too tightly).
     */
    public function cancelAssignment(FeeAssignment $assignment, User $operator): FeeAssignment
    {
        if ($assignment->status === FeeAssignmentStatus::Cancelled) {
            return $assignment;
        }
        $assignment->update([
            'status' => FeeAssignmentStatus::Cancelled->value,
        ]);
        return $assignment->refresh();
    }

    private function mintRunReference(): string
    {
        $year = now()->year;
        $n    = $this->sequences->next("billing_run:{$year}");
        return sprintf('BR-%s-%04d', $year, $n);
    }
}
