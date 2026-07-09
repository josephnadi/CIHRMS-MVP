<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A straight-line release schedule for one deferred posting (e.g. a subscription
 * collected in advance). Its entries move the balance from the deferred liability
 * to income, one month at a time.
 */
class RevenueRecognitionSchedule extends Model
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'source_type', 'source_id', 'member_id',
        'income_gl_account_id', 'deferred_gl_account_id',
        'total_amount', 'months', 'start_date', 'recognized_total', 'status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount'     => 'decimal:2',
            'recognized_total' => 'decimal:2',
            'months'           => 'int',
            'start_date'       => 'date',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RevenueRecognitionEntry::class, 'schedule_id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'income_gl_account_id');
    }

    public function deferredAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'deferred_gl_account_id');
    }
}
