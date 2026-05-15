<?php

namespace App\Services\Identity\Providers;

use App\Enums\IdentityProviderKind;
use App\Services\Identity\Contracts\IdentityVerificationProvider;
use App\Services\Identity\VerificationResult;

/**
 * Pilot-grade fallback. Confirms only that the supplied Ghana Card number
 * matches the regex `GHA-NNNNNNNNN-N` and assumes the back-office reviewer
 * will manually approve the uploaded scan via the UI flow. Returns success
 * pending manual sign-off.
 */
class ManualUploadProvider implements IdentityVerificationProvider
{
    private const PATTERN = '/^GHA-\d{9}-\d$/';

    public function kind(): string
    {
        return IdentityProviderKind::ManualUpload->value;
    }

    public function verify(string $ghanaCardNumber, array $personal = []): VerificationResult
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($ghanaCardNumber)));

        if (! preg_match(self::PATTERN, $normalized)) {
            return VerificationResult::failed(
                "Ghana Card number does not match the expected format GHA-NNNNNNNNN-N.",
                ['normalized' => $normalized],
            );
        }

        // Manual flow always requires senior-officer confirmation post-upload.
        return VerificationResult::ok(
            raw: [
                'normalized' => $normalized,
                'mode'       => 'manual',
                'note'       => 'Pending senior-officer manual approval.',
            ],
            expiresAt: new \DateTimeImmutable('+12 months'),
        );
    }
}
