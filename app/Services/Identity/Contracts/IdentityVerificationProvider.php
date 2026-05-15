<?php

namespace App\Services\Identity\Contracts;

use App\Services\Identity\VerificationResult;

/**
 * Pluggable Ghana Card verification provider. Concrete implementations
 * talk to the NIA's official Identity Verification System, to a third-party
 * KYC aggregator (uqudo, Smile ID, Youverify), or accept a manual upload
 * with senior-officer approval as a controlled fallback for pilot.
 *
 * The active provider is selected via `config/identity.php`.
 */
interface IdentityVerificationProvider
{
    /**
     * Provider slug — must match an `IdentityProviderKind` enum value.
     */
    public function kind(): string;

    /**
     * Verify a Ghana Card number against the provider.
     *
     * @param array{full_name?: string, date_of_birth?: string, phone?: string} $personal
     */
    public function verify(string $ghanaCardNumber, array $personal = []): VerificationResult;
}
