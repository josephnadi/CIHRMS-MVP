<?php

namespace App\Models;

use App\Enums\SsoLoginOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoLoginAttempt extends Model
{
    protected $fillable = [
        'provider_id', 'user_id', 'external_subject_id', 'external_email',
        'outcome', 'error', 'claims_snapshot',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'outcome'         => SsoLoginOutcome::class,
            'claims_snapshot' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SsoIdentityProvider::class, 'provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
