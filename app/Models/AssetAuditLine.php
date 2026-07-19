<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetAuditAction;
use App\Enums\AssetAuditResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAuditLine extends Model
{
    protected $fillable = [
        'asset_audit_id', 'asset_id', 'expected_status', 'expected_location',
        'expected_holder_employee_id', 'result', 'observed_location', 'observed_note',
        'is_discrepancy', 'counted_by', 'counted_at',
        'resolution_action', 'resolved_by', 'resolved_at', 'resolved_note',
    ];

    protected function casts(): array
    {
        return [
            'result'            => AssetAuditResult::class,
            'resolution_action' => AssetAuditAction::class,
            'is_discrepancy'    => 'boolean',
            'counted_at'        => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(AssetAudit::class, 'asset_audit_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function expectedHolder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_holder_employee_id');
    }
}
