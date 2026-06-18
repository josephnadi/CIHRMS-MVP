<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    protected $table = 'fiscal_periods';

    protected $fillable = [
        'fiscal_year_id', 'period_no', 'name', 'starts_on', 'ends_on',
        'status', 'closed_at', 'closed_by', 'locked_at', 'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'period_no' => 'integer',
            'status'    => FiscalPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'closed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
