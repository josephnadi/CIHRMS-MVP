<?php

namespace App\Models;

use App\Enums\EnrolmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrolment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_id', 'employee_id', 'status',
        'progress_pct', 'final_score', 'certificate_path',
        'enrolled_at', 'started_at', 'completed_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => EnrolmentStatus::class,
            'progress_pct'     => 'decimal:2',
            'final_score'      => 'decimal:2',
            'enrolled_at'      => 'datetime',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [EnrolmentStatus::Pending, EnrolmentStatus::Active]);
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', EnrolmentStatus::Completed);
    }
}
