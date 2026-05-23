<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaystackWebhookEvent extends Model
{
    // Webhook log: append-only audit, no timestamps beyond received_at.
    public $timestamps = false;

    protected $table = 'paystack_webhook_events';

    protected $fillable = [
        'paystack_event_id', 'event_type', 'paystack_reference',
        'payload', 'signature',
        'payment_intent_id', 'ar_receipt_id',
        'processed_at', 'processing_error', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
            'received_at'  => 'datetime',
        ];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ArReceipt::class, 'ar_receipt_id');
    }
}
