<?php

namespace App\Integrations\Drivers\DocuSign;

use App\Integrations\Contracts\ESignProvider;
use App\Integrations\DTO\EnvelopeDto;
use App\Integrations\Drivers\AbstractDriver;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * DocuSign eSignature REST v2.1 — envelope create, status, void, signed PDF URL.
 *
 * Note: DocuSign's API base URL is account-specific (returned by the userinfo endpoint
 * after OAuth). We persist it under config['account_base_uri'] once the connection is made.
 */
class DocuSignDriver extends AbstractDriver implements ESignProvider
{
    public function __construct(
        protected TokenStore $tokens,
        protected OAuthFlow $oauth,
    ) {}

    public function provider(): string
    {
        return 'docusign';
    }

    public function capability(): string
    {
        return 'esign';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'DocuSign';
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
            return $this->http()->get('accounts/'.$this->accountId())->ok();
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
            $payload = [
                'emailSubject' => $envelope->subject,
                'emailBlurb'   => $envelope->message,
                'status'       => 'sent', // immediate send (use 'created' for draft)
                'documents'    => [[
                    'documentBase64' => $envelope->documentBase64,
                    'name'           => $envelope->documentName,
                    'fileExtension'  => pathinfo($envelope->documentName, PATHINFO_EXTENSION) ?: 'pdf',
                    'documentId'     => '1',
                ]],
                'recipients' => [
                    'signers' => array_values(array_map(fn ($i, $r) => [
                        'email'        => $r['email'],
                        'name'         => $r['name'] ?? $r['email'],
                        'roleName'     => $r['role'] ?? 'Signer',
                        'recipientId'  => (string) ($i + 1),
                        'routingOrder' => (string) ($i + 1),
                        'tabs'         => [
                            'signHereTabs' => [[
                                'documentId' => '1',
                                'pageNumber' => '1',
                                'xPosition'  => '100',
                                'yPosition'  => '700',
                            ]],
                        ],
                    ], array_keys($envelope->recipients), $envelope->recipients)),
                ],
                'eventNotification' => $envelope->callbackUrl ? [
                    'url'                       => $envelope->callbackUrl,
                    'requireAcknowledgment'     => 'true',
                    'useSoapInterface'          => 'false',
                    'envelopeEvents'            => array_map(fn ($e) => ['envelopeEventStatusCode' => $e], [
                        'sent', 'delivered', 'completed', 'declined', 'voided',
                    ]),
                ] : null,
            ];

            $response = $this->http()->post('accounts/'.$this->accountId().'/envelopes', array_filter($payload))->throw();
            $id = (string) $response->json('envelopeId');
            if ($id === '') {
                throw new RuntimeException('DocuSign returned no envelopeId: '.$response->body());
            }
            return $id;
        });
    }

    public function status(string $envelopeId): string
    {
        return $this->track('esign.status', ['id' => $envelopeId], function () use ($envelopeId) {
            $response = $this->http()->get("accounts/{$this->accountId()}/envelopes/{$envelopeId}")->throw();
            return (string) ($response->json('status') ?? 'unknown');
        });
    }

    public function signedDocumentUrl(string $envelopeId): ?string
    {
        return $this->track('esign.signed_url', ['id' => $envelopeId], function () use ($envelopeId) {
            // DocuSign serves the combined PDF at this path; we return it for the caller to stream.
            return rtrim($this->baseUri(), '/')."/restapi/v2.1/accounts/{$this->accountId()}/envelopes/{$envelopeId}/documents/combined";
        });
    }

    public function void(string $envelopeId, ?string $reason = null): bool
    {
        return $this->track('esign.void', ['id' => $envelopeId, 'reason' => $reason], function () use ($envelopeId, $reason) {
            $this->http()->put("accounts/{$this->accountId()}/envelopes/{$envelopeId}", [
                'status'       => 'voided',
                'voidedReason' => $reason ?? 'Revoked by HR',
            ])->throw();
            return true;
        });
    }

    protected function baseUri(): string
    {
        // Set on the integration config after OAuth (DocuSign's userinfo response gives `accounts[].base_uri`).
        return (string) (data_get($this->integration?->config, 'account_base_uri')
            ?: 'https://demo.docusign.net'); // sensible dev default
    }

    protected function accountId(): string
    {
        return (string) (data_get($this->integration?->config, 'account_id')
            ?? throw new RuntimeException('DocuSign account_id not stored on integration row.'));
    }

    protected function http(): PendingRequest
    {
        $integration = $this->integration ?? throw new RuntimeException('DocuSign driver not bound to integration row.');
        $token = $this->tokens->active($integration);
        if (! $token) {
            throw new RuntimeException('DocuSign not connected — no token on file.');
        }
        if ($token->expiresWithin(2)) {
            $this->oauth->refresh($integration);
            $token = $this->tokens->active($integration);
        }

        $base = rtrim($this->baseUri(), '/').'/restapi/v2.1/';

        return Http::baseUrl($base)
            ->withToken($token->access_token)
            ->acceptJson()
            ->timeout(30);
    }
}
