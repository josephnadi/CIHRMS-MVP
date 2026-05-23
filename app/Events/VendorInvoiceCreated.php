<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VendorInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VendorInvoiceCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly VendorInvoice $invoice)
    {
    }
}
