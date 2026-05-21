<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a PayrollRun transitions to the Paid state. Subscribers:
 *   - `MintGifmisJournal` — auto-generates the GIFMIS JV when the
 *     `payroll.gifmis.auto_mint_on_paid` config flag is enabled.
 *
 * Distinct from `PayrollRunApproved` because approval ≠ payment: an
 * approved run can sit for hours/days before disbursement; the journal
 * should only post once the money has actually moved.
 */
class PayrollRunPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PayrollRun $run) {}
}
