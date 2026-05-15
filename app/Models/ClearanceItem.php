<?php

namespace App\Models;

use App\Enums\ClearanceArea;
use App\Enums\ClearanceItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearanceItem extends Model
{
    protected $fillable = [
        'offboarding_case_id', 'area', 'label', 'status',
        'responsible_department_id', 'responsible_user_id',
        'is_required',
        'cleared_by', 'cleared_at', 'notes', 'evidence_paths',
    ];

    protected function casts(): array
    {
        return [
            'area'           => ClearanceArea::class,
            'status'         => ClearanceItemStatus::class,
            'is_required'    => 'bool',
            'cleared_at'     => 'datetime',
            'evidence_paths' => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', ClearanceItemStatus::Pending->value);
    }
}
