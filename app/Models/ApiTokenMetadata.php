<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenMetadata extends Model
{
    protected $table = 'api_token_metadata';

    protected $fillable = [
        'token_id', 'issued_to_user_id', 'issued_by_user_id', 'purpose',
        'rate_limit_per_minute', 'expires_at', 'revoked_at', 'revoked_by',
        'allowed_ip_cidrs',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'            => 'datetime',
            'revoked_at'            => 'datetime',
            'allowed_ip_cidrs'      => 'array',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }
}
