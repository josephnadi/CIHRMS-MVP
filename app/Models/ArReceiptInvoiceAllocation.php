<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArReceiptInvoiceAllocation extends Model
{
    use HasFactory;

    protected $table = 'ar_receipt_invoice_allocations';

    protected $fillable = [
        'ar_receipt_id', 'ar_invoice_id', 'allocated_amount', 'notes',
    ];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ArReceipt::class, 'ar_receipt_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }
}
