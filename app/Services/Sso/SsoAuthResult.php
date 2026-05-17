<?php

namespace App\Services\Sso;

use App\Enums\SsoLoginOutcome;

final class SsoAuthResult
{
    public function __construct(
        public readonly bool $success,
        public readonly SsoLoginOutcome $outcome,
        public readonly ?string $subject = null,        // IdP's stable `sub` claim
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly array $claims = [],
        public readonly ?string $error = null,
    ) {}

    public static function ok(string $subject, ?string $email, ?string $name, array $claims): self
    {
        return new self(true, SsoLoginOutcome::Success, $subject, $email, $name, $claims);
    }

    public static function failure(SsoLoginOutcome $outcome, string $error, array $claims = []): self
    {
        return new self(false, $outcome, null, null, null, $claims, $error);
    }
}
