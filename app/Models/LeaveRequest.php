<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'approved_by',
        'start_date',
        'end_date',
        'type',
        'reason',
        'status',
        'decision_comment',
        'decided_at',
        'attachment_path',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
            'decided_at' => 'datetime',
            'status'     => LeaveStatus::class,
            'type'       => LeaveType::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', LeaveStatus::Pending);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', LeaveStatus::Approved);
    }

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function durationInDays(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /** Weekdays (Mon–Fri) between start and end, inclusive. */
    public function workingDays(): int
    {
        $days   = 0;
        $cursor = $this->start_date->copy();
        while ($cursor->lessThanOrEqualTo($this->end_date)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Days charged against the leave balance: working days for most types,
     * calendar days for statutory calendar-week leave (maternity).
     */
    public function chargeableDays(): float
    {
        return $this->type->countsWorkingDaysOnly()
            ? (float) $this->workingDays()
            : (float) $this->durationInDays();
    }
}
