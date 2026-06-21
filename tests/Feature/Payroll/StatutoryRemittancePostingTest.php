<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use App\Services\Payroll\RemittanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // active Payroll-purpose account → GL 1110

    $this->svc = app(RemittanceService::class);
    $this->by  = User::factory()->create(['role' => 'finance_officer']);
    $this->run = PayrollRun::create([
        'reference' => 'PR-2026-05-ORG', 'period_year' => 2026, 'period_month' => 5,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'status' => 'approved',
    ]);
});

function makeStatReturn(PayrollRun $run, string $kind, float $amount): StatutoryReturn
{
    return StatutoryReturn::create([
        'payroll_run_id' => $run->id, 'kind' => $kind, 'file_path' => "returns/{$kind}.csv",
        'total_amount' => $amount, 'record_count' => 3, 'generated_at' => now(),
    ]);
}

it('posts a balanced remittance JE that clears the liability when a PAYE return is filed', function () {
    $return = makeStatReturn($this->run, 'paye', 1200);

    $this->svc->markSubmitted($return, $this->by, 'GRA-2026-05');

    $je = JournalEntry::where('source_type', JournalSourceType::StatutoryRemittance->value)
        ->where('source_id', $return->id)
        ->where('source_purpose', 'remittance')
        ->firstOrFail();

    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->isBalanced())->toBeTrue();

    $payePayable = GlAccount::where('code', '2210')->firstOrFail(); // payroll.paye_payable
    $bank        = GlAccount::where('code', '1120')->firstOrFail(); // seeded statutory-escrow bank
    $dr = $je->lines->firstWhere('gl_account_id', $payePayable->id);
    $cr = $je->lines->firstWhere('gl_account_id', $bank->id);

    expect((float) $dr->debit_amount)->toBe(1200.0)
        ->and((float) $cr->credit_amount)->toBe(1200.0);
});

it('maps each statutory kind to its own liability account', function () {
    $cases = [
        'ssnit_tier1'   => '2200',
        'tier2_trustee' => '2220',
        'tier3'         => '2230',
    ];

    foreach ($cases as $kind => $code) {
        $return = makeStatReturn($this->run, $kind, 500);
        $this->svc->markSubmitted($return, $this->by, "REF-{$kind}");

        $je = JournalEntry::where('source_type', JournalSourceType::StatutoryRemittance->value)
            ->where('source_id', $return->id)->firstOrFail();
        $liability = GlAccount::where('code', $code)->firstOrFail();

        expect($je->lines->firstWhere('gl_account_id', $liability->id)->debit_amount)->toEqual(500);
    }
});

it('does not post a JE for informational returns (NHIA, bank file)', function () {
    foreach (['nhia_split', 'bank_file'] as $kind) {
        $return = makeStatReturn($this->run, $kind, 999);
        $this->svc->markSubmitted($return, $this->by, "REF-{$kind}");

        expect(JournalEntry::where('source_type', JournalSourceType::StatutoryRemittance->value)
            ->where('source_id', $return->id)->exists())->toBeFalse();

        // The filing itself is still recorded.
        expect($return->fresh()->submitted_at)->not->toBeNull();
    }
});

it('does not post a JE for a zero-value return', function () {
    $return = makeStatReturn($this->run, 'paye', 0);
    $this->svc->markSubmitted($return, $this->by, 'ZERO');

    expect(JournalEntry::where('source_type', JournalSourceType::StatutoryRemittance->value)
        ->where('source_id', $return->id)->exists())->toBeFalse();
    expect($return->fresh()->submitted_at)->not->toBeNull();
});

it('falls back to the payroll bank when no statutory-escrow account is active', function () {
    // Disable the seeded escrow account (1120); only payroll (1110) remains.
    OrgBankAccount::where('purpose', OrgBankAccountPurpose::StatutoryEscrow->value)
        ->update(['is_active' => false]);

    $payrollBank = GlAccount::where('code', '1110')->firstOrFail();

    $return = makeStatReturn($this->run, 'paye', 300);
    $this->svc->markSubmitted($return, $this->by, 'PAYROLLBANK');

    $je = JournalEntry::where('source_type', JournalSourceType::StatutoryRemittance->value)
        ->where('source_id', $return->id)->firstOrFail();

    expect((float) $je->lines->firstWhere('gl_account_id', $payrollBank->id)->credit_amount)->toBe(300.0);
});

it('fails closed — a posting failure rolls the filing back', function () {
    OrgBankAccount::query()->update(['is_active' => false]); // no bank → posting throws

    $return = makeStatReturn($this->run, 'paye', 750);

    expect(fn () => $this->svc->markSubmitted($return, $this->by, 'NOBANK'))
        ->toThrow(DomainException::class);

    // The whole transaction rolled back: the return is NOT marked filed.
    expect($return->fresh()->submitted_at)->toBeNull();
    expect(JournalEntry::where('source_type', JournalSourceType::StatutoryRemittance->value)
        ->where('source_id', $return->id)->exists())->toBeFalse();
});
