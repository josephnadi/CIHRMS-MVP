<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BroadcastRecipient extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'broadcast_id', 'recipient_type', 'recipient_id',
        'sms_message_id', 'sms_status', 'mail_status', 'mail_failure_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function smsMessage(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class);
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
