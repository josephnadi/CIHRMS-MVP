<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentityLink extends Model
{
    protected $fillable = [
        'user_id', 'provider_id', 'external_subject_id', 'external_email',
        'last_claims', 'linked_at', 'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'last_claims'   => 'array',
            'linked_at'     => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SsoIdentityProvider::class, 'provider_id');
    }
}
