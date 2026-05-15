<?php

namespace App\Models;

use App\Enums\ReviewCycleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReviewCycle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'cadence', 'starts_at', 'ends_at',
        'self_review_due', 'peer_review_due', 'manager_review_due',
        'status', 'opened_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'          => 'date',
            'ends_at'            => 'date',
            'self_review_due'    => 'date',
            'peer_review_due'    => 'date',
            'manager_review_due' => 'date',
            'closed_at'          => 'datetime',
            'status'             => ReviewCycleStatus::class,
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'cycle_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class, 'cycle_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', ReviewCycleStatus::Active);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [ReviewCycleStatus::Draft, ReviewCycleStatus::Active]);
    }
}
