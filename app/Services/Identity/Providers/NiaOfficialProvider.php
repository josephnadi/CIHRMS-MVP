<?php

namespace App\Services\Identity\Providers;

use App\Enums\IdentityProviderKind;
use App\Services\Identity\Contracts\IdentityVerificationProvider;
use App\Services\Identity\VerificationResult;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for the National Identification Authority's official Identity
 * Verification System (IVS). Requires institutional onboarding and a
 * shared-secret API token (issued by NIA after MoU).
 *
 * Endpoint: configurable via `config/identity.php` (`providers.nia_official`).
 * Auth: Bearer token + signed request body.
 */
class NiaOfficialProvider implements IdentityVerificationProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int    $timeoutSeconds = 8,
    ) {}

    public function kind(): string
    {
        return IdentityProviderKind::NiaOfficial->value;
    }

    public function verify(string $ghanaCardNumber, array $personal = []): VerificationResult
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($ghanaCardNumber)));

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post("{$this->baseUrl}/verify", [
                    'pin'           => $normalized,
                    'full_name'     => $personal['full_name']     ?? null,
                    'date_of_birth' => $personal['date_of_birth'] ?? null,
                ]);
        } catch (\Throwable $e) {
            return VerificationResult::failed("NIA transport error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return VerificationResult::failed(
                "NIA returned HTTP {$response->status()}",
                ['body' => $response->json() ?? $response->body()],
            );
        }

        $body  = $response->json() ?? [];
        $match = (bool) ($body['matched'] ?? false);

        if (! $match) {
            return VerificationResult::failed(
                $body['reason'] ?? 'NIA: no match for the supplied Ghana Card details.',
                $body,
            );
        }

        // NIA does not expire verifications — we re-verify locally every 12 months.
        return VerificationResult::ok(
            raw: $body,
            expiresAt: new \DateTimeImmutable('+12 months'),
        );
    }
}
