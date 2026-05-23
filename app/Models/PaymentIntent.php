<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentIntent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payment_intents';

    protected $fillable = [
        'reference', 'customer_id', 'ar_invoice_id', 'amount', 'currency', 'status',
        'paystack_reference', 'paystack_access_code', 'authorization_url', 'callback_url',
        'ar_receipt_id', 'narration', 'paid_at', 'expires_at', 'last_paystack_response',
        'created_by',
        // F4-R: refund audit
        'refunded_at', 'refund_amount', 'refund_reason',
        'refund_paystack_ref', 'refund_settled_at', 'refunded_by',
    ];

    protected $attributes = ['currency' => 'GHS', 'status' => 'created'];

    protected function casts(): array
    {
        return [
            'status'                 => PaymentIntentStatus::class,
            'amount'                 => 'decimal:2',
            'paid_at'                => 'datetime',
            'expires_at'             => 'datetime',
            'last_paystack_response' => 'array',
            // F4-R
            'refunded_at'            => 'datetime',
            'refund_amount'          => 'decimal:2',
            'refund_settled_at'      => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ArReceipt::class, 'ar_receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', PaymentIntentStatus::Pending->value);
    }

    public function scopeStale(Builder $q): Builder
    {
        return $q->where('status', PaymentIntentStatus::Pending->value)
                 ->whereNotNull('expires_at')
                 ->where('expires_at', '<', now());
    }

    public function scopeRefundable(Builder $q): Builder
    {
        return $q->where('status', PaymentIntentStatus::Success->value)
                 ->whereNull('refunded_at');
    }
}
