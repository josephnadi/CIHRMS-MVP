<?php

namespace App\Enums;

enum SsoLoginOutcome: string
{
    case Success        = 'success';
    case ProvidersError = 'provider_error';    // upstream IdP rejected / returned bad assertion
    case InvalidState   = 'invalid_state';     // CSRF / state mismatch
    case ClaimMissing   = 'claim_missing';     // required claim absent
    case UserDisabled   = 'user_disabled';     // matched user is deleted / disabled
    case ProvisionFailed = 'provision_failed'; // JIT provisioning blocked

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
