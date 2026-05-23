<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->fixture = base_path('tests/Fixtures/Finance/Statements/gcb-sample.csv');
});

function rec2faFresh(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

it('finance_officer can list reconciliation index', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reconciliation')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reconciliation/Index'));
});

it('employee gets 403 on reconciliation index', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reconciliation')->assertForbidden();
});

it('finance_officer can upload a CSV statement', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $file = new UploadedFile($this->fixture, 'gcb-sample.csv', 'text/csv', null, true);

    $this->actingAs($u)->post('/finance/reconciliation', [
        'org_bank_account_id' => $this->bank->id,
        'bank_key'            => 'gcb',
        'file'                => $file,
    ])->assertRedirect();

    expect(BankStatement::count())->toBe(1);
});

it('auditor cannot upload', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $file = new UploadedFile($this->fixture, 'gcb-sample.csv', 'text/csv', null, true);

    $this->actingAs($u)->post('/finance/reconciliation', [
        'org_bank_account_id' => $this->bank->id,
        'bank_key'            => 'gcb',
        'file'                => $file,
    ])->assertForbidden();
});

it('post adjustment requires 2fa:fresh', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $expense = GlAccount::where('code', '5400')->first()
        ?? GlAccount::create(['code' => '5400', 'name' => 'Bank Charges', 'type' => 'expense']);
    GlAccountBalance::firstOrCreate(['gl_account_id' => $expense->id], ['balance' => 0]);

    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('m', 64),
        'file_name' => 'm.csv', 'format' => 'csv',
        'imported_by' => $u->id,
    ]);
    $line = BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1,
        'transaction_date' => '2026-05-20',
        'description' => 'BANK CHARGES MAY', 'amount' => -49.50,
        'line_hash' => str_repeat('q', 64),
    ]);

    $this->actingAs($u)->post("/finance/reconciliation/lines/{$line->id}/adjust", [
        'gl_account_id' => $expense->id, 'narration' => 'fee',
    ])->assertStatus(302);

    $this->actingAs(rec2faFresh($u))->post("/finance/reconciliation/lines/{$line->id}/adjust", [
        'gl_account_id' => $expense->id, 'narration' => 'fee',
    ])->assertRedirect();

    expect($line->fresh()->reconciled_at)->not->toBeNull();
});

it('finance_officer can stream the reconciliation PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date'      => '2026-05-31',
        'opening_balance'     => 0,
        'closing_balance'     => 1500,
        'file_hash'           => str_repeat('p', 64),
        'file_name'           => 'p.csv',
        'format'              => 'csv',
        'imported_by'         => $u->id,
    ]);
    BankStatementLine::create([
        'bank_statement_id' => $stmt->id,
        'line_no'           => 1,
        'transaction_date'  => '2026-05-20',
        'description'       => 'PAYMENT IN',
        'amount'            => 1500.00,
        'line_hash'         => str_repeat('r', 64),
    ]);

    $response = $this->actingAs($u)->get("/finance/reconciliation/{$stmt->id}/print");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('forces PDF download when ?download=1 is passed', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date'      => '2026-05-31',
        'opening_balance'     => 0,
        'closing_balance'     => 0,
        'file_hash'           => str_repeat('d', 64),
        'file_name'           => 'd.csv',
        'format'              => 'csv',
        'imported_by'         => $u->id,
    ]);

    $response = $this->actingAs($u)->get("/finance/reconciliation/{$stmt->id}/print?download=1");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment');
});

it('employee gets 403 on the reconciliation PDF', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date'      => '2026-05-31',
        'opening_balance'     => 0,
        'closing_balance'     => 0,
        'file_hash'           => str_repeat('e', 64),
        'file_name'           => 'e.csv',
        'format'              => 'csv',
        'imported_by'         => User::factory()->create()->id,
    ]);

    $this->actingAs($u)->get("/finance/reconciliation/{$stmt->id}/print")->assertForbidden();
});
