<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeeAssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The (member × fee_product × period) join. Tracks which members owe
 * which fees for which billing period, decoupled from whether the AR
 * invoice has been minted yet. Re-running a billing run for the same
 * period is idempotent because of the unique key on
 * (member_id, fee_product_id, period_label).
 */
class FeeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'fee_product_id',
        'period_label',
        'due_date',
        'ar_invoice_id',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status'   => FeeAssignmentStatus::class,
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function feeProduct(): BelongsTo
    {
        return $this->belongsTo(FeeProduct::class);
    }

    public function arInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', FeeAssignmentStatus::Pending->value);
    }

    public function scopeBilled(Builder $q): Builder
    {
        return $q->where('status', FeeAssignmentStatus::Billed->value);
    }
}
