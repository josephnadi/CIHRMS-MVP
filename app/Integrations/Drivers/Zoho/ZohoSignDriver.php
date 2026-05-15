<?php

namespace App\Integrations\Drivers\Zoho;

use App\Integrations\Contracts\ESignProvider;
use App\Integrations\DTO\EnvelopeDto;
use App\Integrations\Drivers\AbstractDriver;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Zoho Sign — request creation, status polling, void, signed-doc retrieval.
 *
 * Uses the multipart `requests` endpoint (POST /requests) which lets us send
 * the document + JSON metadata in a single call.
 */
class ZohoSignDriver extends AbstractDriver implements ESignProvider
{
    public function __construct(
        protected TokenStore $tokens,
        protected OAuthFlow $oauth,
    ) {}

    public function provider(): string
    {
        return 'zoho_sign';
    }

    public function capability(): string
    {
        return 'esign';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'Zoho Sign';
    }

    protected function requiredConfigKeys(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function ping(): bool
    {
        if (! $this->isConfigured() || ! $this->integration?->is_enabled) {
            return false;
        }
        try {
            return $this->http()->get('users')->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    public function createEnvelope(EnvelopeDto $envelope): string
    {
        return $this->track('esign.create_envelope', [
            'subject'    => $envelope->subject,
            'recipients' => count($envelope->recipients),
        ], function () use ($envelope) {
            $metadata = [
                'requests' => [
                    'request_name' => $envelope->subject,
                    'notes'        => $envelope->message,
                    'expiration_days' => 14,
                    'is_sequential'   => false,
                    'actions'         => array_values(array_map(fn ($i, $r) => [
                        'action_type'      => 'SIGN',
                        'recipient_name'   => $r['name']  ?? $r['email'],
                        'recipient_email'  => $r['email'],
                        'role'             => $r['role']  ?? 'Signer',
                        'verify_recipient' => false,
                        'signing_order'    => $i + 1,
                    ], array_keys($envelope->recipients), $envelope->recipients)),
                ],
            ];

            $response = $this->http()
                ->attach('file', base64_decode($envelope->documentBase64), $envelope->documentName)
                ->asMultipart()
                ->post('requests', ['data' => json_encode($metadata)])
                ->throw();

            $id = (string) data_get($response->json(), 'requests.request_id');
            if ($id === '') {
                throw new RuntimeException('Zoho Sign returned no request_id: '.$response->body());
            }

            // Send for signature immediately after upload.
            $this->http()->post("requests/{$id}/submit")->throw();

            return $id;
        });
    }

    public function status(string $envelopeId): string
    {
        return $this->track('esign.status', ['id' => $envelopeId], function () use ($envelopeId) {
            $response = $this->http()->get("requests/{$envelopeId}")->throw();
            return (string) data_get($response->json(), 'requests.request_status', 'unknown');
        });
    }

    public function signedDocumentUrl(string $envelopeId): ?string
    {
        return $this->track('esign.signed_url', ['id' => $envelopeId], function () use ($envelopeId) {
            // Zoho signed PDFs are delivered through GET /requests/{id}/pdf — return the absolute URL
            // so the caller can redirect or stream.
            return rtrim($this->driverConfig()['api_base'] ?? 'https://sign.zoho.com/api/v1', '/')."/requests/{$envelopeId}/pdf";
        });
    }

    public function void(string $envelopeId, ?string $reason = null): bool
    {
        return $this->track('esign.void', ['id' => $envelopeId, 'reason' => $reason], function () use ($envelopeId, $reason) {
            $this->http()->post("requests/{$envelopeId}/recall", [
                'data' => json_encode(['reason' => $reason ?? 'Revoked by HR']),
            ])->throw();
            return true;
        });
    }

    protected function http(): PendingRequest
    {
        $integration = $this->integration ?? throw new RuntimeException('Zoho Sign driver not bound to integration row.');
        $token = $this->tokens->active($integration);
        if (! $token) {
            throw new RuntimeException('Zoho Sign not connected — no token on file.');
        }
        if ($token->expiresWithin(2)) {
            $this->oauth->refresh($integration);
            $token = $this->tokens->active($integration);
        }

        $base = rtrim($this->driverConfig()['api_base'] ?? 'https://sign.zoho.com/api/v1', '/').'/';

        return Http::baseUrl($base)
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$token->access_token])
            ->acceptJson()
            ->timeout(30);
    }
}
