<?php
declare(strict_types=1);

namespace App\Services\Website;

interface WebsiteFeedClient
{
    /** @return array{data: array<int, array>, next_cursor: ?int} */
    public function members(?string $since, ?int $cursor, int $limit = 200): array;

    /** @return array{data: array<int, array>, next_cursor: ?int} */
    public function collections(?string $since, ?int $cursor, int $limit = 200): array;
}
