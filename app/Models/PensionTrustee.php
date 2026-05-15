<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PensionTrustee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'npra_license_number', 'contact_email', 'contact_phone',
        'schedule_format', 'is_active', 'schedule_columns',
    ];

    protected function casts(): array
    {
        return [
            'is_active'         => 'bool',
            'schedule_columns'  => 'array',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'tier2_trustee_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
