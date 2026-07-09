<?php
declare(strict_types=1);

use App\Models\User;
use App\Services\Website\WebsiteFeedClient;
use Tests\Support\FakeWebsiteFeedClient;

beforeEach(function () {
    // Same seeding order as WebsiteSyncServiceTest: ChartOfAccountsSeeder must
    // run before CihrmChartOfAccountsSeeder (which reuses its structural
    // parents/accounts), PostingAccountSeeder resolves structural codes like
    // 1200/2210/5100 so it also needs the chart seeded first, and
    // GlAccountBalanceSeeder runs last so every account (including the
    // 1131/4700-4760 ones FeeGlMappingSeeder creates) has a balance row
    // before JournalPostingService can post to it.
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // posting actor fallback: the
    // command runs unauthenticated (console/scheduled context), so
    // PostingActorResolver needs a super_admin to stamp created_by on the
    // JournalEntry it creates.
});

it('runs the sync and reports counts', function () {
    app()->instance(WebsiteFeedClient::class, new FakeWebsiteFeedClient(collections: [
        ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PR-2', 'external_user_id' => null,
         'payer_name' => 'Y', 'fee_code' => 'exam', 'amount' => 200, 'currency' => 'GHS',
         'paid_at' => '2026-07-05T11:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
    ]));

    $this->artisan('sync:website-collections')
        ->assertExitCode(0)
        ->expectsOutputToContain('posted: 1');
});
