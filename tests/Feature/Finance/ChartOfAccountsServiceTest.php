<?php

declare(strict_types=1);

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use App\Services\Finance\ChartOfAccountsService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    $this->svc = app(ChartOfAccountsService::class);
});

it('creates a GL account and a matching zero balance row', function () {
    $acc = $this->svc->create([
        'code' => '6000',
        'name' => 'Test Expense',
        'type' => GlAccountType::Expense->value,
    ]);

    expect($acc->id)->not->toBeNull();
    expect($acc->balance)->not->toBeNull();
    expect((float) $acc->balance->balance)->toBe(0.0);
});

it('updates a GL account', function () {
    $acc = $this->svc->create(['code' => '6000', 'name' => 'Old', 'type' => 'expense']);
    $updated = $this->svc->update($acc, ['name' => 'New Name']);

    expect($updated->name)->toBe('New Name');
    expect($updated->id)->toBe($acc->id);
});

it('archives a GL account via soft delete', function () {
    $acc = $this->svc->create(['code' => '6000', 'name' => 'X', 'type' => 'expense']);
    $this->svc->archive($acc);

    expect(GlAccount::withTrashed()->find($acc->id)->trashed())->toBeTrue();
    expect(GlAccount::find($acc->id))->toBeNull();
});

it('builds a tree rooted at top-level accounts', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $tree = $this->svc->tree();

    expect($tree)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($tree->pluck('code')->all())->toEqualCanonicalizing(['1000', '2000', '3000', '4000', '5000']);
    $assets = $tree->firstWhere('code', '1000');
    expect($assets->children->count())->toBeGreaterThanOrEqual(5);
});

it('filters list by type and search', function () {
    (new ChartOfAccountsSeeder())->run();

    $rows = $this->svc->list(['type' => 'asset'])->pluck('type')->unique();
    expect($rows)->toEqual(collect([GlAccountType::Asset]));

    $rows = $this->svc->list(['search' => 'SSNIT']);
    expect($rows->pluck('name')->contains(fn ($n) => str_contains($n, 'SSNIT')))->toBeTrue();
});

it('refuses to archive an account that has children', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $parent = GlAccount::where('code', '1000')->firstOrFail();

    expect(fn () => $this->svc->archive($parent))
        ->toThrow(\DomainException::class, 'child accounts');
});

it('refuses to archive an account that has a linked bank account', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\OrgBankAccountSeeder())->run();

    $linked = GlAccount::where('code', '1100')->firstOrFail(); // GCB Operating, linked by the OrgBankAccountSeeder

    expect(fn () => $this->svc->archive($linked))
        ->toThrow(\DomainException::class, 'bank account');
});
