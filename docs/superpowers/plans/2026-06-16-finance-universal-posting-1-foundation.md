# Finance Universal Posting — Plan 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the central posting engine, DB-backed account-determination map, control accounts, and admin UI that make the General Ledger the single source of truth — so Plans 2 (event wirings) and 3 (refactors) have one pathway to post through.

**Architecture:** A new `PostingService` accepts an immutable `PostingDocument` (source + lines that reference accounts by slug or literal id), resolves slugs through `AccountResolver` (backed by a `posting_accounts` table), generates the journal reference, enforces idempotency, and delegates to the existing `JournalPostingService::post()` for balance validation and ledger mutation. A finance-admin UI lets the account map be re-pointed without code changes.

**Tech Stack:** Laravel 13, PHP 8.3 (readonly value objects, enums), Inertia + Vue 3, Pest. SQLite in tests, follows the existing Finance module conventions (Enum → FormRequest → Service → Resource, `SequenceService` for references, DB-backed permissions).

**This is Plan 1 of 3 for Phase 1.** Out of scope here (later plans): wiring payroll/loans/disbursements/member-fees/gateway into the engine (Plan 2); refactoring existing AP/AR/bank services onto it (Plan 3).

**Spec:** `docs/superpowers/specs/2026-06-16-finance-universal-posting-design.md`

---

### Task 1: Control accounts (Interest Income + Cash in Transit)

The accrual model needs two accounts the chart lacks: **Interest Income** (loan interest, used in Plan 2) and **Cash in Transit** (disbursement clearing). The chart seeder is idempotent (`updateOrCreate` by code), so we extend its array.

**Files:**
- Modify: `database/seeders/ChartOfAccountsSeeder.php`
- Test: `tests/Feature/Finance/ControlAccountsSeederTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use Database\Seeders\ChartOfAccountsSeeder;

it('seeds the interest income and cash-in-transit control accounts', function () {
    (new ChartOfAccountsSeeder())->run();

    $interest = GlAccount::where('code', '4600')->first();
    expect($interest)->not->toBeNull()
        ->and($interest->name)->toBe('Interest Income')
        ->and($interest->type->value)->toBe('income');

    $transit = GlAccount::where('code', '1130')->first();
    expect($transit)->not->toBeNull()
        ->and($transit->name)->toBe('Cash in Transit')
        ->and($transit->type->value)->toBe('asset');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/ControlAccountsSeederTest.php`
Expected: FAIL — `4600` / `1130` not found (null).

- [ ] **Step 3: Add the accounts to the seeder array**

In `database/seeders/ChartOfAccountsSeeder.php`, inside `private const ACCOUNTS`, add `'1130'` in the Assets block right after the `'1120'` line:

```php
        ['1130', 'Cash in Transit',             'asset', '1000'],
```

and add `'4600'` in the Income block right after the `'4500'` line:

```php
        ['4600', 'Interest Income',         'income', '4000'],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/ControlAccountsSeederTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/ChartOfAccountsSeeder.php tests/Feature/Finance/ControlAccountsSeederTest.php
git commit -m "feat(finance): add Interest Income + Cash in Transit control accounts"
```

---

### Task 2: Extend JournalSourceType enum

New money events need their own source types for traceability and idempotency keys.

**Files:**
- Modify: `app/Enums/JournalSourceType.php`
- Test: `tests/Unit/Finance/JournalSourceTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;

it('exposes the new universal-posting source types with labels', function () {
    expect(JournalSourceType::Payroll->value)->toBe('payroll')
        ->and(JournalSourceType::Disbursement->value)->toBe('disbursement')
        ->and(JournalSourceType::LoanDisbursement->value)->toBe('loan_disbursement')
        ->and(JournalSourceType::LoanRepayment->value)->toBe('loan_repayment')
        ->and(JournalSourceType::MemberFee->value)->toBe('member_fee')
        ->and(JournalSourceType::Payroll->label())->toBe('Payroll')
        ->and(JournalSourceType::LoanDisbursement->label())->toBe('Loan Disbursement');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Finance/JournalSourceTypeTest.php`
Expected: FAIL — undefined constant `Payroll`.

- [ ] **Step 3: Add the cases and labels**

In `app/Enums/JournalSourceType.php`, add these cases after `BankAdjustment`:

```php
    case Payroll          = 'payroll';
    case Disbursement     = 'disbursement';
    case LoanDisbursement = 'loan_disbursement';
    case LoanRepayment    = 'loan_repayment';
    case MemberFee        = 'member_fee';
```

and add to the `label()` match arms (before the closing `};`):

```php
            self::Payroll          => 'Payroll',
            self::Disbursement     => 'Disbursement',
            self::LoanDisbursement => 'Loan Disbursement',
            self::LoanRepayment    => 'Loan Repayment',
            self::MemberFee        => 'Member Fee',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Finance/JournalSourceTypeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/JournalSourceType.php tests/Unit/Finance/JournalSourceTypeTest.php
git commit -m "feat(finance): add universal-posting source types"
```

---

### Task 3: Add `source_purpose` + idempotency index to journal_entries

A single source can post more than one entry (e.g. accrual vs settlement). `source_purpose` discriminates them and, combined with `(source_type, source_id)`, forms the idempotency key. Defaults to `''` (never null) so the unique index actually constrains non-null sources; manual entries keep `source_id = null`, which SQL treats as distinct, so they never collide.

**Files:**
- Create: `database/migrations/2026_06_16_000001_add_source_purpose_to_journal_entries.php`
- Modify: `app/Models/JournalEntry.php`
- Test: `tests/Feature/Finance/JournalEntrySourcePurposeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\JournalEntry;

it('persists source_purpose and defaults it to empty string', function () {
    $entry = JournalEntry::create([
        'reference'   => 'JE-TEST-1',
        'entry_date'  => '2026-06-16',
        'narration'   => 'test',
        'status'      => 'draft',
        'source_type' => JournalSourceType::Payroll->value,
        'source_id'   => 42,
    ]);

    expect($entry->fresh()->source_purpose)->toBe('');

    $entry2 = JournalEntry::create([
        'reference'      => 'JE-TEST-2',
        'entry_date'     => '2026-06-16',
        'narration'      => 'test',
        'status'         => 'draft',
        'source_type'    => JournalSourceType::Payroll->value,
        'source_id'      => 42,
        'source_purpose' => 'settlement',
    ]);

    expect($entry2->fresh()->source_purpose)->toBe('settlement');
});

it('rejects a duplicate (source_type, source_id, source_purpose)', function () {
    $attrs = [
        'reference'      => 'JE-DUP-1',
        'entry_date'     => '2026-06-16',
        'narration'      => 'test',
        'status'         => 'draft',
        'source_type'    => JournalSourceType::Payroll->value,
        'source_id'      => 99,
        'source_purpose' => 'accrual',
    ];
    JournalEntry::create($attrs);

    expect(fn () => JournalEntry::create([...$attrs, 'reference' => 'JE-DUP-2']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/JournalEntrySourcePurposeTest.php`
Expected: FAIL — column `source_purpose` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_purpose', 50)->default('')->after('source_id');
            $table->unique(
                ['source_type', 'source_id', 'source_purpose'],
                'journal_entries_source_idem_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_source_idem_unique');
            $table->dropColumn('source_purpose');
        });
    }
};
```

- [ ] **Step 4: Add to the model fillable**

In `app/Models/JournalEntry.php`, change the `$fillable` array to include `source_purpose` (add it right after `'source_id'`):

```php
    protected $fillable = [
        'reference', 'entry_date', 'narration', 'status', 'source_type', 'source_id', 'source_purpose',
        'posted_at', 'posted_by', 'reversed_at', 'reversed_by', 'reversal_of_id', 'created_by',
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/JournalEntrySourcePurposeTest.php`
Expected: PASS (both tests).

- [ ] **Step 6: Run the existing journal/AP/AR suite to confirm no regression**

Run: `php artisan test tests/Feature/Finance --filter="Journal|ApPayment|ArInvoice|ArReceipt|VendorInvoice|BankAdjustment"`
Expected: PASS — existing services each post one JE per source id, so the new index does not collide.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_16_000001_add_source_purpose_to_journal_entries.php app/Models/JournalEntry.php tests/Feature/Finance/JournalEntrySourcePurposeTest.php
git commit -m "feat(finance): add source_purpose idempotency key to journal_entries"
```

---

### Task 4: `posting_accounts` table + PostingAccount model

The DB-backed account map: each `slug` points to a `gl_account_id`, grouped by `domain`, with a `locked` flag for system-critical rows.

**Files:**
- Create: `database/migrations/2026_06_16_000002_create_posting_accounts.php`
- Create: `app/Models/PostingAccount.php`
- Test: `tests/Feature/Finance/PostingAccountModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('stores a mapping and resolves its GL account relation', function () {
    $gl = GlAccount::where('code', '2300')->firstOrFail();

    $rule = PostingAccount::create([
        'slug'          => 'payroll.net_pay_payable',
        'gl_account_id' => $gl->id,
        'domain'        => 'payroll',
        'description'   => 'Net pay owed to staff',
        'locked'        => true,
    ]);

    expect($rule->fresh()->locked)->toBeTrue()
        ->and($rule->glAccount->code)->toBe('2300');
});

it('enforces a unique slug', function () {
    $gl = GlAccount::where('code', '2300')->firstOrFail();
    PostingAccount::create(['slug' => 'dup.slug', 'gl_account_id' => $gl->id, 'domain' => 'payroll']);

    expect(fn () => PostingAccount::create(['slug' => 'dup.slug', 'gl_account_id' => $gl->id, 'domain' => 'payroll']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingAccountModelTest.php`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->foreignId('gl_account_id')->constrained('gl_accounts');
            $table->string('domain', 50)->index();
            $table->string('description', 255)->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_accounts');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingAccount extends Model
{
    protected $table = 'posting_accounts';

    protected $fillable = ['slug', 'gl_account_id', 'domain', 'description', 'locked'];

    protected function casts(): array
    {
        return ['locked' => 'bool'];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingAccountModelTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_16_000002_create_posting_accounts.php app/Models/PostingAccount.php tests/Feature/Finance/PostingAccountModelTest.php
git commit -m "feat(finance): posting_accounts map table + model"
```

---

### Task 5: PostingAccountSeeder (default slug → account map)

Seeds the default mappings idempotently (upsert by slug), pointing each slug at its chart-of-accounts code.

**Files:**
- Create: `database/seeders/PostingAccountSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Finance/PostingAccountSeederTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\PostingAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
});

it('maps every seeded slug to an active GL account', function () {
    (new PostingAccountSeeder())->run();

    $rules = PostingAccount::with('glAccount')->get();
    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect($rule->glAccount)->not->toBeNull("slug {$rule->slug} has no GL account")
            ->and($rule->glAccount->is_active)->toBeTrue("slug {$rule->slug} maps to an inactive account");
    }
});

it('is idempotent and maps net pay payable to 2300', function () {
    (new PostingAccountSeeder())->run();
    (new PostingAccountSeeder())->run();

    $rule = PostingAccount::where('slug', 'payroll.net_pay_payable')->firstOrFail();
    expect(PostingAccount::where('slug', 'payroll.net_pay_payable')->count())->toBe(1)
        ->and($rule->glAccount->code)->toBe('2300')
        ->and($rule->locked)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingAccountSeederTest.php`
Expected: FAIL — seeder class missing.

- [ ] **Step 3: Write the seeder**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class PostingAccountSeeder extends Seeder
{
    /**
     * Default account-determination map.
     * Shape: [slug, account_code, domain, description, locked].
     * `locked` rows are system-critical and not re-pointable from the admin UI.
     */
    private const RULES = [
        ['payroll.salary_expense',           '5100', 'payroll', 'Gross basic salary expense',        true],
        ['payroll.allowance_expense',        '5110', 'payroll', 'Allowances expense',                false],
        ['payroll.employer_contrib_expense', '5120', 'payroll', 'Employer statutory contributions',  false],
        ['payroll.paye_payable',             '2210', 'payroll', 'PAYE withheld, owed to GRA',         true],
        ['payroll.ssnit_payable',            '2200', 'payroll', 'SSNIT owed (employee + employer)',   true],
        ['payroll.tier2_payable',            '2220', 'payroll', 'Tier-2 pension owed',                true],
        ['payroll.tier3_payable',            '2230', 'payroll', 'Tier-3 pension owed',                false],
        ['payroll.net_pay_payable',          '2300', 'payroll', 'Net pay owed to staff',             true],
        ['loan.principal_receivable',        '1300', 'loans',   'Staff loan principal receivable',    true],
        ['loan.interest_income',             '4600', 'loans',   'Loan interest income',               false],
        ['member_fee.receivable',            '1200', 'member_fees', 'Member fee receivable',          false],
        ['member_fee.income',                '4100', 'member_fees', 'Membership dues income',         false],
        ['bank.cash_in_transit',             '1130', 'bank',    'Disbursement clearing / in transit', false],
    ];

    public function run(): void
    {
        $codeToId = GlAccount::pluck('id', 'code');

        foreach (self::RULES as [$slug, $code, $domain, $description, $locked]) {
            $glId = $codeToId[$code] ?? null;
            if ($glId === null) {
                throw new \RuntimeException("PostingAccountSeeder: GL account {$code} not found for slug {$slug}.");
            }

            PostingAccount::updateOrCreate(
                ['slug' => $slug],
                [
                    'gl_account_id' => $glId,
                    'domain'        => $domain,
                    'description'   => $description,
                    'locked'        => $locked,
                ],
            );
        }
    }
}
```

- [ ] **Step 4: Register the seeder**

In `database/seeders/DatabaseSeeder.php`, add this call immediately after the `GlAccountBalanceSeeder` call (it depends on the chart existing):

```php
        $this->call(PostingAccountSeeder::class);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingAccountSeederTest.php`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add database/seeders/PostingAccountSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Finance/PostingAccountSeederTest.php
git commit -m "feat(finance): seed default posting-account map"
```

---

### Task 6: Exceptions (MissingAccountMapping, AlreadyPosted)

Typed exceptions so callers and the UI can react precisely.

**Files:**
- Create: `app/Exceptions/Finance/MissingAccountMappingException.php`
- Create: `app/Exceptions/Finance/AlreadyPostedException.php`
- Test: `tests/Unit/Finance/PostingExceptionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Exceptions\Finance\AlreadyPostedException;
use App\Exceptions\Finance\MissingAccountMappingException;

it('exposes domain-specific posting exceptions', function () {
    expect(new MissingAccountMappingException('x'))->toBeInstanceOf(DomainException::class)
        ->and(new AlreadyPostedException('y'))->toBeInstanceOf(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Finance/PostingExceptionsTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 3: Write the exceptions**

`app/Exceptions/Finance/MissingAccountMappingException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use DomainException;

class MissingAccountMappingException extends DomainException
{
}
```

`app/Exceptions/Finance/AlreadyPostedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use DomainException;

class AlreadyPostedException extends DomainException
{
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Finance/PostingExceptionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Exceptions/Finance/MissingAccountMappingException.php app/Exceptions/Finance/AlreadyPostedException.php tests/Unit/Finance/PostingExceptionsTest.php
git commit -m "feat(finance): posting engine exceptions"
```

---

### Task 7: AccountResolver

Resolves a slug to an active `GlAccount`, throwing `MissingAccountMappingException` when unmapped or inactive.

**Files:**
- Create: `app/Services/Finance/AccountResolver.php`
- Test: `tests/Feature/Finance/AccountResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Exceptions\Finance\MissingAccountMappingException;
use App\Models\GlAccount;
use App\Services\Finance\AccountResolver;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
});

it('resolves a mapped slug to its GL account', function () {
    $account = app(AccountResolver::class)->resolve('payroll.net_pay_payable');
    expect($account)->toBeInstanceOf(GlAccount::class)->and($account->code)->toBe('2300');
});

it('throws when the slug is not mapped', function () {
    expect(fn () => app(AccountResolver::class)->resolve('does.not.exist'))
        ->toThrow(MissingAccountMappingException::class);
});

it('throws when the mapped account is inactive', function () {
    GlAccount::where('code', '2300')->update(['is_active' => false]);
    expect(fn () => app(AccountResolver::class)->resolve('payroll.net_pay_payable'))
        ->toThrow(MissingAccountMappingException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/AccountResolverTest.php`
Expected: FAIL — `AccountResolver` missing.

- [ ] **Step 3: Write the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\Finance\MissingAccountMappingException;
use App\Models\GlAccount;
use App\Models\PostingAccount;

class AccountResolver
{
    public function resolve(string $slug): GlAccount
    {
        $rule = PostingAccount::where('slug', $slug)->first();

        if (! $rule) {
            throw new MissingAccountMappingException(
                "No posting-account mapping found for slug '{$slug}'. Map it under Finance → Posting Rules."
            );
        }

        $account = GlAccount::where('id', $rule->gl_account_id)->where('is_active', true)->first();

        if (! $account) {
            throw new MissingAccountMappingException(
                "Posting slug '{$slug}' maps to an inactive or missing GL account (id {$rule->gl_account_id})."
            );
        }

        return $account;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/AccountResolverTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/AccountResolver.php tests/Feature/Finance/AccountResolverTest.php
git commit -m "feat(finance): AccountResolver for slug -> GL account"
```

---

### Task 8: PostingDocument + PostingLine value objects

Immutable description of a journal request. A line references its account by **slug** (control accounts) or **literal id** (per-record accounts), and carries exactly one of debit/credit.

**Files:**
- Create: `app/Services/Finance/Posting/PostingLine.php`
- Create: `app/Services/Finance/Posting/PostingDocument.php`
- Test: `tests/Unit/Finance/PostingDocumentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;

it('builds debit and credit lines via factories', function () {
    $dr = PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0, narration: 'gross');
    $cr = PostingLine::credit(accountId: 5, amount: 100.0);

    expect($dr->accountSlug)->toBe('payroll.salary_expense')
        ->and($dr->debit)->toBe(100.0)->and($dr->credit)->toBe(0.0)
        ->and($cr->accountId)->toBe(5)->and($cr->credit)->toBe(100.0);
});

it('rejects a line with both slug and id', function () {
    expect(fn () => new PostingLine(accountId: 5, accountSlug: 'x', debit: 1, credit: 0))
        ->toThrow(DomainException::class);
});

it('rejects a line with neither debit nor credit', function () {
    expect(fn () => new PostingLine(accountId: 5, accountSlug: null, debit: 0, credit: 0))
        ->toThrow(DomainException::class);
});

it('rejects a line with both debit and credit', function () {
    expect(fn () => new PostingLine(accountId: 5, accountSlug: null, debit: 1, credit: 1))
        ->toThrow(DomainException::class);
});

it('requires at least two lines and reports balance', function () {
    $lines = [
        PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0),
        PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 100.0),
    ];
    $doc = new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 1,
        purpose: 'accrual',
        date: '2026-06-16',
        narration: 'Payroll accrual',
        lines: $lines,
    );

    expect($doc->isBalanced())->toBeTrue()->and($doc->purpose)->toBe('accrual');

    expect(fn () => new PostingDocument(
        sourceType: JournalSourceType::Payroll, sourceId: 1, purpose: '',
        date: '2026-06-16', narration: 'x',
        lines: [PostingLine::debit(slug: 'a', amount: 1)],
    ))->toThrow(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Finance/PostingDocumentTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 3: Write PostingLine**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Posting;

use DomainException;

final class PostingLine
{
    public function __construct(
        public readonly ?int $accountId,
        public readonly ?string $accountSlug,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?string $narration = null,
    ) {
        if (($accountId === null) === ($accountSlug === null)) {
            throw new DomainException('PostingLine requires exactly one of accountId or accountSlug.');
        }
        if ($debit < 0 || $credit < 0) {
            throw new DomainException('PostingLine amounts must be non-negative.');
        }
        if (($debit > 0) === ($credit > 0)) {
            throw new DomainException('PostingLine must carry exactly one of a positive debit or credit.');
        }
    }

    public static function debit(float $amount, ?string $slug = null, ?int $accountId = null, ?string $narration = null): self
    {
        return new self($accountId, $slug, $amount, 0.0, $narration);
    }

    public static function credit(float $amount, ?string $slug = null, ?int $accountId = null, ?string $narration = null): self
    {
        return new self($accountId, $slug, 0.0, $amount, $narration);
    }
}
```

- [ ] **Step 4: Write PostingDocument**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Posting;

use App\Enums\JournalSourceType;
use DomainException;

final class PostingDocument
{
    /** @param PostingLine[] $lines */
    public function __construct(
        public readonly JournalSourceType $sourceType,
        public readonly ?int $sourceId,
        public readonly string $purpose,
        public readonly string $date,
        public readonly string $narration,
        public readonly array $lines,
        public readonly string $currency = 'GHS',
    ) {
        if (count($lines) < 2) {
            throw new DomainException('A posting document needs at least two lines.');
        }
        foreach ($lines as $line) {
            if (! $line instanceof PostingLine) {
                throw new DomainException('Every posting line must be a PostingLine instance.');
            }
        }
    }

    public function isBalanced(): bool
    {
        $dr = array_sum(array_map(fn (PostingLine $l) => $l->debit, $this->lines));
        $cr = array_sum(array_map(fn (PostingLine $l) => $l->credit, $this->lines));

        return abs($dr - $cr) < 0.005;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Finance/PostingDocumentTest.php`
Expected: PASS (all cases).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/Posting/PostingLine.php app/Services/Finance/Posting/PostingDocument.php tests/Unit/Finance/PostingDocumentTest.php
git commit -m "feat(finance): PostingDocument + PostingLine value objects"
```

---

### Task 9: PostingService — the engine

Builds the JE from a `PostingDocument`, resolves account references, generates the reference via `SequenceService`, enforces idempotency, and delegates to `JournalPostingService::post()`.

**Files:**
- Create: `app/Services/Finance/PostingService.php`
- Test: `tests/Feature/Finance/PostingServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    $this->actingAs(User::factory()->create());
});

function payrollAccrualDoc(string $purpose = 'accrual'): PostingDocument
{
    return new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 7,
        purpose: $purpose,
        date: '2026-06-16',
        narration: 'Payroll accrual run 7',
        lines: [
            PostingLine::debit(slug: 'payroll.salary_expense', amount: 1000.0, narration: 'gross'),
            PostingLine::credit(slug: 'payroll.paye_payable', amount: 150.0),
            PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 850.0),
        ],
    );
}

it('posts a balanced journal entry and updates balances', function () {
    $entry = app(PostingService::class)->post(payrollAccrualDoc());

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->source_type)->toBe(JournalSourceType::Payroll)
        ->and($entry->source_id)->toBe(7)
        ->and($entry->source_purpose)->toBe('accrual')
        ->and($entry->lines)->toHaveCount(3)
        ->and(str_starts_with($entry->reference, 'JE-'))->toBeTrue();

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    $netpay  = GlAccount::where('code', '2300')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(1000.0)
        ->and((float) GlAccountBalance::where('gl_account_id', $netpay->id)->value('balance'))->toBe(850.0);
});

it('is idempotent: re-posting the same source returns the existing entry without double-counting', function () {
    $first  = app(PostingService::class)->post(payrollAccrualDoc());
    $second = app(PostingService::class)->post(payrollAccrualDoc());

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::where('source_type', JournalSourceType::Payroll->value)->where('source_id', 7)->count())->toBe(1);

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(1000.0);
});

it('resolves literal account ids as well as slugs', function () {
    $bankGl = GlAccount::where('code', '1100')->firstOrFail();
    $doc = new PostingDocument(
        sourceType: JournalSourceType::Disbursement,
        sourceId: 3,
        purpose: 'settlement',
        date: '2026-06-16',
        narration: 'Settle net pay',
        lines: [
            PostingLine::debit(slug: 'payroll.net_pay_payable', amount: 850.0),
            PostingLine::credit(accountId: $bankGl->id, amount: 850.0, narration: 'cash out'),
        ],
    );

    $entry = app(PostingService::class)->post($doc);
    $bankLine = $entry->lines->firstWhere('gl_account_id', $bankGl->id);
    expect($bankLine)->not->toBeNull()->and((float) $bankLine->credit_amount)->toBe(850.0);
});

it('reverses a posted entry for a source', function () {
    $entry = app(PostingService::class)->post(payrollAccrualDoc());
    $by = User::factory()->create();

    $reversal = app(PostingService::class)->reverseFor(
        JournalSourceType::Payroll, 7, 'accrual', $by, 'run cancelled'
    );

    expect($reversal->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->fresh()->status)->toBe(JournalEntryStatus::Reversed);

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingServiceTest.php`
Expected: FAIL — `PostingService` missing.

- [ ] **Step 3: Write the engine**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalEntryStatus;
use App\Exceptions\Finance\AlreadyPostedException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single pathway every business event uses to reach the General Ledger.
 * Builds a JournalEntry from a PostingDocument, resolves account references
 * (slug -> GL via AccountResolver, or literal id), enforces idempotency on
 * (source_type, source_id, source_purpose), and delegates the actual balance
 * mutation to JournalPostingService::post().
 */
class PostingService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly AccountResolver $resolver,
        private readonly SequenceService $sequences,
    ) {
    }

    public function post(PostingDocument $doc): JournalEntry
    {
        // Idempotency (only for identifiable sources). A null source_id is a
        // one-off (manual / ad-hoc) entry and is never deduplicated.
        if ($doc->sourceId !== null) {
            $existing = JournalEntry::query()
                ->where('source_type', $doc->sourceType->value)
                ->where('source_id', $doc->sourceId)
                ->where('source_purpose', $doc->purpose)
                ->first();

            if ($existing) {
                return $existing->load('lines.glAccount');
            }
        }

        try {
            return DB::transaction(function () use ($doc) {
                $entry = JournalEntry::create([
                    'reference'      => $this->nextReference(),
                    'entry_date'     => $doc->date,
                    'narration'      => $doc->narration,
                    'status'         => JournalEntryStatus::Draft->value,
                    'source_type'    => $doc->sourceType->value,
                    'source_id'      => $doc->sourceId,
                    'source_purpose' => $doc->purpose,
                    'created_by'     => auth()->id(),
                ]);

                $lineNo = 1;
                foreach ($doc->lines as $line) {
                    $accountId = $line->accountId ?? $this->resolver->resolve($line->accountSlug)->id;

                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'line_no'          => $lineNo++,
                        'gl_account_id'    => $accountId,
                        'debit_amount'     => $line->debit,
                        'credit_amount'    => $line->credit,
                        'narration'        => $line->narration,
                    ]);
                }

                return $this->journal->post($entry->fresh('lines.glAccount'));
            });
        } catch (QueryException $e) {
            // Concurrent race lost to the unique index — treat as already posted.
            if ($doc->sourceId !== null) {
                $existing = JournalEntry::query()
                    ->where('source_type', $doc->sourceType->value)
                    ->where('source_id', $doc->sourceId)
                    ->where('source_purpose', $doc->purpose)
                    ->first();
                if ($existing) {
                    return $existing->load('lines.glAccount');
                }
            }
            throw new AlreadyPostedException($e->getMessage(), 0, $e);
        }
    }

    public function reverseFor(
        \App\Enums\JournalSourceType $type,
        int $sourceId,
        string $purpose,
        User $by,
        string $reason,
    ): JournalEntry {
        $entry = JournalEntry::query()
            ->where('source_type', $type->value)
            ->where('source_id', $sourceId)
            ->where('source_purpose', $purpose)
            ->where('status', JournalEntryStatus::Posted->value)
            ->firstOrFail();

        return $this->journal->reverse($entry, $by, $reason);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');

        return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingServiceTest.php`
Expected: PASS (all five).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/PostingService.php tests/Feature/Finance/PostingServiceTest.php
git commit -m "feat(finance): PostingService engine with idempotent posting + reversal"
```

---

### Task 10: Posting-rules permission

Add `finance.posting_rules.manage` and grant it to `finance_officer` (super_admin / ceo already inherit all via the wildcard).

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Test: `tests/Feature/Finance/PostingRulesPermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants posting_rules.manage to finance_officer and super_admin only', function () {
    $fo = User::factory()->create(['role' => 'finance_officer']);
    $sa = User::factory()->create(['role' => 'super_admin']);
    $emp = User::factory()->create(['role' => 'employee']);

    expect($fo->hasPermission('finance.posting_rules.manage'))->toBeTrue()
        ->and($sa->hasPermission('finance.posting_rules.manage'))->toBeTrue()
        ->and($emp->hasPermission('finance.posting_rules.manage'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingRulesPermissionTest.php`
Expected: FAIL — finance_officer lacks the permission.

- [ ] **Step 3: Declare the permission**

In `database/seeders/RolePermissionSeeder.php`, inside `private const PERMISSIONS`, add this line right after the `'finance.hub'` entry:

```php
        'finance.posting_rules.manage' => ['Finance', 'View and re-map the GL account-determination rules'],
```

- [ ] **Step 4: Grant it to finance_officer**

In the same file, inside the `'finance_officer' => [` array, add this line right after the `'finance.hub',` line:

```php
            'finance.posting_rules.manage',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingRulesPermissionTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php tests/Feature/Finance/PostingRulesPermissionTest.php
git commit -m "feat(finance): posting_rules.manage permission"
```

---

### Task 11: PostingRuleResource + UpdatePostingRuleRequest + Controller + routes

Admin endpoints: list the map grouped by domain, and re-point a single non-locked rule.

**Files:**
- Create: `app/Http/Resources/Finance/PostingRuleResource.php`
- Create: `app/Http/Requests/Finance/UpdatePostingRuleRequest.php`
- Create: `app/Http/Controllers/Finance/PostingRuleController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/PostingRuleEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\PostingAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
});

it('finance_officer can view the posting rules page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/posting-rules')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/PostingRules/Index'));
});

it('employee is forbidden from the posting rules page', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/posting-rules')->assertForbidden();
});

it('re-points a non-locked rule to another account of the same type', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.allowance_expense')->firstOrFail(); // not locked, expense
    $other = GlAccount::where('code', '5200')->firstOrFail(); // Operations Expense (expense)

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $other->id])
        ->assertRedirect();

    expect($rule->fresh()->gl_account_id)->toBe($other->id);
});

it('rejects re-pointing a locked rule', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.net_pay_payable')->firstOrFail(); // locked
    $other = GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $other->id])
        ->assertSessionHasErrors('gl_account_id');

    expect($rule->fresh()->gl_account_id)->not->toBe($other->id);
});

it('rejects re-pointing to an account of a different type', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.allowance_expense')->firstOrFail(); // expense
    $income = GlAccount::where('code', '4100')->firstOrFail(); // income

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $income->id])
        ->assertSessionHasErrors('gl_account_id');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingRuleEndpointTest.php`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the Resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\PostingAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PostingAccount */
class PostingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'slug'          => $this->slug,
            'domain'        => $this->domain,
            'description'   => $this->description,
            'locked'        => $this->locked,
            'gl_account_id' => $this->gl_account_id,
            'gl_account'    => $this->whenLoaded('glAccount', fn () => [
                'id'   => $this->glAccount->id,
                'code' => $this->glAccount->code,
                'name' => $this->glAccount->name,
                'type' => $this->glAccount->type->value,
            ]),
        ];
    }
}
```

- [ ] **Step 4: Write the FormRequest**

It authorizes on the permission and enforces: target account exists, is active, matches the current rule's account *type*, and the rule is not `locked`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('finance.posting_rules.manage') === true;
    }

    public function rules(): array
    {
        return [
            'gl_account_id' => [
                'required',
                'integer',
                'exists:gl_accounts,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    /** @var PostingAccount $rule */
                    $rule = $this->route('postingAccount');

                    if ($rule->locked) {
                        $fail('This mapping is locked and cannot be re-pointed.');
                        return;
                    }

                    $target  = GlAccount::find($value);
                    $current = $rule->glAccount;
                    if ($target && $current && $target->type !== $current->type) {
                        $fail("The account must be of type {$current->type->value} to keep postings valid.");
                    }
                    if ($target && ! $target->is_active) {
                        $fail('The target GL account is archived.');
                    }
                },
            ],
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\UpdatePostingRuleRequest;
use App\Http\Resources\Finance\PostingRuleResource;
use App\Models\GlAccount;
use App\Models\PostingAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PostingRuleController extends Controller
{
    public function index(): Response
    {
        $rules = PostingAccount::with('glAccount')
            ->orderBy('domain')
            ->orderBy('slug')
            ->get();

        return Inertia::render('Finance/PostingRules/Index', [
            'activeModule' => 'finance-posting-rules',
            'rules'        => PostingRuleResource::collection($rules),
            'glAccounts'   => GlAccount::active()
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function update(UpdatePostingRuleRequest $request, PostingAccount $postingAccount): RedirectResponse
    {
        $postingAccount->update(['gl_account_id' => $request->validated()['gl_account_id']]);

        return back()->with('success', "Mapping '{$postingAccount->slug}' updated.");
    }
}
```

- [ ] **Step 6: Add the routes**

In `routes/web.php`, inside the `Route::prefix('finance')->name('finance.')->group(...)` block, add immediately after the `accounts.manage` group (around line 950):

```php
        // Universal Posting — account-determination map
        Route::middleware('permission:finance.posting_rules.manage')->group(function () {
            Route::get('posting-rules',                       [\App\Http\Controllers\Finance\PostingRuleController::class, 'index'])->name('posting-rules.index');
            Route::patch('posting-rules/{postingAccount}',    [\App\Http\Controllers\Finance\PostingRuleController::class, 'update'])->name('posting-rules.update');
        });
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingRuleEndpointTest.php`
Expected: PASS (all five).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Resources/Finance/PostingRuleResource.php app/Http/Requests/Finance/UpdatePostingRuleRequest.php app/Http/Controllers/Finance/PostingRuleController.php routes/web.php tests/Feature/Finance/PostingRuleEndpointTest.php
git commit -m "feat(finance): posting-rules admin endpoints"
```

---

### Task 12: Posting Rules admin UI (Vue page)

A finance-admin page listing the map grouped by domain, with an inline account picker per non-locked row, submitting via `PATCH`. Mirrors the structure of `Finance/Customers/Index.vue`.

**Files:**
- Create: `resources/js/Pages/Finance/PostingRules/Index.vue`
- Test: covered by `PostingRuleEndpointTest` (Inertia component assertion in Task 11). Manual check below.

- [ ] **Step 1: Write the page**

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    rules:      { type: Object, required: true },
    glAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('finance.posting_rules.manage');
});

const rows = computed(() => props.rules.data ?? props.rules ?? []);

const grouped = computed(() => {
    const out = {};
    for (const r of rows.value) {
        (out[r.domain] ??= []).push(r);
    }
    return out;
});

// Accounts selectable for a given rule: same type as its current account.
const optionsFor = (rule) => {
    const type = rule.gl_account?.type;
    return props.glAccounts.filter((a) => a.type === type);
};

const editingId = ref(null);
const form = useForm({ gl_account_id: null });

const startEdit = (rule) => {
    editingId.value = rule.id;
    form.clearErrors();
    form.gl_account_id = rule.gl_account_id;
};

const save = (rule) => {
    form.patch(route('finance.posting-rules.update', rule.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};
</script>

<template>
    <Head title="Posting Rules" />

    <div class="p-6 max-w-5xl mx-auto">
        <header class="mb-6">
            <h1 class="text-2xl font-semibold text-slate-100">Posting Rules</h1>
            <p class="text-slate-400 mt-1">
                The account-determination map. Each rule tells the ledger which GL account a kind of
                transaction posts to. Locked rules are system-critical and cannot be re-pointed.
            </p>
        </header>

        <EmptyState v-if="rows.length === 0" title="No posting rules" description="Run the PostingAccountSeeder." />

        <div v-for="(domainRules, domain) in grouped" :key="domain" class="mb-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-cobalt-300 mb-2">{{ domain }}</h2>

            <div class="rounded-xl border border-slate-700/60 divide-y divide-slate-700/60 bg-slate-900/40">
                <div v-for="rule in domainRules" :key="rule.id" class="p-4 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-slate-200">{{ rule.slug }}</p>
                        <p class="text-xs text-slate-500 truncate">{{ rule.description }}</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <template v-if="editingId === rule.id">
                            <div>
                                <select v-model="form.gl_account_id"
                                        class="rounded-lg bg-slate-800 border-slate-600 text-sm text-slate-100">
                                    <option v-for="a in optionsFor(rule)" :key="a.id" :value="a.id">
                                        {{ a.code }} — {{ a.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.gl_account_id" class="mt-1" />
                            </div>
                            <PrimaryButton :disabled="form.processing" @click="save(rule)">Save</PrimaryButton>
                            <button class="text-slate-400 text-sm" @click="editingId = null">Cancel</button>
                        </template>

                        <template v-else>
                            <span class="text-sm text-slate-300 font-mono">
                                {{ rule.gl_account?.code }} — {{ rule.gl_account?.name }}
                            </span>
                            <span v-if="rule.locked"
                                  class="text-xs px-2 py-0.5 rounded-full bg-slate-700 text-slate-300">locked</span>
                            <button v-else-if="canManage"
                                    class="text-cobalt-300 text-sm hover:underline"
                                    @click="startEdit(rule)">Re-point</button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Re-run the endpoint test (covers the component render)**

Run: `php artisan test tests/Feature/Finance/PostingRuleEndpointTest.php`
Expected: PASS — the `Finance/PostingRules/Index` component resolves.

- [ ] **Step 3: Build assets to confirm the page compiles**

Run: `npm run build`
Expected: build succeeds with no Vue compile errors for `PostingRules/Index.vue`.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Finance/PostingRules/Index.vue
git commit -m "feat(finance): posting-rules admin UI page"
```

---

### Task 13: Link Posting Rules from the Finance Hub

Make the page reachable from the Finance Hub instead of only by URL.

**Files:**
- Modify: `resources/js/Pages/Finance/Hub.vue`
- Test: manual (visual) — the hub is a presentational page.

The hub header (around lines 76–87) holds a `<div class="flex gap-2">` containing two `<Link>` buttons (Chart of Accounts, Bank Accounts). These are not permission-gated in markup — the target route enforces the permission. Add a third button matching that exact style.

- [ ] **Step 1: Add the Posting Rules link**

In `resources/js/Pages/Finance/Hub.vue`, inside the `<div class="flex gap-2">` block, add this third `<Link>` immediately after the Bank Accounts `<Link>` (after line 86):

```vue
                <Link :href="route('finance.posting-rules.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">rule_settings</span>
                    Posting Rules
                </Link>
```

(`Link` is already imported in `Hub.vue`; no new import is needed.)

- [ ] **Step 2: Build to confirm it compiles**

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Finance/Hub.vue
git commit -m "feat(finance): link Posting Rules from the Finance Hub"
```

---

### Task 14: Full suite + regression gate

Confirm the whole foundation is green and nothing else regressed.

**Files:** none (verification only).

- [ ] **Step 1: Run the full Finance suite**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance`
Expected: PASS — all new tests plus the pre-existing Finance suite.

- [ ] **Step 2: Run the complete test suite**

Run: `php artisan test`
Expected: PASS — no regressions across the app (the new unique index and column are additive; existing services each post one JE per source id).

- [ ] **Step 3: Sanity-seed locally**

Run: `php artisan migrate:fresh --seed`
Expected: completes; `posting_accounts` populated. Verify quickly:

Run: `php artisan tinker --execute="echo App\Models\PostingAccount::count();"`
Expected: prints `13` (the number of rows in `PostingAccountSeeder::RULES`).

- [ ] **Step 4: Commit any incidental fixes, then finish**

```bash
git add -A
git commit -m "test(finance): universal-posting foundation green" --allow-empty
```

---

## Self-Review notes (for the implementer)

- **Idempotency depends on `source_purpose` defaulting to `''`**, not null — that is what makes the unique index bite for non-null sources while leaving null-`source_id` manual entries unconstrained. Don't "tidy" the default to null.
- **`reverseFor` requires a posted entry** for the exact `(type, sourceId, purpose)` — callers in Plan 2 must pass the same `purpose` they posted with.
- **Plan 2** wires payroll/loans/disbursements/member-fees/gateway to build `PostingDocument`s and call `PostingService::post()`. **Plan 3** migrates the existing AP/AR/bank/gateway services onto the engine with characterization tests. Neither is in this plan.
