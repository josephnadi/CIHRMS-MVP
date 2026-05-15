<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalCheckin extends Model
{
    protected $fillable = [
        'goal_id', 'user_id',
        'progress_pct', 'current_value',
        'narrative', 'mood', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_pct'  => 'decimal:2',
            'current_value' => 'decimal:2',
            'recorded_at'   => 'datetime',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
