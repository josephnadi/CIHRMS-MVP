<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Broadcast extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'audience_type', 'audience_params', 'channels',
        'template_id', 'sms_body', 'mail_subject', 'mail_body',
        'scheduled_at', 'throttle_overridden', 'throttle_override_reason',
        'status', 'created_by',
        'started_at', 'completed_at',
        'recipient_count', 'sms_sent_count', 'sms_failed_count',
        'sms_throttled_count', 'mail_sent_count', 'mail_failed_count',
    ];

    protected function casts(): array
    {
        return [
            'audience_type'       => BroadcastAudienceType::class,
            'audience_params'     => 'array',
            'channels'            => 'array',
            'status'              => BroadcastStatus::class,
            'throttle_overridden' => 'boolean',
            'scheduled_at'        => 'datetime',
            'started_at'          => 'datetime',
            'completed_at'        => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BroadcastTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', BroadcastStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now());
    }
}
