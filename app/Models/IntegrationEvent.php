<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id',
    'direction',
    'event_type',
    'external_id',
    'payload',
    'response',
    'status',
    'error',
    'attempts',
    'processed_at',
])]
class IntegrationEvent extends Model
{
    public const STATUS_QUEUED   = 'queued';
    public const STATUS_SENT     = 'sent';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_RECEIVED = 'received';

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND  = 'inbound';

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'response'     => 'array',
            'attempts'     => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_FAILED);
    }

    public function scopeOutbound(Builder $q): Builder
    {
        return $q->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeInbound(Builder $q): Builder
    {
        return $q->where('direction', self::DIRECTION_INBOUND);
    }

    public function markSent(?array $response = null): void
    {
        $this->update([
            'status'       => self::STATUS_SENT,
            'response'     => $response,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status'       => self::STATUS_FAILED,
            'error'        => $error,
            'attempts'     => $this->attempts + 1,
            'processed_at' => now(),
        ]);
    }
}
