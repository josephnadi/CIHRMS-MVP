<?php

namespace App\Services\Identity\Providers;

use App\Enums\IdentityProviderKind;
use App\Services\Identity\Contracts\IdentityVerificationProvider;
use App\Services\Identity\VerificationResult;
use Illuminate\Support\Facades\Http;

/**
 * Generic third-party KYC aggregator adapter. Compatible with uqudo,
 * Smile ID, Youverify, or any vendor that re-publishes the NIA database.
 *
 * Used during pilot phases before NIA institutional onboarding completes.
 */
class ThirdPartyKycProvider implements IdentityVerificationProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $vendor = 'unspecified',
        private readonly int    $timeoutSeconds = 10,
    ) {}

    public function kind(): string
    {
        return IdentityProviderKind::ThirdPartyKyc->value;
    }

    public function verify(string $ghanaCardNumber, array $personal = []): VerificationResult
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($ghanaCardNumber)));

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-API-KEY' => $this->apiKey])
                ->acceptJson()
                ->post("{$this->baseUrl}/ghana/card", [
                    'card_number' => $normalized,
                    'first_name'  => $personal['first_name'] ?? null,
                    'last_name'   => $personal['last_name']  ?? null,
                ]);
        } catch (\Throwable $e) {
            return VerificationResult::failed("{$this->vendor} transport error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return VerificationResult::failed("{$this->vendor} returned HTTP {$response->status()}", [
                'body'   => $response->json() ?? $response->body(),
                'vendor' => $this->vendor,
            ]);
        }

        $body = $response->json() ?? [];
        if (! ($body['data']['verified'] ?? $body['verified'] ?? false)) {
            return VerificationResult::failed(
                $body['message'] ?? "{$this->vendor}: not verified.",
                $body + ['vendor' => $this->vendor],
            );
        }

        return VerificationResult::ok(
            raw: $body + ['vendor' => $this->vendor],
            expiresAt: new \DateTimeImmutable('+12 months'),
        );
    }
}
