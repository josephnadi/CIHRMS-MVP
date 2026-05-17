<?php

namespace App\Models;

use App\Enums\SsoProviderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SsoIdentityProvider extends Model
{
    use SoftDeletes;

    protected $table = 'identity_providers';

    protected $fillable = [
        'slug', 'name', 'type', 'is_active', 'auto_provision', 'default_role',
        'config', 'claim_mapping', 'allowed_email_domains',
        'button_label', 'button_icon', 'display_order',
    ];

    protected $hidden = ['config'];   // keep credentials out of API responses

    protected function casts(): array
    {
        return [
            'type'                  => SsoProviderType::class,
            'is_active'             => 'bool',
            'auto_provision'        => 'bool',
            'config'                => 'encrypted:array',
            'claim_mapping'         => 'array',
            'allowed_email_domains' => 'array',
        ];
    }

    public function links(): HasMany
    {
        return $this->hasMany(UserIdentityLink::class, 'provider_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SsoLoginAttempt::class, 'provider_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('display_order')->orderBy('name');
    }

    public function isEmailDomainAllowed(?string $email): bool
    {
        $allowed = $this->allowed_email_domains ?? [];
        if (empty($allowed)) return true;                  // no restriction
        if ($email === null) return false;

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        return in_array($domain, array_map('strtolower', $allowed), true);
    }

    /** Resolve a claim from `last_claims`-shaped payload using the configured mapping. */
    public function readClaim(array $claims, string $localKey): mixed
    {
        $mapping = $this->claim_mapping ?? [];
        $remoteKey = $mapping[$localKey] ?? $localKey;
        return $claims[$remoteKey] ?? null;
    }
}
