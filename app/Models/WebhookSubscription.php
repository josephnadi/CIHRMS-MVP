<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebhookSubscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'target_url', 'signing_secret',
        'event_types', 'is_active', 'created_by',
        'last_success_at', 'last_failure_at', 'consecutive_failures',
    ];

    protected $hidden = ['signing_secret'];

    protected function casts(): array
    {
        return [
            'event_types'     => 'array',
            'is_active'       => 'bool',
            'signing_secret'  => 'encrypted',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array('*', $this->event_types ?? [], true)
            || in_array($eventType, $this->event_types ?? [], true);
    }
}
