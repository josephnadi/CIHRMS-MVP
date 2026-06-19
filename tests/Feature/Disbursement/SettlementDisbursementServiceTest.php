<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\JournalEntry;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/../Offboarding/SettlementAccrualTest.php'; // seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
    OrgBankAccount::factory()->create([
        'purpose' => 'payroll', 'is_active' => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
});

/** A provider stub that reports Settled immediately. */
function settledProvider(string $channel): DisbursementProvider
{
    return new class($channel) implements DisbursementProvider {
        public function __construct(private string $ch) {}
        public function channel(): string { return $this->ch; }
        public function send(Disbursement $d): DisbursementResult {
            return new DisbursementResult(DisbursementStatus::Settled, 'PROV-REF-1', null, ['ok' => true]);
        }
        public function refreshStatus(Disbursement $d): DisbursementResult {
            return new DisbursementResult(DisbursementStatus::Settled, 'PROV-REF-1', null, ['ok' => true]);
        }
    };
}

it('creates a pending settlement disbursement for the paid net', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]); // net 6200
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    $svc = app(BatchDisbursementService::class);
    $d = $svc->createForSettlement($settlement->fresh());

    expect($d)->not->toBeNull()
        ->and($d->final_settlement_id)->toBe($settlement->id)
        ->and((float) $d->gross_amount)->toEqualWithDelta(6200.0, 0.01)
        ->and($d->status)->toBe(DisbursementStatus::Pending)
        ->and($d->payroll_run_id)->toBeNull();
});

it('dispatching a settlement disbursement posts NO GL (additive tracking only)', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    // Build the service with a stub GhIPSS provider that settles immediately.
    $svc = new BatchDisbursementService(
        ['ghipss_ach' => settledProvider('ghipss_ach')],
        app(\App\Services\Finance\PostingService::class),
    );
    $d = $svc->createForSettlement($settlement->fresh());

    $glBefore = JournalEntry::where('source_type', 'disbursement')->count();
    $svc->dispatchOne($d);

    expect($d->fresh()->status)->toBe(DisbursementStatus::Settled)
        ->and($d->fresh()->provider_reference)->toBe('PROV-REF-1')
        // NO disbursement-source GL entry was posted for this settlement disbursement.
        ->and(JournalEntry::where('source_type', 'disbursement')->count())->toBe($glBefore);
});
