<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSummary extends Model
{
    protected $fillable = [
        'employee_id', 'summary_date', 'status',
        'first_in', 'last_out', 'hours_worked', 'overtime_hours',
        'is_weekend', 'is_holiday', 'source',
    ];

    protected function casts(): array
    {
        return [
            'status'         => AttendanceStatus::class,
            'summary_date'   => 'date',
            'hours_worked'   => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'is_weekend'     => 'bool',
            'is_holiday'     => 'bool',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeBetween(Builder $q, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        $from = $from instanceof \DateTimeInterface ? $from->format('Y-m-d') : $from;
        $to   = $to   instanceof \DateTimeInterface ? $to->format('Y-m-d')   : $to;
        return $q->whereBetween('summary_date', [$from, $to]);
    }
}
