<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IncomingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncomingInvoiceSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly IncomingInvoice $invoice)
    {
    }
}
