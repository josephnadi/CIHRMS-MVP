<?php

namespace App\Services\Sso;

use App\Enums\SsoLoginOutcome;

/**
 * Normalised SSO callback result. Both OIDC and SAML adapters return this
 * shape so the SsoOrchestrator can JIT-provision without protocol awareness.
 */
final class SsoCallbackResult
{
    public function __construct(
        public readonly bool $success,
        public readonly SsoLoginOutcome $outcome,
        public readonly ?string $subjectId = null,    // IdP-stable identifier
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly array $claims = [],
        public readonly ?string $error = null,
    ) {}

    public static function ok(string $subjectId, ?string $email, ?string $name, array $claims): self
    {
        return new self(true, SsoLoginOutcome::Success, $subjectId, $email, $name, $claims);
    }

    public static function fail(SsoLoginOutcome $outcome, string $error, array $claims = []): self
    {
        return new self(false, $outcome, null, null, null, $claims, $error);
    }
}
