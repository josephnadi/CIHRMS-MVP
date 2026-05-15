<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Enums\ReviewType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cycle_id', 'employee_id', 'reviewer_id', 'type',
        'overall_rating', 'performance_rating', 'potential_rating',
        'strengths', 'opportunities', 'comments',
        'status', 'submitted_at', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'overall_rating'     => 'decimal:2',
            'performance_rating' => 'decimal:2',
            'potential_rating'   => 'decimal:2',
            'submitted_at'       => 'datetime',
            'acknowledged_at'    => 'datetime',
            'type'               => ReviewType::class,
            'status'             => ReviewStatus::class,
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function scopeSubmitted(Builder $q): Builder
    {
        return $q->whereIn('status', [ReviewStatus::Submitted, ReviewStatus::Acknowledged]);
    }

    public function scopeForCycle(Builder $q, int $cycleId): Builder
    {
        return $q->where('cycle_id', $cycleId);
    }

    public function scopeForEmployee(Builder $q, int $employeeId): Builder
    {
        return $q->where('employee_id', $employeeId);
    }
}
