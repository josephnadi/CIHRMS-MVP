<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BiometricDevice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'vendor', 'location', 'department_id',
        'shared_secret', 'geo_lat', 'geo_lng', 'geo_radius_m',
        'is_active', 'last_seen_at',
    ];

    protected $hidden = ['shared_secret'];

    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'is_active'     => 'bool',
            'last_seen_at'  => 'datetime',
            'geo_lat'       => 'decimal:7',
            'geo_lng'       => 'decimal:7',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'device_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
