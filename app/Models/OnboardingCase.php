<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingCase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'employee_id', 'initiated_by', 'status',
        'hire_date', 'target_completion_date', 'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => OnboardingStatus::class,
            'hire_date'              => 'date',
            'target_completion_date' => 'date',
            'completed_at'           => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [OnboardingStatus::Draft->value, OnboardingStatus::InProgress->value]);
    }

    /** Every required task is Completed or Skipped. */
    public function isComplete(): bool
    {
        return ! $this->tasks()
            ->where('is_required', true)
            ->whereNotIn('status', [OnboardingTaskStatus::Completed->value, OnboardingTaskStatus::Skipped->value])
            ->exists();
    }

    public function progress(): float
    {
        $total = $this->tasks()->count();
        if ($total === 0) {
            return 0.0;
        }
        $done = $this->tasks()
            ->whereIn('status', [OnboardingTaskStatus::Completed->value, OnboardingTaskStatus::Skipped->value])
            ->count();

        return round($done / $total, 4);
    }
}
