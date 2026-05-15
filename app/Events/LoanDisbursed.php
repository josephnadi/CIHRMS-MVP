<?php

namespace App\Events;

use App\Models\LoanAccount;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoanDisbursed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LoanAccount $loan) {}
}
