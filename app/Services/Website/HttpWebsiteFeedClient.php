<?php
declare(strict_types=1);

namespace App\Services\Website;

use Illuminate\Support\Facades\Http;

class HttpWebsiteFeedClient implements WebsiteFeedClient
{
    public function members(?string $since, ?int $cursor, int $limit = 200): array
    {
        return $this->get('members', $since, $cursor, $limit);
    }

    public function collections(?string $since, ?int $cursor, int $limit = 200): array
    {
        return $this->get('collections', $since, $cursor, $limit);
    }

    private function get(string $path, ?string $since, ?int $cursor, int $limit): array
    {
        $base  = rtrim((string) config('services.cihrm_website.url'), '/');
        $token = (string) config('services.cihrm_website.token');

        $resp = Http::withToken($token)->acceptJson()->timeout(30)
            ->get("{$base}/api/finance-sync/{$path}", array_filter([
                'since' => $since, 'cursor' => $cursor, 'limit' => $limit,
            ], fn ($v) => $v !== null))
            ->throw()->json();

        return ['data' => $resp['data'] ?? [], 'next_cursor' => $resp['next_cursor'] ?? null];
    }
}
