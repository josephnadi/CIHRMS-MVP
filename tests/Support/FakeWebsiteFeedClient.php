<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\Website\WebsiteFeedClient;

class FakeWebsiteFeedClient implements WebsiteFeedClient
{
    /** @param array<int,array> $memberPages @param array<int,array> $collectionPages */
    public function __construct(
        public array $members = [],
        public array $collections = [],
    ) {}

    public function members(?string $since, ?int $cursor, int $limit = 200): array
    {
        return ['data' => $this->members, 'next_cursor' => null];
    }

    public function collections(?string $since, ?int $cursor, int $limit = 200): array
    {
        return ['data' => $this->collections, 'next_cursor' => null];
    }
}
