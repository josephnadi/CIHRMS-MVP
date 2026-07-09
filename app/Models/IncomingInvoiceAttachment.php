<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingInvoiceAttachment extends Model
{
    protected $fillable = [
        'incoming_invoice_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(IncomingInvoice::class, 'incoming_invoice_id');
    }
}
