<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceCorrection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'attendance_record_id', 'employee_id', 'requester_id',
        'requested_event_at', 'requested_direction', 'reason',
        'status', 'reviewer_id', 'reviewed_at', 'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_event_at' => 'datetime',
            'reviewed_at'        => 'datetime',
            'status'             => CorrectionStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function scopePending($q)
    {
        return $q->where('status', CorrectionStatus::Pending->value);
    }
}
