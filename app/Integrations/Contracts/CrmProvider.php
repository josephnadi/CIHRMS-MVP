<?php

namespace App\Integrations\Contracts;

use App\Integrations\DTO\ContactDto;

interface CrmProvider extends IntegrationProvider
{
    public function syncContact(ContactDto $contact): string;

    public function fetchContact(string $externalId): ?ContactDto;

    public function deleteContact(string $externalId): bool;

    /** @return array<int, ContactDto> */
    public function searchContacts(string $query, int $limit = 25): array;
}
