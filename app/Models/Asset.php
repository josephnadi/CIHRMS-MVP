<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'asset_tag', 'name', 'category', 'serial_number',
        'brand', 'model', 'purchase_date', 'purchase_cost',
        'currency', 'supplier', 'warranty_expires_at',
        'current_status', 'current_assignment_id', 'location', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'category'            => AssetCategory::class,
            'current_status'      => AssetStatus::class,
            'purchase_date'       => 'date',
            'warranty_expires_at' => 'date',
            'purchase_cost'       => 'decimal:2',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(AssetAssignment::class, 'current_assignment_id');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    public function depreciationSnapshots(): HasMany
    {
        return $this->hasMany(AssetDepreciationSnapshot::class);
    }

    public function scopeInStock($q)
    {
        return $q->where('current_status', AssetStatus::InStock->value);
    }

    public function scopeAssigned($q)
    {
        return $q->where('current_status', AssetStatus::Assigned->value);
    }
}
