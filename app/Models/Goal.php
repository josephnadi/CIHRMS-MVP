<?php

namespace App\Models;

use App\Enums\GoalCadence;
use App\Enums\GoalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'parent_goal_id', 'cycle_id',
        'title', 'description', 'cadence',
        'target_value', 'current_value', 'unit', 'weight',
        'status', 'starts_at', 'due_at', 'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_value'  => 'decimal:2',
            'current_value' => 'decimal:2',
            'weight'        => 'integer',
            'starts_at'     => 'date',
            'due_at'        => 'date',
            'completed_at'  => 'datetime',
            'status'        => GoalStatus::class,
            'cadence'       => GoalCadence::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'cycle_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_goal_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_goal_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(GoalCheckin::class)->latest('recorded_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Derived progress percentage based on current vs target. Returns 0..100. */
    public function progressPct(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->target_value || (float) $this->target_value <= 0) {
                return $this->status === GoalStatus::Completed ? 100.0 : 0.0;
            }
            $pct = ((float) $this->current_value / (float) $this->target_value) * 100;
            return max(0.0, min(100.0, round($pct, 1)));
        });
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [GoalStatus::Active, GoalStatus::AtRisk]);
    }

    public function scopeForEmployee(Builder $q, int $employeeId): Builder
    {
        return $q->where('employee_id', $employeeId);
    }

    public function scopeForCycle(Builder $q, int $cycleId): Builder
    {
        return $q->where('cycle_id', $cycleId);
    }
}
