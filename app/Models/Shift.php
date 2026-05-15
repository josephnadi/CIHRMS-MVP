<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'start_time', 'end_time',
        'grace_period_minutes', 'full_day_hours', 'half_day_hours',
        'working_days', 'department_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'working_days'         => 'array',
            'is_active'            => 'boolean',
            'full_day_hours'       => 'decimal:2',
            'half_day_hours'       => 'decimal:2',
            'grace_period_minutes' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
