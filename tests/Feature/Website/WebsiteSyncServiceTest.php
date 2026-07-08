<?php
declare(strict_types=1);

use App\Models\ExternalCollection;
use App\Models\Member;
use App\Models\User;
use App\Services\Website\WebsiteFeedClient;
use App\Services\Website\WebsiteSyncService;
use Tests\Support\FakeWebsiteFeedClient;

beforeEach(function () {
    // CihrmChartOfAccountsSeeder reuses the structural parents/accounts that
    // ChartOfAccountsSeeder creates (see its docblock), and PostingAccountSeeder
    // resolves structural codes like 1200/2210/5100 — both need it seeded first.
    // GlAccountBalanceSeeder runs last: FeeGlMappingSeeder creates new GL
    // accounts (1131, 4700-4760), and every account needs a balance row before
    // JournalPostingService can post to it.
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // posting actor fallback: sync
    // runs unauthenticated (console/scheduled context), so PostingActorResolver
    // needs a super_admin to stamp created_by on the JournalEntry it creates.
});

it('mirrors members and posts collections, returning a report', function () {
    $fake = new FakeWebsiteFeedClient(
        members: [[
            'external_user_id' => 4821, 'member_number' => 'M-1', 'student_number' => null,
            'user_type' => 'member', 'class' => 'full', 'status' => 'active',
            'name' => 'Ama', 'email' => 'a@x.com', 'phone' => '024',
        ]],
        collections: [
            ['source' => 'member_fee_payment', 'source_id' => 1, 'external_ref' => 'TXN-1', 'external_user_id' => 4821,
             'payer_name' => 'Ama', 'fee_code' => 'member.subscription', 'amount' => 350, 'currency' => 'GHS',
             'paid_at' => '2026-07-05T10:00:00Z', 'method' => 'momo', 'gateway_ref' => 'h1', 'meta' => []],
            ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PR-2', 'external_user_id' => null,
             'payer_name' => 'Y', 'fee_code' => 'mystery', 'amount' => 50, 'currency' => 'GHS',
             'paid_at' => '2026-07-05T11:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
        ],
    );
    app()->instance(WebsiteFeedClient::class, $fake);

    $report = app(WebsiteSyncService::class)->sync();

    expect($report['members'])->toBe(1)
        ->and($report['posted'])->toBe(1)
        ->and($report['unmapped'])->toBe(1)
        ->and(Member::where('external_user_id', 4821)->exists())->toBeTrue()
        ->and(ExternalCollection::where('status', 'posted')->count())->toBe(1);
});

it('follows a paginated collections feed across multiple pages', function () {
    $client = new class implements WebsiteFeedClient {
        public function members(?string $since, ?int $cursor, int $limit = 200): array
        {
            return ['data' => [], 'next_cursor' => null];
        }

        public function collections(?string $since, ?int $cursor, int $limit = 200): array
        {
            if ($cursor === null) {
                return [
                    'data' => [
                        ['source' => 'payment_record', 'source_id' => 1, 'external_ref' => 'PAGE-1', 'external_user_id' => null,
                         'payer_name' => 'A', 'fee_code' => 'exam', 'amount' => 100, 'currency' => 'GHS',
                         'paid_at' => '2026-07-05T09:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
                    ],
                    'next_cursor' => 2,
                ];
            }

            return [
                'data' => [
                    ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PAGE-2', 'external_user_id' => null,
                     'payer_name' => 'B', 'fee_code' => 'exam', 'amount' => 150, 'currency' => 'GHS',
                     'paid_at' => '2026-07-05T09:30:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
                ],
                'next_cursor' => null,
            ];
        }
    };
    app()->instance(WebsiteFeedClient::class, $client);

    $report = app(WebsiteSyncService::class)->sync();

    expect($report['pulled'])->toBe(2)
        ->and($report['posted'])->toBe(2)
        ->and(ExternalCollection::where('external_ref', 'PAGE-1')->where('status', 'posted')->exists())->toBeTrue()
        ->and(ExternalCollection::where('external_ref', 'PAGE-2')->where('status', 'posted')->exists())->toBeTrue();
});

it('breaks out of pagination when the feed returns a non-advancing cursor', function () {
    $client = new class implements WebsiteFeedClient {
        public function members(?string $since, ?int $cursor, int $limit = 200): array
        {
            return ['data' => [], 'next_cursor' => null];
        }

        public function collections(?string $since, ?int $cursor, int $limit = 200): array
        {
            // Buggy feed: always returns the same non-null cursor, never
            // progressing. The stuck-cursor guard must break the loop.
            return [
                'data' => [
                    ['source' => 'payment_record', 'source_id' => 9, 'external_ref' => 'STUCK-'.($cursor ?? 'first'), 'external_user_id' => null,
                     'payer_name' => 'C', 'fee_code' => 'exam', 'amount' => 75, 'currency' => 'GHS',
                     'paid_at' => '2026-07-05T09:45:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
                ],
                'next_cursor' => 5,
            ];
        }
    };
    app()->instance(WebsiteFeedClient::class, $client);

    $report = app(WebsiteSyncService::class)->sync();

    // First iteration: cursor null -> requested null, page returns next_cursor 5 (advances, null !== 5).
    // Second iteration: cursor 5 -> requested 5, page returns next_cursor 5 again (stuck) -> break.
    expect($report['pulled'])->toBe(2);
});

it('does not double-post on a second sync', function () {
    $fake = new FakeWebsiteFeedClient(collections: [
        ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PR-2', 'external_user_id' => null,
         'payer_name' => 'Y', 'fee_code' => 'exam', 'amount' => 200, 'currency' => 'GHS',
         'paid_at' => '2026-07-05T11:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
    ]);
    app()->instance(WebsiteFeedClient::class, $fake);

    app(WebsiteSyncService::class)->sync();
    app(WebsiteSyncService::class)->sync();

    expect(ExternalCollection::where('external_ref', 'PR-2')->count())->toBe(1);
});
