<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentConditionOnReturn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'asset_id', 'employee_id', 'assigned_at', 'assigned_by',
        'due_back_at', 'returned_at', 'returned_to', 'condition_on_return',
        'notes', 'signed_handover_path',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at'         => 'datetime',
            'due_back_at'         => 'date',
            'returned_at'         => 'datetime',
            'condition_on_return' => AssignmentConditionOnReturn::class,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function returnedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_to');
    }

    public function scopeOpen($q)
    {
        return $q->whereNull('returned_at');
    }
}
