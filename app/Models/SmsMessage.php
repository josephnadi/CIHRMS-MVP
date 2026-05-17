<?php

namespace App\Models;

use App\Enums\SmsStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $fillable = [
        'to_phone', 'from_sender', 'body', 'provider', 'status',
        'provider_message_id', 'segments', 'cost', 'failure_reason',
        'context_type', 'context_id', 'triggered_by',
        'sent_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => SmsStatus::class,
            'segments'     => 'integer',
            'cost'         => 'decimal:4',
            'sent_at'      => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeQueued(Builder $q): Builder
    {
        return $q->where('status', SmsStatus::Queued->value);
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->where('status', SmsStatus::Failed->value);
    }
}
