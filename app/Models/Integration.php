<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'provider',
    'capability',
    'display_name',
    'logo',
    'is_enabled',
    'config',
    'connected_by',
    'connected_at',
])]
class Integration extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'config'       => 'array',
            'is_enabled'   => 'boolean',
            'connected_at' => 'datetime',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(IntegrationToken::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('is_enabled', true);
    }

    public function scopeForCapability(Builder $q, string $capability): Builder
    {
        return $q->where('capability', $capability);
    }

    public function isConnected(): bool
    {
        return $this->is_enabled && $this->tokens()->exists();
    }

    public function serviceToken(): ?IntegrationToken
    {
        return $this->tokens()->whereNull('user_id')->latest()->first();
    }
}
