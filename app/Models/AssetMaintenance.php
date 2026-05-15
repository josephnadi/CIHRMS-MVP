<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetMaintenance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'asset_maintenance';

    protected $fillable = [
        'asset_id', 'type', 'status', 'started_at',
        'completed_at', 'cost', 'vendor', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type'         => MaintenanceType::class,
            'status'       => MaintenanceStatus::class,
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'cost'         => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
