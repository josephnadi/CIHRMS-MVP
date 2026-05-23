<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\StatementImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->svc = app(StatementImportService::class);

    $this->fixturePath = base_path('tests/Fixtures/Finance/Statements/gcb-sample.csv');
});

it('imports a CSV statement and persists header + lines', function () {
    $file = new UploadedFile($this->fixturePath, 'gcb-sample.csv', 'text/csv', null, true);

    $stmt = $this->svc->import($file, $this->bank, $this->user, 'gcb');

    expect($stmt)->toBeInstanceOf(BankStatement::class);
    expect($stmt->format)->toBe('csv');
    expect($stmt->org_bank_account_id)->toBe($this->bank->id);
    expect((float) $stmt->closing_balance)->toBe(2150.50);
    expect(BankStatementLine::where('bank_statement_id', $stmt->id)->count())->toBe(4);
});

it('re-importing the same file returns the existing statement (idempotent)', function () {
    $file1 = new UploadedFile($this->fixturePath, 'gcb-sample.csv', 'text/csv', null, true);
    $stmt1 = $this->svc->import($file1, $this->bank, $this->user, 'gcb');

    $file2 = new UploadedFile($this->fixturePath, 'gcb-sample.csv', 'text/csv', null, true);
    $stmt2 = $this->svc->import($file2, $this->bank, $this->user, 'gcb');

    expect($stmt2->id)->toBe($stmt1->id);
    expect(BankStatement::count())->toBe(1);
    expect(BankStatementLine::count())->toBe(4);
});

it('rejects currency mismatch', function () {
    $this->bank->update(['currency' => 'USD']);

    $file = new UploadedFile($this->fixturePath, 'gcb-sample.csv', 'text/csv', null, true);

    expect(fn () => $this->svc->import($file, $this->bank, $this->user, 'gcb'))
        ->toThrow(\DomainException::class, 'currency');

    expect(BankStatement::count())->toBe(0);
});
