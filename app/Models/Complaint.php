<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['reference', 'submitted_by', 'assigned_to', 'details', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ComplaintStatus::class,
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ComplaintStatus::Open);
    }

    /** Investigator / handler responsible for this complaint. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }
}
