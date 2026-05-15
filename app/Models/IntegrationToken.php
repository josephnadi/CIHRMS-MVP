<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id',
    'user_id',
    'access_token',
    'refresh_token',
    'scopes',
    'expires_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class IntegrationToken extends Model
{
    protected function casts(): array
    {
        return [
            'access_token'  => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes'        => 'array',
            'expires_at'    => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresWithin(int $minutes): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now()->addMinutes($minutes));
    }
}
