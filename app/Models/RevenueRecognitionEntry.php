<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's tranche of a {@see RevenueRecognitionSchedule}. Recognised by the
 * monthly run, which posts DR deferred / CR income and stamps journal_entry_id.
 */
class RevenueRecognitionEntry extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_RECOGNIZED = 'recognized';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'schedule_id', 'period_month', 'amount', 'status', 'recognized_at', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'recognized_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RevenueRecognitionSchedule::class, 'schedule_id');
    }
}
