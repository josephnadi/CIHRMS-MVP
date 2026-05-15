<?php

namespace App\Integrations\Drivers\Zoho;

use App\Integrations\Contracts\CrmProvider;
use App\Integrations\DTO\ContactDto;
use App\Integrations\Drivers\AbstractDriver;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Zoho CRM v8 driver — Contacts module sync (upsert), fetch, search, delete.
 *
 * Field mapping (CIHRMS ContactDto → Zoho Contact):
 *   firstName  → First_Name (required)
 *   lastName   → Last_Name  (required)
 *   email      → Email
 *   phone      → Phone
 *   jobTitle   → Title
 *   company    → Account_Name (only set when present; Zoho expects an account name string)
 *   externalId → record id
 *   extra.*    → flat-mapped under the same keys (skipped if Zoho rejects unknown fields)
 */
class ZohoCrmDriver extends AbstractDriver implements CrmProvider
{
    public function __construct(
        protected TokenStore $tokens,
        protected OAuthFlow $oauth,
    ) {}

    public function provider(): string
    {
        return 'zoho_crm';
    }

    public function capability(): string
    {
        return 'crm';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'Zoho CRM';
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
            return $this->http()->get('users?type=CurrentUser')->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    public function syncContact(ContactDto $contact): string
    {
        return $this->track('crm.upsert_contact', $contact->toArray(), function () use ($contact) {
            $payload = ['data' => [$this->toZoho($contact)], 'duplicate_check_fields' => ['Email']];
            $response = $this->http()->post('Contacts/upsert', $payload)->throw();

            $id = (string) data_get($response->json(), 'data.0.details.id');
            if ($id === '') {
                throw new RuntimeException('Zoho upsert returned no record id: '.$response->body());
            }
            return $id;
        });
    }

    public function fetchContact(string $externalId): ?ContactDto
    {
        return $this->track('crm.fetch_contact', ['id' => $externalId], function () use ($externalId) {
            $response = $this->http()->get("Contacts/{$externalId}");
            if ($response->status() === 204 || $response->notFound()) {
                return null;
            }
            $response->throw();
            $row = data_get($response->json(), 'data.0');
            return $row ? $this->fromZoho($row) : null;
        });
    }

    public function deleteContact(string $externalId): bool
    {
        return $this->track('crm.delete_contact', ['id' => $externalId], function () use ($externalId) {
            $this->http()->delete("Contacts/{$externalId}")->throw();
            return true;
        });
    }

    /** @return array<int, ContactDto> */
    public function searchContacts(string $query, int $limit = 25): array
    {
        return $this->track('crm.search_contacts', ['q' => $query, 'limit' => $limit], function () use ($query, $limit) {
            $response = $this->http()->get('Contacts/search', [
                'word'     => $query,
                'per_page' => max(1, min($limit, 200)),
            ]);
            if ($response->status() === 204) {
                return [];
            }
            $response->throw();
            return array_map(fn ($r) => $this->fromZoho($r), (array) data_get($response->json(), 'data', []));
        });
    }

    /** Authenticated HTTP client against the configured Zoho CRM API base. */
    protected function http(): PendingRequest
    {
        $integration = $this->integration ?? throw new RuntimeException('Zoho CRM driver not bound to an integration row.');
        $token = $this->tokens->active($integration);
        if (! $token) {
            throw new RuntimeException('Zoho CRM not connected — no token on file.');
        }
        if ($token->expiresWithin(2)) {
            $this->oauth->refresh($integration);
            $token = $this->tokens->active($integration);
        }

        return Http::baseUrl(rtrim($this->driverConfig()['api_base'], '/').'/')
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$token->access_token])
            ->acceptJson()
            ->timeout(30);
    }

    /** Map our ContactDto into a Zoho Contacts payload. */
    protected function toZoho(ContactDto $c): array
    {
        $payload = array_filter([
            'First_Name'   => $c->firstName,
            'Last_Name'    => $c->lastName ?? $c->firstName, // Zoho requires Last_Name
            'Email'        => $c->email,
            'Phone'        => $c->phone,
            'Title'        => $c->jobTitle,
            'Account_Name' => $c->company,
        ], fn ($v) => $v !== null && $v !== '');

        if ($c->externalId) {
            $payload['id'] = $c->externalId;
        }

        // Field-mapping overrides per environment so admins can rename without code changes.
        $extraMap = (array) ($this->driverConfig()['contact_field_map'] ?? []);
        foreach ($c->extra as $key => $value) {
            $payload[$extraMap[$key] ?? $key] = $value;
        }

        return $payload;
    }

    /** Map a Zoho Contacts row back to our ContactDto. */
    protected function fromZoho(array $row): ContactDto
    {
        return new ContactDto(
            externalId: (string) ($row['id'] ?? '') ?: null,
            firstName:  (string) ($row['First_Name'] ?? ''),
            lastName:   $row['Last_Name'] ?? null,
            email:      $row['Email'] ?? null,
            phone:      $row['Phone'] ?? null,
            jobTitle:   $row['Title'] ?? null,
            company:    is_array($row['Account_Name'] ?? null) ? (string) data_get($row, 'Account_Name.name') : ($row['Account_Name'] ?? null),
            extra:      collect($row)->except(['id', 'First_Name', 'Last_Name', 'Email', 'Phone', 'Title', 'Account_Name'])->all(),
        );
    }
}
