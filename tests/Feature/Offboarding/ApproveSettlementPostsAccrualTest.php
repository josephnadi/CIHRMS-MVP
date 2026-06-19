<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // reuse seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // actor-less posting fallback
});

it('posts the accrual JE when a settlement is approved', function () {
    // seedSettlementWithLoan creates an already-"approved" row; recreate it as Calculated so approveSettlement runs.
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    $settlement->update(['status' => 'calculated']);

    $approver = User::factory()->create(['role' => 'super_admin']);
    app(OffboardingService::class)->approveSettlement($settlement->fresh(), $approver);

    $je = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
        ->where('source_id', $settlement->id)->where('source_purpose', 'accrual')->first();

    expect($je)->not->toBeNull()
        ->and(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('5130'))->toEqualWithDelta(10000.0, 0.01)
        ->and($settlement->fresh()->status->value)->toBe('approved');
});
