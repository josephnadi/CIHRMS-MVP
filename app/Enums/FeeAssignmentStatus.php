<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State of a fee assignment row — the join between a Member and a
 * FeeProduct for a specific period. The Status lifecycle is independent
 * from the resulting `ArInvoice`'s lifecycle:
 *
 *   Pending  — assignment created (e.g. mid-year onboarding), invoice not yet minted
 *   Billed   — `ar_invoice_id` populated; AR invoice owns settlement status from here
 *   Cancelled — admin voided the assignment before billing (or after, if the
 *               invoice is also cancelled / written off)
 */
enum FeeAssignmentStatus: string
{
    case Pending   = 'pending';
    case Billed    = 'billed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Billed    => 'Billed',
            self::Cancelled => 'Cancelled',
        };
    }
}
