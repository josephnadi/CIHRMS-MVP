<?php

namespace App\Models;

use App\Enums\ClearanceItemStatus;
use App\Enums\ExitType;
use App\Enums\OffboardingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class OffboardingCase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'employee_id', 'initiated_by',
        'exit_type', 'status',
        'notice_received_on', 'last_working_day', 'effective_termination_date',
        'rehire_eligible', 'reason', 'exit_interview_summary',
        'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'exit_type'                  => ExitType::class,
            'status'                     => OffboardingStatus::class,
            'notice_received_on'         => 'date',
            'last_working_day'           => 'date',
            'effective_termination_date' => 'date',
            'rehire_eligible'            => 'bool',
            'completed_at'               => 'datetime',
        ];
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

    public function clearanceItems(): HasMany
    {
        return $this->hasMany(ClearanceItem::class);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(FinalSettlement::class)->latestOfMany();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [
            OffboardingStatus::Completed->value,
            OffboardingStatus::Cancelled->value,
        ]);
    }

    /** All required clearance items are cleared or waived. */
    public function isClearanceComplete(): bool
    {
        $blockingPending = $this->clearanceItems()
            ->where('is_required', true)
            ->where('status', ClearanceItemStatus::Pending->value)
            ->exists();

        return ! $blockingPending && $this->clearanceItems()->exists();
    }

    public function clearanceProgress(): float
    {
        $total = $this->clearanceItems()->count();
        if ($total === 0) return 0.0;

        $done = $this->clearanceItems()
            ->whereIn('status', [ClearanceItemStatus::Cleared->value, ClearanceItemStatus::Waived->value])
            ->count();

        return round($done / $total, 4);
    }
}
