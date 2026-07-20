<?php

declare(strict_types=1);

return [

    'payouts' => [
        // Batches whose total is >= this (GHS) require the higher approver
        // (payouts.release_high). 0 disables the high-approval tier.
        'high_approval_threshold' => (float) env('PAYOUT_HIGH_APPROVAL_THRESHOLD', 50000),
    ],

];
