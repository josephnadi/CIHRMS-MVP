<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single internal chat message. Not named `Message` to avoid clashing
 * with the existing SMS/USSD messaging layer (`MessagingDispatcher`,
 * `MessagingController`).
 */
class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'deleted_for_everyone_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_for_everyone_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereNull('deleted_for_everyone_at');
    }
}
