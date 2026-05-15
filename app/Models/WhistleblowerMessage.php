<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowerMessage extends Model
{
    protected $fillable = [
        'report_id', 'direction', 'body',
        'posted_by', 'posted_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'body'      => 'encrypted',
            'posted_at' => 'datetime',
            'read_at'   => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WhistleblowerReport::class, 'report_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
