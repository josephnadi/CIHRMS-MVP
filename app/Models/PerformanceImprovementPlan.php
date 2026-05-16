<?php

namespace App\Models;

use App\Enums\PipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PerformanceImprovementPlan extends Model
{
    use SoftDeletes;

    protected $table = 'performance_improvement_plans';

    protected $fillable = [
        'employee_id', 'triggered_by_review_id', 'opened_by', 'mentor_id',
        'status', 'opened_on', 'target_end_date', 'actual_end_date',
        'extensions_used', 'max_extensions',
        'target_metrics', 'checkins', 'outcome_summary',
    ];

    protected function casts(): array
    {
        return [
            'status'           => PipStatus::class,
            'opened_on'        => 'date',
            'target_end_date'  => 'date',
            'actual_end_date'  => 'date',
            'target_metrics'   => 'array',
            'checkins'         => 'array',
            'extensions_used'  => 'integer',
            'max_extensions'   => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'mentor_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function triggerReview(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'triggered_by_review_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [PipStatus::Open->value, PipStatus::InProgress->value, PipStatus::Extended->value]);
    }
}
