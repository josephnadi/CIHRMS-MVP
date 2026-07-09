<?php
declare(strict_types=1);

use App\Services\Website\HttpWebsiteFeedClient;
use Illuminate\Support\Facades\Http;

it('calls the collections endpoint with token + params and parses the page', function () {
    config()->set('services.cihrm_website.url', 'https://site.test');
    config()->set('services.cihrm_website.token', 'secret-token');

    Http::fake(['site.test/api/finance-sync/collections*' => Http::response([
        'data' => [['source' => 'exam', 'external_ref' => 'PR-1']],
        'next_cursor' => 42,
    ])]);

    $page = app(HttpWebsiteFeedClient::class)->collections(since: '2026-07-01T00:00:00Z', cursor: null, limit: 200);

    expect($page['data'])->toHaveCount(1)->and($page['next_cursor'])->toBe(42);
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/finance-sync/collections')
        && $req->hasHeader('Authorization', 'Bearer secret-token')
        && str_contains($req->url(), 'since=2026-07-01'));
});
