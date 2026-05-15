<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayslipGenerated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $pdfBase64  Base64-encoded PDF bytes (kept off-disk so the listener can
     *                             stream it straight to OneDrive/Drive without local storage).
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly string $pdfBase64,
        public readonly string $filename,
        public readonly ?User $actor = null,
    ) {}
}
