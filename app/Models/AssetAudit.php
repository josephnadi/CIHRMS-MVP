<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetAuditStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetAudit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'status', 'scope_type', 'scope_value',
        'total_lines', 'counted_lines', 'discrepancy_lines', 'notes',
        'opened_by', 'opened_at', 'completed_by', 'completed_at',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = ['status' => 'in_progress', 'scope_type' => 'all'];

    protected function casts(): array
    {
        return [
            'status'       => AssetAuditStatus::class,
            'opened_at'    => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetAuditLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetAuditEvent::class)->orderByDesc('id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
