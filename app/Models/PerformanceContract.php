<?php

namespace App\Models;

use App\Enums\PerformanceContractStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PerformanceContract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cycle_id', 'employee_id', 'supervisor_id', 'status',
        'kpis', 'balanced_scorecard', 'weighted_achievement',
        'drafted_by', 'employee_signed_at', 'supervisor_signed_at',
        'finalised_by', 'finalised_at',
        'mid_year_note', 'end_year_note',
    ];

    protected function casts(): array
    {
        return [
            'status'               => PerformanceContractStatus::class,
            'kpis'                 => 'array',
            'balanced_scorecard'   => 'array',
            'weighted_achievement' => 'decimal:2',
            'employee_signed_at'   => 'datetime',
            'supervisor_signed_at' => 'datetime',
            'finalised_at'         => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function drafter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by');
    }

    public function isFullySigned(): bool
    {
        return $this->employee_signed_at !== null && $this->supervisor_signed_at !== null;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', PerformanceContractStatus::Active->value);
    }
}
