<?php

namespace App\Events;

use App\Models\FinalSettlement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SettlementApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly FinalSettlement $settlement) {}
}
