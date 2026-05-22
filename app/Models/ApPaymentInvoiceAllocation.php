<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApPaymentInvoiceAllocation extends Model
{
    protected $table = 'ap_payment_invoice_allocations';

    protected $fillable = ['ap_payment_id', 'vendor_invoice_id', 'allocated_amount', 'notes'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ApPayment::class, 'ap_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
    }
}
