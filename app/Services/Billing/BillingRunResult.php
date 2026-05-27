<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Return value from `BillingRunService::run()`. Plain DTO; lightweight by
 * design — controllers serialise it for the admin UI.
 */
final readonly class BillingRunResult
{
    /**
     * @param  string  $reference        e.g. "BR-2026-0007"
     * @param  int     $eligibleMembers  how many members matched the run filter
     * @param  int     $assignmentsCreated  newly minted (member, product, period) rows
     * @param  int     $invoicesCreated  new AR invoices minted in Draft
     * @param  int     $alreadyBilled    members who already had a billed assignment for this period
     * @param  array<int, int>  $invoiceIds  list of `ar_invoices.id` (new ones only)
     */
    public function __construct(
        public string $reference,
        public int $eligibleMembers,
        public int $assignmentsCreated,
        public int $invoicesCreated,
        public int $alreadyBilled,
        public array $invoiceIds,
    ) {}

    public function toArray(): array
    {
        return [
            'reference'           => $this->reference,
            'eligible_members'    => $this->eligibleMembers,
            'assignments_created' => $this->assignmentsCreated,
            'invoices_created'    => $this->invoicesCreated,
            'already_billed'      => $this->alreadyBilled,
            'invoice_ids'         => $this->invoiceIds,
        ];
    }
}
