<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id', 'device_id', 'source', 'direction',
        'event_at', 'recorded_at', 'geo_lat', 'geo_lng',
        'biometric_score', 'recorded_by', 'reason', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'source'      => AttendanceSource::class,
            'event_at'    => 'datetime',
            'recorded_at' => 'datetime',
            'geo_lat'     => 'decimal:7',
            'geo_lng'     => 'decimal:7',
            'raw_payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeForEmployeeOn(Builder $q, int $employeeId, \DateTimeInterface|string $date): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;
        return $q->where('employee_id', $employeeId)
            ->whereDate('event_at', $date)
            ->orderBy('event_at');
    }
}
