<?php

namespace App\Models;

use App\Enums\CalibrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalibrationSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cycle_id', 'department_id', 'status',
        'facilitated_by', 'opened_at', 'locked_at',
        'applied_at', 'applied_by',
        'target_distribution', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'              => CalibrationStatus::class,
            'opened_at'           => 'datetime',
            'locked_at'           => 'datetime',
            'applied_at'          => 'datetime',
            'target_distribution' => 'array',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'cycle_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitated_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CalibrationAdjustment::class, 'session_id');
    }
}
