<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalibrationAdjustment extends Model
{
    protected $fillable = [
        'session_id', 'review_id',
        'original_rating', 'adjusted_rating',
        'reason', 'adjusted_by', 'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'original_rating' => 'decimal:2',
            'adjusted_rating' => 'decimal:2',
            'adjusted_at'     => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'session_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
