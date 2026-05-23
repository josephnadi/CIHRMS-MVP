# Finance F5 — Bank Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add bank-statement import (CSV/OFX/MT940), 3-tier auto-matching against F4-populated `ar_receipts.external_ref` + AP payments, manual pairing UI, and bank-fee/interest adjustment JEs routed through F2's `JournalPostingService`.

**Architecture:** `StatementImportService` detects format and dispatches to one of three parsers; persisted lines flow through `ReconciliationMatcher` (Tier 1: external_ref exact; Tier 2: amount+date+ref-in-description; Tier 3: amount+date suggested-only). `ReconciliationService` is the sole writer of `bank_transaction_matches` and back-populates `ap_payments.external_ref` on link. `BankAdjustmentService` posts one-line JEs through `JournalPostingService` (new `JournalSourceType::BankAdjustment` case) — `gl_account_balances` is never written directly.

**Tech Stack:** Laravel 13.7, PHP 8.3, Eloquent + SoftDeletes, Pest, Inertia + Vue 3. SHA-256 for `file_hash` and `line_hash` idempotency keys. Per-bank CSV column maps in `config/banks.php`. OFX 2.x via PHP `SimpleXMLElement`. MT940 hand-parser for `:61:`/`:86:` pairs.

**Spec reference:** [docs/superpowers/specs/2026-05-23-finance-f5-bank-reconciliation-design.md](../specs/2026-05-23-finance-f5-bank-reconciliation-design.md)

**Branch:** `feat/finance-f5-bank-reconciliation` (off F4 head `928acef`; rebase onto `origin/main` after PR #14 merges)

---

## File Structure

### New files

```
app/Enums/
    (modified)  JournalSourceType.php          -- add BankAdjustment case

app/Models/
    BankStatement.php
    BankStatementLine.php
    BankTransactionMatch.php

app/Services/Finance/
    StatementImportService.php
    ReconciliationMatcher.php
    ReconciliationService.php
    BankAdjustmentService.php

app/Services/Finance/Statements/
    StatementParser.php                         -- interface
    CsvStatementParser.php
    OfxStatementParser.php
    Mt940StatementParser.php
    StatementFormatDetector.php

app/Exceptions/Finance/
    StatementParseException.php

app/Http/Middleware/                            -- (no new middleware)

app/Http/Requests/Finance/
    UploadBankStatementRequest.php
    LinkReconciliationLineRequest.php
    UnlinkReconciliationLineRequest.php
    PostBankAdjustmentRequest.php

app/Http/Resources/Finance/
    BankStatementResource.php
    BankStatementLineResource.php

app/Http/Controllers/Finance/
    ReconciliationController.php

config/
    banks.php                                   -- per-bank CSV column maps

database/migrations/
    2026_05_27_000001_add_external_ref_to_ap_payments.php
    2026_05_27_000002_create_bank_statements.php
    2026_05_27_000003_create_bank_statement_lines.php
    2026_05_27_000004_create_bank_transaction_matches.php

database/factories/
    BankStatementFactory.php
    BankStatementLineFactory.php

resources/js/Pages/Finance/Reconciliation/
    Index.vue
    Show.vue

tests/Unit/Finance/
    EnumsF5Test.php

tests/Feature/Finance/
    BankReconciliationMigrationsTest.php
    F5ModelsTest.php
    F5PermissionsSeedTest.php
    CsvStatementParserTest.php
    OfxStatementParserTest.php
    Mt940StatementParserTest.php
    StatementImportServiceTest.php
    ReconciliationMatcherTest.php
    ReconciliationServiceTest.php
    BankAdjustmentServiceTest.php
    ReconciliationEndpointsTest.php
    FinanceHubF5Test.php

tests/Fixtures/Finance/Statements/
    gcb-sample.csv
    stanbic-sample.csv
    gtb-sample.csv
    sample.ofx
    sample.mt940
```

### Modified files

```
app/Enums/JournalSourceType.php                 -- add BankAdjustment case
app/Models/ApPayment.php                        -- add external_ref to $fillable, scope
app/Models/User.php                             -- add 4 new perms to ROLE_PERMISSIONS
app/Services/Finance/FinanceHubService.php      -- add reconciliationStats()
bootstrap/app.php                               -- (no change for F5)
database/seeders/RolePermissionSeeder.php       -- add 4 new perms
routes/web.php                                  -- reconciliation routes inside finance prefix
resources/js/Layouts/AuthenticatedLayout.vue    -- sidebar entry + icon palette
resources/js/Pages/Finance/Hub.vue              -- reconciliation tile
tests/Feature/Finance/FinanceHubTest.php        -- assert reconciliationStats key
```

### Responsibility boundaries

- **`StatementFormatDetector`** — pure function: extension + magic-bytes → `csv` | `ofx` | `mt940`. Throws on unknown.
- **`StatementParser` interface** — `parse(string $rawContent): array` returning the normalized statement structure documented in the spec.
- **`CsvStatementParser` / `OfxStatementParser` / `Mt940StatementParser`** — one format each; no cross-talk; each independently testable with one fixture.
- **`StatementImportService`** — orchestration. Picks the parser, persists header + lines in one transaction, computes `file_hash`, returns the persisted `BankStatement` (or an existing one on hash collision).
- **`ReconciliationMatcher`** — read-only against AP/AR; calls `ReconciliationService::link()` for auto-links; never touches the DB directly.
- **`ReconciliationService`** — sole writer of `bank_transaction_matches`. Back-populates `ap_payments.external_ref` when an AP payment is linked.
- **`BankAdjustmentService`** — only path to post a bank-fee/interest JE; auto-links the line to the resulting JE.
- **`ReconciliationController`** — thin: delegates to services. Returns Inertia render or `back()`.

---

## Task 1: JournalSourceType `BankAdjustment` enum case

**Files:**
- Modify: `app/Enums/JournalSourceType.php`
- Test: `tests/Unit/Finance/EnumsF5Test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Finance/EnumsF5Test.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;

it('JournalSourceType includes BankAdjustment case for F5', function () {
    $values = array_map(fn ($c) => $c->value, JournalSourceType::cases());
    expect($values)->toContain('bank_adjustment');
});

it('JournalSourceType::BankAdjustment has a non-empty label', function () {
    expect(JournalSourceType::BankAdjustment->label())->toBeString()->not->toBeEmpty();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=EnumsF5Test
```

Expected: 2 failures. `JournalSourceType::BankAdjustment` does not exist yet.

- [ ] **Step 3: Add the enum case**

Open `app/Enums/JournalSourceType.php`. Find the last `case` line; append (preserving the existing block structure):

```php
    case BankAdjustment = 'bank_adjustment';
```

And add a `match` arm in the `label()` method:

```php
            self::BankAdjustment => 'Bank Adjustment',
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=EnumsF5Test
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Enums/JournalSourceType.php tests/Unit/Finance/EnumsF5Test.php
git commit -m "$(cat <<'EOF'
feat(finance): add JournalSourceType::BankAdjustment case (F5)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Migrations (ap_payments.external_ref + 3 new tables)

**Files:**
- Create: `database/migrations/2026_05_27_000001_add_external_ref_to_ap_payments.php`
- Create: `database/migrations/2026_05_27_000002_create_bank_statements.php`
- Create: `database/migrations/2026_05_27_000003_create_bank_statement_lines.php`
- Create: `database/migrations/2026_05_27_000004_create_bank_transaction_matches.php`
- Test: `tests/Feature/Finance/BankReconciliationMigrationsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/BankReconciliationMigrationsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds external_ref column to ap_payments', function () {
    expect(Schema::hasColumn('ap_payments', 'external_ref'))->toBeTrue();
});

it('creates the bank_statements table', function () {
    expect(Schema::hasTable('bank_statements'))->toBeTrue();
    expect(Schema::hasColumns('bank_statements', [
        'id', 'org_bank_account_id', 'statement_date', 'period_start',
        'opening_balance', 'closing_balance', 'currency',
        'file_hash', 'file_name', 'format', 'imported_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the bank_statement_lines table', function () {
    expect(Schema::hasTable('bank_statement_lines'))->toBeTrue();
    expect(Schema::hasColumns('bank_statement_lines', [
        'id', 'bank_statement_id', 'line_no', 'transaction_date', 'value_date',
        'description', 'reference', 'amount', 'running_balance', 'line_hash',
        'matched_type', 'matched_id', 'confidence', 'reconciled_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates the bank_transaction_matches table', function () {
    expect(Schema::hasTable('bank_transaction_matches'))->toBeTrue();
    expect(Schema::hasColumns('bank_transaction_matches', [
        'id', 'bank_statement_line_id', 'matched_type', 'matched_id',
        'confidence', 'matched_by', 'matched_at',
        'unmatched_at', 'unmatched_by', 'unmatched_reason',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=BankReconciliationMigrationsTest
```

Expected: 4 failures (none of the schema exists yet).

- [ ] **Step 3: `ap_payments.external_ref` migration**

`database/migrations/2026_05_27_000001_add_external_ref_to_ap_payments.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5: AP payments need a bank-side external reference so the reconciliation
 * matcher can Tier-1 match debit statement lines. Operator populates this
 * manually until a future GhIPSS-callback feature does it automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_payments', function (Blueprint $table) {
            $table->string('external_ref', 100)->nullable()->after('narration');
            $table->index('external_ref');
        });
    }

    public function down(): void
    {
        Schema::table('ap_payments', function (Blueprint $table) {
            $table->dropIndex(['external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
```

- [ ] **Step 4: `bank_statements` migration**

`database/migrations/2026_05_27_000002_create_bank_statements.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5: One row per uploaded bank-statement file. file_hash UNIQUE is the
 * idempotency guard — re-uploading the same file collides and the
 * controller returns the existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_bank_account_id')->constrained('org_bank_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->date('period_start')->nullable();
            $table->decimal('opening_balance', 18, 2);
            $table->decimal('closing_balance', 18, 2);
            $table->char('currency', 3)->default('GHS');
            $table->string('file_hash', 64)->unique();
            $table->string('file_name', 255);
            $table->string('format', 10);
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_bank_account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
```

- [ ] **Step 5: `bank_statement_lines` migration**

`database/migrations/2026_05_27_000003_create_bank_statement_lines.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5: One row per line in the statement file. `amount` is signed:
 * positive = credit, negative = debit. `line_hash` UNIQUE within statement
 * guards against duplicate parsing.
 *
 * matched_type / matched_id are polymorphic (no DB FK) so a line can link to
 * ApPayment, ArReceipt, or JournalEntry (bank-adjustment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->string('line_hash', 64);
            $table->string('matched_type', 50)->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->string('confidence', 10)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_id', 'line_no']);
            $table->unique(['bank_statement_id', 'line_hash']);
            $table->index('reconciled_at');
            $table->index(['matched_type', 'matched_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
```

- [ ] **Step 6: `bank_transaction_matches` migration**

`database/migrations/2026_05_27_000004_create_bank_transaction_matches.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5: Append-only audit log of every link/unlink action. Rows are never
 * deleted; unlinks set unmatched_at / unmatched_by / unmatched_reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transaction_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->restrictOnDelete();
            $table->string('matched_type', 50);
            $table->unsignedBigInteger('matched_id');
            $table->string('confidence', 10);
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('matched_at');
            $table->timestamp('unmatched_at')->nullable();
            $table->foreignId('unmatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unmatched_reason', 500)->nullable();

            $table->index(['matched_type', 'matched_id']);
            $table->index('matched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transaction_matches');
    }
};
```

- [ ] **Step 7: Run test — must PASS**

```
php artisan test --filter=BankReconciliationMigrationsTest
```

Expected: 4 tests pass.

- [ ] **Step 8: Commit**

```
git add database/migrations/2026_05_27_000001_add_external_ref_to_ap_payments.php database/migrations/2026_05_27_000002_create_bank_statements.php database/migrations/2026_05_27_000003_create_bank_statement_lines.php database/migrations/2026_05_27_000004_create_bank_transaction_matches.php tests/Feature/Finance/BankReconciliationMigrationsTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 schema — ap_payments.external_ref + 3 reconciliation tables

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Models (BankStatement, BankStatementLine, BankTransactionMatch) + factories

**Files:**
- Create: `app/Models/BankStatement.php`
- Create: `app/Models/BankStatementLine.php`
- Create: `app/Models/BankTransactionMatch.php`
- Create: `database/factories/BankStatementFactory.php`
- Create: `database/factories/BankStatementLineFactory.php`
- Modify: `app/Models/ApPayment.php` — add `external_ref` to `$fillable`
- Test: `tests/Feature/Finance/F5ModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/F5ModelsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\ApPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
});

it('creates a bank statement with decimal balances + softDeletes', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date'      => '2026-05-31',
        'opening_balance'     => 1000.00,
        'closing_balance'     => 2500.50,
        'currency'            => 'GHS',
        'file_hash'           => str_repeat('a', 64),
        'file_name'           => 'sample.csv',
        'format'              => 'csv',
        'imported_by'         => $this->user->id,
    ]);

    expect((float) $stmt->opening_balance)->toBe(1000.00);
    expect((float) $stmt->closing_balance)->toBe(2500.50);
    expect($stmt->orgBankAccount->id)->toBe($this->bank->id);
    expect($stmt->deleted_at)->toBeNull();
});

it('bank_statements.file_hash is UNIQUE', function () {
    $hash = str_repeat('b', 64);
    BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => $hash, 'file_name' => 'a.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    expect(fn () => BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => $hash, 'file_name' => 'b.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('BankStatementLine.scopeUnreconciled filters reconciled_at IS NULL', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('c', 64), 'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1, 'transaction_date' => '2026-05-30',
        'description' => 'unmatched', 'amount' => 50.00, 'line_hash' => str_repeat('1', 64),
    ]);
    BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 2, 'transaction_date' => '2026-05-30',
        'description' => 'matched', 'amount' => 75.00, 'line_hash' => str_repeat('2', 64),
        'matched_type' => 'X', 'matched_id' => 1, 'reconciled_at' => now(), 'confidence' => 'high',
    ]);

    expect(BankStatementLine::unreconciled()->pluck('line_no')->all())->toBe([1]);
});

it('BankStatementLine line_hash is UNIQUE within statement', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('d', 64), 'file_name' => 'y.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    $hash = str_repeat('e', 64);
    BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1, 'transaction_date' => '2026-05-30',
        'description' => 'first', 'amount' => 50.00, 'line_hash' => $hash,
    ]);

    expect(fn () => BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 2, 'transaction_date' => '2026-05-30',
        'description' => 'dup', 'amount' => 50.00, 'line_hash' => $hash,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('BankTransactionMatch persists with matched_at + supports unmatch', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('f', 64), 'file_name' => 'z.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);
    $line = BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1, 'transaction_date' => '2026-05-30',
        'description' => 'pay', 'amount' => -200.00, 'line_hash' => str_repeat('7', 64),
    ]);

    $match = BankTransactionMatch::create([
        'bank_statement_line_id' => $line->id,
        'matched_type' => 'App\Models\ApPayment',
        'matched_id'   => 1,
        'confidence'   => 'high',
        'matched_by'   => $this->user->id,
        'matched_at'   => now(),
    ]);

    expect($match->fresh()->matched_at)->not->toBeNull();
    expect($match->fresh()->unmatched_at)->toBeNull();

    $match->update(['unmatched_at' => now(), 'unmatched_by' => $this->user->id, 'unmatched_reason' => 'wrong']);
    expect($match->fresh()->unmatched_at)->not->toBeNull();
});

it('ApPayment.external_ref is fillable', function () {
    $vendor = Vendor::create(['code' => 'V1', 'name' => 'V', 'status' => 'active']);
    $pay = ApPayment::create([
        'reference'           => 'AP-X', 'vendor_id' => $vendor->id, 'payment_date' => '2026-05-30',
        'amount' => 100, 'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id, 'external_ref' => 'GCB-TX-9999',
    ]);

    expect($pay->external_ref)->toBe('GCB-TX-9999');
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=F5ModelsTest
```

Expected: 6 failures (models don't exist; `ApPayment.external_ref` is not in `$fillable`).

- [ ] **Step 3: Create `BankStatement` model**

`app/Models/BankStatement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankStatement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bank_statements';

    protected $fillable = [
        'org_bank_account_id', 'statement_date', 'period_start',
        'opening_balance', 'closing_balance', 'currency',
        'file_hash', 'file_name', 'format', 'imported_by',
    ];

    protected $attributes = ['currency' => 'GHS'];

    protected function casts(): array
    {
        return [
            'statement_date'   => 'date',
            'period_start'     => 'date',
            'opening_balance'  => 'decimal:2',
            'closing_balance'  => 'decimal:2',
        ];
    }

    public function orgBankAccount(): BelongsTo
    {
        return $this->belongsTo(OrgBankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function scopeForBankAccount(Builder $q, int $bankAccountId): Builder
    {
        return $q->where('org_bank_account_id', $bankAccountId);
    }
}
```

- [ ] **Step 4: Create `BankStatementLine` model**

`app/Models/BankStatementLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BankStatementLine extends Model
{
    use HasFactory;

    protected $table = 'bank_statement_lines';

    protected $fillable = [
        'bank_statement_id', 'line_no', 'transaction_date', 'value_date',
        'description', 'reference', 'amount', 'running_balance', 'line_hash',
        'matched_type', 'matched_id', 'confidence', 'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'value_date'       => 'date',
            'amount'           => 'decimal:2',
            'running_balance'  => 'decimal:2',
            'reconciled_at'    => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matched(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnreconciled(Builder $q): Builder
    {
        return $q->whereNull('reconciled_at');
    }

    public function scopeReconciled(Builder $q): Builder
    {
        return $q->whereNotNull('reconciled_at');
    }

    public function isDebit(): bool
    {
        return (float) $this->amount < 0;
    }

    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }
}
```

- [ ] **Step 5: Create `BankTransactionMatch` model**

`app/Models/BankTransactionMatch.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransactionMatch extends Model
{
    // Append-only audit log; created_at/updated_at not tracked, matched_at + unmatched_at are explicit.
    public $timestamps = false;

    protected $table = 'bank_transaction_matches';

    protected $fillable = [
        'bank_statement_line_id', 'matched_type', 'matched_id',
        'confidence', 'matched_by', 'matched_at',
        'unmatched_at', 'unmatched_by', 'unmatched_reason',
    ];

    protected function casts(): array
    {
        return [
            'matched_at'   => 'datetime',
            'unmatched_at' => 'datetime',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function unmatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unmatched_by');
    }
}
```

- [ ] **Step 6: Create factories**

`database/factories/BankStatementFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\OrgBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankStatement>
 */
class BankStatementFactory extends Factory
{
    protected $model = BankStatement::class;

    public function definition(): array
    {
        return [
            'org_bank_account_id' => OrgBankAccount::factory(),
            'statement_date'      => now()->format('Y-m-d'),
            'opening_balance'     => 0,
            'closing_balance'     => fake()->randomFloat(2, -1000, 5000),
            'currency'            => 'GHS',
            'file_hash'           => hash('sha256', fake()->unique()->uuid()),
            'file_name'           => fake()->unique()->bothify('stmt-####.csv'),
            'format'              => 'csv',
            'imported_by'         => User::factory(),
        ];
    }
}
```

`database/factories/BankStatementLineFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankStatementLine>
 */
class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    public function definition(): array
    {
        return [
            'bank_statement_id' => BankStatement::factory(),
            'line_no'           => fake()->numberBetween(1, 999),
            'transaction_date'  => now()->format('Y-m-d'),
            'description'       => fake()->sentence(4),
            'reference'         => fake()->optional()->bothify('REF-#########'),
            'amount'            => fake()->randomFloat(2, -1000, 1000),
            'line_hash'         => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
```

- [ ] **Step 7: Modify `ApPayment` model**

Open `app/Models/ApPayment.php`. Find `$fillable`. Append `'external_ref'` to the array (keep existing entries in order; just add the new one).

- [ ] **Step 8: Run test — must PASS**

```
php artisan test --filter=F5ModelsTest
```

Expected: 6 tests pass.

- [ ] **Step 9: Commit**

```
git add app/Models/BankStatement.php app/Models/BankStatementLine.php app/Models/BankTransactionMatch.php database/factories/BankStatementFactory.php database/factories/BankStatementLineFactory.php app/Models/ApPayment.php tests/Feature/Finance/F5ModelsTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 models — BankStatement + BankStatementLine + BankTransactionMatch + ApPayment.external_ref

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: F5 permissions (4 slugs)

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Modify: `app/Models/User.php` (`ROLE_PERMISSIONS` constant only)
- Test: `tests/Feature/Finance/F5PermissionsSeedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/F5PermissionsSeedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the 4 new F5 permission slugs', function () {
    foreach (['reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust'] as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants all 4 F5 perms to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust');
});

it('grants only reconciliation.view to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('reconciliation.view');
    expect($slugs)->not->toContain('reconciliation.import', 'reconciliation.match', 'reconciliation.adjust');
});

it('legacy ROLE_PERMISSIONS lock-step for finance_officer', function () {
    expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain('reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust');
});

it('super_admin gets all F5 perms via wildcard', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    expect($u->hasPermission('reconciliation.adjust'))->toBeTrue();
    expect($u->hasPermission('reconciliation.import'))->toBeTrue();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=F5PermissionsSeedTest
```

- [ ] **Step 3: Add the 4 perms to `RolePermissionSeeder::PERMISSIONS`**

Open `database/seeders/RolePermissionSeeder.php`. Find the F4 block (search for `// ── F4: Finance — Paystack Gateway ──`). The block ends with `'gateway.refund' => ['Finance', 'Refund a processed Paystack payment']`. Immediately after that line, add:

```php
        // ── F5: Finance — Bank Reconciliation ──
        'reconciliation.view'   => ['Finance', 'View bank statements and reconciliation status'],
        'reconciliation.import' => ['Finance', 'Upload bank statement files'],
        'reconciliation.match'  => ['Finance', 'Link statement lines to AP payments / AR receipts'],
        'reconciliation.adjust' => ['Finance', 'Post bank fee or interest adjustment journal entries'],
```

- [ ] **Step 4: Grant to `finance_officer` and `auditor`**

In `RolePermissionSeeder`, find the `'finance_officer'` block. After the F4 entries (`'gateway.view', 'gateway.create',`), add:

```php
            // F5 — Bank Reconciliation
            'reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust',
```

Find the `'auditor'` block. After the F4 view-only slug (`'gateway.view',`), add:

```php
            // F5 — Read-only reconciliation oversight
            'reconciliation.view',
```

- [ ] **Step 5: Mirror in `User::ROLE_PERMISSIONS`**

Open `app/Models/User.php`. Find `public const ROLE_PERMISSIONS`. Append `'reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust'` to the `'finance_officer'` array, and `'reconciliation.view'` to the `'auditor'` array.

- [ ] **Step 6: Run test — must PASS**

```
php artisan test --filter=F5PermissionsSeedTest
```

Expected: 5 tests pass.

- [ ] **Step 7: Commit**

```
git add database/seeders/RolePermissionSeeder.php app/Models/User.php tests/Feature/Finance/F5PermissionsSeedTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 permissions (reconciliation.view/import/match/adjust)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: StatementParser interface + StatementFormatDetector + CSV parser + config/banks.php

**Files:**
- Create: `app/Services/Finance/Statements/StatementParser.php`
- Create: `app/Services/Finance/Statements/StatementFormatDetector.php`
- Create: `app/Services/Finance/Statements/CsvStatementParser.php`
- Create: `app/Exceptions/Finance/StatementParseException.php`
- Create: `config/banks.php`
- Create: `tests/Fixtures/Finance/Statements/gcb-sample.csv`
- Test: `tests/Feature/Finance/CsvStatementParserTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Fixtures/Finance/Statements/gcb-sample.csv`:

```
"GCB Bank Limited"
"Statement Period","2026-05-01","2026-05-31"
"Opening Balance","1000.00"
"Closing Balance","2150.50"
"Transaction Date","Value Date","Description","Reference","Debit","Credit","Balance"
"2026-05-05","2026-05-05","SALARY ADV TO J NADI","GCB-TX-001","500.00","","500.00"
"2026-05-10","2026-05-10","PAYSTACK PAYMENT pst_ref_001","PST-001","","1500.00","2000.00"
"2026-05-20","2026-05-20","BANK CHARGES MAY","CHG-001","49.50","","1950.50"
"2026-05-25","2026-05-25","CASH DEPOSIT","CSH-001","","200.00","2150.50"
```

Create `tests/Feature/Finance/CsvStatementParserTest.php`:

```php
<?php

declare(strict_types=1);

use App\Exceptions\Finance\StatementParseException;
use App\Services\Finance\Statements\CsvStatementParser;

it('parses a GCB CSV statement', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/Finance/Statements/gcb-sample.csv'));
    $parser = new CsvStatementParser('gcb');

    $result = $parser->parse($raw);

    expect($result['statement_date'])->toBe('2026-05-31');
    expect($result['period_start'])->toBe('2026-05-01');
    expect((float) $result['opening_balance'])->toBe(1000.00);
    expect((float) $result['closing_balance'])->toBe(2150.50);
    expect($result['currency'])->toBe('GHS');
    expect($result['lines'])->toHaveCount(4);

    // Debit line: amount must be negative
    expect($result['lines'][0]['transaction_date'])->toBe('2026-05-05');
    expect($result['lines'][0]['description'])->toContain('SALARY ADV');
    expect((float) $result['lines'][0]['amount'])->toBe(-500.00);

    // Credit line: amount must be positive
    expect($result['lines'][1]['transaction_date'])->toBe('2026-05-10');
    expect((float) $result['lines'][1]['amount'])->toBe(1500.00);
    expect($result['lines'][1]['reference'])->toBe('PST-001');
});

it('throws StatementParseException on a non-CSV blob', function () {
    $parser = new CsvStatementParser('gcb');
    expect(fn () => $parser->parse('not a csv'))->toThrow(StatementParseException::class);
});

it('throws StatementParseException on unknown bank key', function () {
    expect(fn () => new CsvStatementParser('unknown-bank'))->toThrow(StatementParseException::class);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=CsvStatementParserTest
```

- [ ] **Step 3: Create the exception class**

`app/Exceptions/Finance/StatementParseException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use RuntimeException;

class StatementParseException extends RuntimeException
{
}
```

- [ ] **Step 4: Create `config/banks.php`**

```php
<?php

declare(strict_types=1);

/**
 * Per-bank CSV column maps for F5 bank reconciliation.
 *
 * Each bank entry describes how to find the header row, what columns map to
 * the normalized statement-line fields, and how to extract the period dates
 * and opening/closing balances.
 *
 * Add a new bank by appending an entry. No code change required.
 */
return [
    'gcb' => [
        'name'            => 'GCB Bank Limited',
        'header_signature'=> 'GCB Bank Limited',
        'period_row'      => 'Statement Period',
        'opening_row'     => 'Opening Balance',
        'closing_row'     => 'Closing Balance',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Transaction Date',
            'value_date'       => 'Value Date',
            'description'      => 'Description',
            'reference'        => 'Reference',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],

    'stanbic' => [
        'name'            => 'Stanbic Bank Ghana',
        'header_signature'=> 'Stanbic',
        'period_row'      => 'Period',
        'opening_row'     => 'Opening',
        'closing_row'     => 'Closing',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Date',
            'value_date'       => 'Value Date',
            'description'      => 'Narration',
            'reference'        => 'Ref',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],

    'gtb' => [
        'name'            => 'Guaranty Trust Bank',
        'header_signature'=> 'GTBank',
        'period_row'      => 'Period',
        'opening_row'     => 'Opening Balance',
        'closing_row'     => 'Closing Balance',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Trans Date',
            'value_date'       => 'Value Date',
            'description'      => 'Description',
            'reference'        => 'Ref',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],

    'ecobank' => [
        'name'            => 'Ecobank Ghana',
        'header_signature'=> 'Ecobank',
        'period_row'      => 'Statement Period',
        'opening_row'     => 'Opening Balance',
        'closing_row'     => 'Closing Balance',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Date',
            'value_date'       => 'Value Date',
            'description'      => 'Description',
            'reference'        => 'Reference',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],
];
```

- [ ] **Step 5: Create the `StatementParser` interface**

`app/Services/Finance/Statements/StatementParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

/**
 * Normalized result shape:
 *
 * [
 *   'period_start'    => '2026-05-01' | null,
 *   'statement_date'  => '2026-05-31',
 *   'opening_balance' => 1000.00,
 *   'closing_balance' => 2150.50,
 *   'currency'        => 'GHS',
 *   'lines'           => [
 *     [
 *       'transaction_date' => '2026-05-05',
 *       'value_date'       => '2026-05-05' | null,
 *       'description'      => 'SALARY ADV TO J NADI',
 *       'reference'        => 'GCB-TX-001' | null,
 *       'amount'           => -500.00,  // signed: positive=credit, negative=debit
 *       'running_balance'  => 500.00 | null,
 *     ],
 *     ...
 *   ],
 * ]
 */
interface StatementParser
{
    public function parse(string $rawContent): array;
}
```

- [ ] **Step 6: Create the `StatementFormatDetector`**

`app/Services/Finance/Statements/StatementFormatDetector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;

class StatementFormatDetector
{
    public function detect(string $fileName, string $rawContent): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return 'csv';
        }
        if ($ext === 'ofx') {
            return 'ofx';
        }
        if (in_array($ext, ['sta', 'mt940', 'mt'], true)) {
            return 'mt940';
        }

        // Magic-bytes fallback
        $head = ltrim(substr($rawContent, 0, 200));
        if (str_starts_with($head, 'OFXHEADER') || str_starts_with($head, '<OFX>')) {
            return 'ofx';
        }
        if (str_starts_with($head, ':20:') || preg_match('/^:\d{2}[A-Z]?:/m', $head)) {
            return 'mt940';
        }

        throw new StatementParseException(
            "Could not detect statement format for '{$fileName}'. Supported: csv, ofx, mt940."
        );
    }
}
```

- [ ] **Step 7: Create the `CsvStatementParser`**

`app/Services/Finance/Statements/CsvStatementParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;

class CsvStatementParser implements StatementParser
{
    private array $bankConfig;

    public function __construct(private readonly string $bankKey)
    {
        $configs = config('banks');
        if (! isset($configs[$bankKey])) {
            throw new StatementParseException("unknown bank key for CSV parser: {$bankKey}");
        }
        $this->bankConfig = $configs[$bankKey];
    }

    public function parse(string $rawContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
        if (count($lines) < 4) {
            throw new StatementParseException('csv too short to be a valid bank statement');
        }

        // Pre-header rows: bank signature, period, opening, closing.
        $periodStart   = null;
        $statementDate = null;
        $opening       = null;
        $closing       = null;
        $headerIdx     = null;

        foreach ($lines as $idx => $row) {
            $cells = str_getcsv($row);
            $first = $cells[0] ?? '';

            if (str_contains($first, $this->bankConfig['period_row']) && count($cells) >= 3) {
                $periodStart   = $cells[1] ?? null;
                $statementDate = $cells[2] ?? null;
            } elseif (str_contains($first, $this->bankConfig['opening_row']) && count($cells) >= 2) {
                $opening = (float) $cells[1];
            } elseif (str_contains($first, $this->bankConfig['closing_row']) && count($cells) >= 2) {
                $closing = (float) $cells[1];
            } elseif ($first === $this->bankConfig['columns']['transaction_date']) {
                $headerIdx = $idx;
                break;
            }
        }

        if ($headerIdx === null || $statementDate === null || $opening === null || $closing === null) {
            throw new StatementParseException('csv missing required header rows (period / balances / column header)');
        }

        // Column index map from the header row.
        $headerCells = str_getcsv($lines[$headerIdx]);
        $colMap = array_flip($headerCells);

        $cols = $this->bankConfig['columns'];
        foreach (['transaction_date', 'description', 'debit', 'credit'] as $required) {
            if (! isset($colMap[$cols[$required]])) {
                throw new StatementParseException("csv missing required column: {$cols[$required]}");
            }
        }

        $resultLines = [];
        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') continue;
            $cells = str_getcsv($lines[$i]);

            $debit  = (float) ($cells[$colMap[$cols['debit']]]  ?? 0);
            $credit = (float) ($cells[$colMap[$cols['credit']]] ?? 0);
            $signed = $credit - $debit;

            $resultLines[] = [
                'transaction_date' => $cells[$colMap[$cols['transaction_date']]] ?? null,
                'value_date'       => isset($colMap[$cols['value_date']])  ? ($cells[$colMap[$cols['value_date']]]  ?? null) : null,
                'description'      => $cells[$colMap[$cols['description']]] ?? '',
                'reference'        => isset($colMap[$cols['reference']])    ? ($cells[$colMap[$cols['reference']]]   ?: null) : null,
                'amount'           => round($signed, 2),
                'running_balance'  => isset($colMap[$cols['running_balance']]) ? ((float) ($cells[$colMap[$cols['running_balance']]] ?? 0)) : null,
            ];
        }

        return [
            'period_start'    => $periodStart,
            'statement_date'  => $statementDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'currency'        => $this->bankConfig['currency'],
            'lines'           => $resultLines,
        ];
    }
}
```

- [ ] **Step 8: Run test — must PASS**

```
php artisan test --filter=CsvStatementParserTest
```

Expected: 3 tests pass.

- [ ] **Step 9: Commit**

```
git add app/Services/Finance/Statements/StatementParser.php app/Services/Finance/Statements/StatementFormatDetector.php app/Services/Finance/Statements/CsvStatementParser.php app/Exceptions/Finance/StatementParseException.php config/banks.php tests/Fixtures/Finance/Statements/gcb-sample.csv tests/Feature/Finance/CsvStatementParserTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 CSV parser + per-bank column maps + format detector

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: OFX statement parser

**Files:**
- Create: `app/Services/Finance/Statements/OfxStatementParser.php`
- Create: `tests/Fixtures/Finance/Statements/sample.ofx`
- Test: `tests/Feature/Finance/OfxStatementParserTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Fixtures/Finance/Statements/sample.ofx`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<?OFX OFXHEADER="200" VERSION="202" SECURITY="NONE" OLDFILEUID="NONE" NEWFILEUID="NONE"?>
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <CURDEF>GHS</CURDEF>
        <BANKACCTFROM>
          <BANKID>030100</BANKID>
          <ACCTID>1234567890</ACCTID>
          <ACCTTYPE>CHECKING</ACCTTYPE>
        </BANKACCTFROM>
        <BANKTRANLIST>
          <DTSTART>20260501000000</DTSTART>
          <DTEND>20260531000000</DTEND>
          <STMTTRN>
            <TRNTYPE>DEBIT</TRNTYPE>
            <DTPOSTED>20260505000000</DTPOSTED>
            <TRNAMT>-500.00</TRNAMT>
            <FITID>GCB-TX-001</FITID>
            <NAME>SALARY ADV</NAME>
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>CREDIT</TRNTYPE>
            <DTPOSTED>20260510000000</DTPOSTED>
            <TRNAMT>1500.00</TRNAMT>
            <FITID>PST-001</FITID>
            <NAME>PAYSTACK pst_ref_001</NAME>
          </STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL>
          <BALAMT>2150.50</BALAMT>
          <DTASOF>20260531000000</DTASOF>
        </LEDGERBAL>
        <AVAILBAL>
          <BALAMT>1000.00</BALAMT>
          <DTASOF>20260501000000</DTASOF>
        </AVAILBAL>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
```

Create `tests/Feature/Finance/OfxStatementParserTest.php`:

```php
<?php

declare(strict_types=1);

use App\Exceptions\Finance\StatementParseException;
use App\Services\Finance\Statements\OfxStatementParser;

it('parses an OFX 2.x statement', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/Finance/Statements/sample.ofx'));
    $parser = new OfxStatementParser();

    $result = $parser->parse($raw);

    expect($result['statement_date'])->toBe('2026-05-31');
    expect($result['period_start'])->toBe('2026-05-01');
    expect((float) $result['closing_balance'])->toBe(2150.50);
    expect($result['currency'])->toBe('GHS');
    expect($result['lines'])->toHaveCount(2);

    expect((float) $result['lines'][0]['amount'])->toBe(-500.00);
    expect($result['lines'][0]['reference'])->toBe('GCB-TX-001');
    expect($result['lines'][0]['description'])->toBe('SALARY ADV');

    expect((float) $result['lines'][1]['amount'])->toBe(1500.00);
    expect($result['lines'][1]['reference'])->toBe('PST-001');
});

it('throws StatementParseException on malformed OFX', function () {
    $parser = new OfxStatementParser();
    expect(fn () => $parser->parse('not ofx'))->toThrow(StatementParseException::class);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=OfxStatementParserTest
```

- [ ] **Step 3: Create the `OfxStatementParser`**

`app/Services/Finance/Statements/OfxStatementParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;
use SimpleXMLElement;
use Throwable;

class OfxStatementParser implements StatementParser
{
    public function parse(string $rawContent): array
    {
        // Strip the OFX processing instruction header, which SimpleXML doesn't
        // handle natively.
        $xml = preg_replace('/<\?OFX[^>]*\?>/', '', $rawContent);
        $xml = trim($xml);

        try {
            $doc = new SimpleXMLElement($xml, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable $e) {
            throw new StatementParseException('ofx is not valid XML: ' . $e->getMessage(), 0, $e);
        }

        $stmt = $doc->BANKMSGSRSV1->STMTTRNRS->STMTRS ?? null;
        if ($stmt === null) {
            throw new StatementParseException('ofx missing BANKMSGSRSV1/STMTTRNRS/STMTRS node');
        }

        $currency = (string) ($stmt->CURDEF ?? 'GHS');

        $tranList = $stmt->BANKTRANLIST ?? null;
        if ($tranList === null) {
            throw new StatementParseException('ofx missing BANKTRANLIST node');
        }

        $periodStart   = $this->formatOfxDate((string) ($tranList->DTSTART ?? ''));
        $statementDate = $this->formatOfxDate((string) ($tranList->DTEND ?? ''));

        $closing = (float) ($stmt->LEDGERBAL->BALAMT ?? 0);
        $opening = (float) ($stmt->AVAILBAL->BALAMT ?? 0);

        $lines = [];
        foreach ($tranList->STMTTRN as $trn) {
            $lines[] = [
                'transaction_date' => $this->formatOfxDate((string) $trn->DTPOSTED),
                'value_date'       => $this->formatOfxDate((string) $trn->DTPOSTED),
                'description'      => trim((string) ($trn->NAME ?? $trn->MEMO ?? '')),
                'reference'        => trim((string) ($trn->FITID ?? '')) ?: null,
                'amount'           => round((float) $trn->TRNAMT, 2),
                'running_balance'  => null,
            ];
        }

        return [
            'period_start'    => $periodStart,
            'statement_date'  => $statementDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'currency'        => $currency,
            'lines'           => $lines,
        ];
    }

    private function formatOfxDate(string $raw): ?string
    {
        if ($raw === '') return null;
        // OFX dates: YYYYMMDD or YYYYMMDDHHMMSS optional .XXX[TZ]
        $date = substr($raw, 0, 8);
        if (strlen($date) !== 8 || ! ctype_digit($date)) return null;
        return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=OfxStatementParserTest
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/Statements/OfxStatementParser.php tests/Fixtures/Finance/Statements/sample.ofx tests/Feature/Finance/OfxStatementParserTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 OFX 2.x statement parser

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: MT940 statement parser

**Files:**
- Create: `app/Services/Finance/Statements/Mt940StatementParser.php`
- Create: `tests/Fixtures/Finance/Statements/sample.mt940`
- Test: `tests/Feature/Finance/Mt940StatementParserTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Fixtures/Finance/Statements/sample.mt940` (CRLF line endings — write each line and the file will use the OS default which is fine for tests):

```
:20:STMT2026053101
:25:030100/1234567890
:28C:00031/001
:60F:C260501GHS1000,00
:61:260505D500,00NTRFGCB-TX-001//
:86:SALARY ADV TO J NADI
:61:260510C1500,00NTRFPST-001//
:86:PAYSTACK pst_ref_001
:62F:C260531GHS2150,50
-
```

Create `tests/Feature/Finance/Mt940StatementParserTest.php`:

```php
<?php

declare(strict_types=1);

use App\Exceptions\Finance\StatementParseException;
use App\Services\Finance\Statements\Mt940StatementParser;

it('parses an MT940 statement with debit and credit lines', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/Finance/Statements/sample.mt940'));
    $parser = new Mt940StatementParser();

    $result = $parser->parse($raw);

    expect($result['statement_date'])->toBe('2026-05-31');
    expect((float) $result['opening_balance'])->toBe(1000.00);
    expect((float) $result['closing_balance'])->toBe(2150.50);
    expect($result['currency'])->toBe('GHS');
    expect($result['lines'])->toHaveCount(2);

    expect((float) $result['lines'][0]['amount'])->toBe(-500.00);
    expect($result['lines'][0]['transaction_date'])->toBe('2026-05-05');
    expect($result['lines'][0]['description'])->toBe('SALARY ADV TO J NADI');

    expect((float) $result['lines'][1]['amount'])->toBe(1500.00);
    expect($result['lines'][1]['transaction_date'])->toBe('2026-05-10');
});

it('throws StatementParseException on garbage input', function () {
    $parser = new Mt940StatementParser();
    expect(fn () => $parser->parse('totally not mt940'))->toThrow(StatementParseException::class);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=Mt940StatementParserTest
```

- [ ] **Step 3: Create the `Mt940StatementParser`**

`app/Services/Finance/Statements/Mt940StatementParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;

class Mt940StatementParser implements StatementParser
{
    public function parse(string $rawContent): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $rawContent);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($l) => $l !== '' && $l !== '-'));

        if (! preg_grep('/^:\d{2}[A-Z]?:/', $lines)) {
            throw new StatementParseException('mt940 has no recognized tag lines');
        }

        $opening = null;
        $closing = null;
        $currency = 'GHS';
        $statementDate = null;
        $tranLines = [];

        $i = 0;
        while ($i < count($lines)) {
            $line = $lines[$i];

            if (preg_match('/^:60[FM]:([CD])(\d{6})([A-Z]{3})([\d,\.]+)/', $line, $m)) {
                $sign = $m[1] === 'C' ? 1 : -1;
                $opening = $sign * $this->mtAmount($m[4]);
                $currency = $m[3];
            } elseif (preg_match('/^:62[FM]:([CD])(\d{6})([A-Z]{3})([\d,\.]+)/', $line, $m)) {
                $sign = $m[1] === 'C' ? 1 : -1;
                $closing = $sign * $this->mtAmount($m[4]);
                $statementDate = $this->mtDate($m[2]);
                $currency = $m[3];
            } elseif (preg_match('/^:61:(\d{6})(\d{4})?([CD])R?([\d,\.]+)([A-Z0-9]{4})([^\/]*)\/?\/?(.*)$/', $line, $m)) {
                $txDate = $this->mtDate($m[1]);
                $valueDate = $m[2] !== '' ? $this->mtDateValue($m[1], $m[2]) : $txDate;
                $sign = $m[3] === 'C' ? 1 : -1;
                $amount = round($sign * $this->mtAmount($m[4]), 2);
                $reference = trim($m[6]) ?: null;
                $description = '';

                if (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], ':86:')) {
                    $description = trim(substr($lines[$i + 1], 4));
                    $i++;
                }

                $tranLines[] = [
                    'transaction_date' => $txDate,
                    'value_date'       => $valueDate,
                    'description'      => $description,
                    'reference'        => $reference,
                    'amount'           => $amount,
                    'running_balance'  => null,
                ];
            }
            $i++;
        }

        if ($opening === null || $closing === null || $statementDate === null) {
            throw new StatementParseException('mt940 missing :60F: opening, :62F: closing, or statement date');
        }

        return [
            'period_start'    => null,
            'statement_date'  => $statementDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'currency'        => $currency,
            'lines'           => $tranLines,
        ];
    }

    private function mtAmount(string $raw): float
    {
        // MT940 uses comma as decimal separator
        return (float) str_replace(',', '.', $raw);
    }

    private function mtDate(string $yymmdd): string
    {
        $yy = (int) substr($yymmdd, 0, 2);
        $century = $yy >= 80 ? 1900 : 2000;
        return ($century + $yy) . '-' . substr($yymmdd, 2, 2) . '-' . substr($yymmdd, 4, 2);
    }

    private function mtDateValue(string $txDateYymmdd, string $valueMmdd): string
    {
        $yy = (int) substr($txDateYymmdd, 0, 2);
        $century = $yy >= 80 ? 1900 : 2000;
        return ($century + $yy) . '-' . substr($valueMmdd, 0, 2) . '-' . substr($valueMmdd, 2, 2);
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=Mt940StatementParserTest
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/Statements/Mt940StatementParser.php tests/Fixtures/Finance/Statements/sample.mt940 tests/Feature/Finance/Mt940StatementParserTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 MT940 statement parser

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: StatementImportService

**Files:**
- Create: `app/Services/Finance/StatementImportService.php`
- Test: `tests/Feature/Finance/StatementImportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/StatementImportServiceTest.php`:

```php
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
    // Force the bank's currency to USD; CSV says GHS.
    $this->bank->update(['currency' => 'USD']);

    $file = new UploadedFile($this->fixturePath, 'gcb-sample.csv', 'text/csv', null, true);

    expect(fn () => $this->svc->import($file, $this->bank, $this->user, 'gcb'))
        ->toThrow(\DomainException::class, 'currency');

    expect(BankStatement::count())->toBe(0);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=StatementImportServiceTest
```

- [ ] **Step 3: Create the service**

`app/Services/Finance/StatementImportService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\Finance\StatementParseException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\Statements\CsvStatementParser;
use App\Services\Finance\Statements\Mt940StatementParser;
use App\Services\Finance\Statements\OfxStatementParser;
use App\Services\Finance\Statements\StatementFormatDetector;
use App\Services\Finance\Statements\StatementParser;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StatementImportService
{
    public function __construct(private readonly StatementFormatDetector $detector)
    {
    }

    public function import(
        UploadedFile $file,
        OrgBankAccount $bank,
        User $importer,
        ?string $bankKey = null,
    ): BankStatement {
        $raw = file_get_contents($file->getRealPath()) ?: '';
        if ($raw === '') {
            throw new DomainException('uploaded file is empty');
        }

        $fileHash = hash('sha256', $raw);

        // Idempotency: same file uploaded twice → return existing.
        $existing = BankStatement::where('file_hash', $fileHash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $format = $this->detector->detect($file->getClientOriginalName(), $raw);
        $parser = $this->parserFor($format, $bankKey);
        $parsed = $parser->parse($raw);

        if ($parsed['currency'] !== ($bank->currency ?? 'GHS')) {
            throw new DomainException(sprintf(
                'currency mismatch: file is %s but bank account is %s',
                $parsed['currency'], $bank->currency ?? 'GHS',
            ));
        }

        return DB::transaction(function () use ($file, $fileHash, $format, $parsed, $bank, $importer) {
            $stmt = BankStatement::create([
                'org_bank_account_id' => $bank->id,
                'statement_date'      => $parsed['statement_date'],
                'period_start'        => $parsed['period_start'],
                'opening_balance'     => $parsed['opening_balance'],
                'closing_balance'     => $parsed['closing_balance'],
                'currency'            => $parsed['currency'],
                'file_hash'           => $fileHash,
                'file_name'           => $file->getClientOriginalName(),
                'format'              => $format,
                'imported_by'         => $importer->id,
            ]);

            $lineNo = 0;
            foreach ($parsed['lines'] as $line) {
                $lineNo++;
                $lineHash = hash('sha256', sprintf('%s|%.2f|%s|%s',
                    $line['transaction_date'] ?? '',
                    (float) ($line['amount'] ?? 0),
                    $line['description'] ?? '',
                    $line['reference'] ?? '',
                ));

                BankStatementLine::create([
                    'bank_statement_id' => $stmt->id,
                    'line_no'           => $lineNo,
                    'transaction_date'  => $line['transaction_date'],
                    'value_date'        => $line['value_date'] ?? null,
                    'description'       => $line['description'] ?? '',
                    'reference'         => $line['reference'] ?? null,
                    'amount'            => $line['amount'] ?? 0,
                    'running_balance'   => $line['running_balance'] ?? null,
                    'line_hash'         => $lineHash,
                ]);
            }

            return $stmt->fresh();
        });
    }

    private function parserFor(string $format, ?string $bankKey): StatementParser
    {
        return match ($format) {
            'csv'   => new CsvStatementParser($bankKey ?? 'gcb'),
            'ofx'   => new OfxStatementParser(),
            'mt940' => new Mt940StatementParser(),
            default => throw new StatementParseException("unknown statement format: {$format}"),
        };
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=StatementImportServiceTest
```

Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/StatementImportService.php tests/Feature/Finance/StatementImportServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): StatementImportService — format detect + parse + persist with file_hash idempotency

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: ReconciliationMatcher (3-tier matching)

**Files:**
- Create: `app/Services/Finance/ReconciliationMatcher.php`
- Test: `tests/Feature/Finance/ReconciliationMatcherTest.php`

> NOTE: This task introduces `ReconciliationService::link()`. Task 10 implements that service, but Task 9 needs to call it. To keep the TDD loop honest, create a minimal `ReconciliationService` stub in Task 9 that records the match row + flips the line; Task 10 expands it. The matcher's tests cover the matching logic; the service's tests (Task 10) cover the link/unlink mechanics.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ReconciliationMatcherTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\ApPayment;
use App\Models\ArReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Customer;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ReconciliationMatcher;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->matcher = app(ReconciliationMatcher::class);

    $this->stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('a', 64),
        'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);
});

function mkLine(int $stmtId, int $no, string $date, float $amount, string $desc, ?string $ref = null): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $stmtId, 'line_no' => $no,
        'transaction_date' => $date,
        'description' => $desc, 'reference' => $ref,
        'amount' => $amount,
        'line_hash' => hash('sha256', "{$no}|{$amount}|{$desc}|{$ref}"),
    ]);
}

it('Tier 1: matches a credit line to AR receipt via external_ref', function () {
    $cust = Customer::create(['code' => 'C1', 'name' => 'C', 'status' => 'active']);
    $receipt = ArReceipt::create([
        'reference' => 'AR-X', 'customer_id' => $cust->id,
        'receipt_date' => '2026-05-10', 'amount' => 1500,
        'currency' => 'GHS', 'org_bank_account_id' => $this->bank->id,
        'external_ref' => 'PST-001',
        'status' => 'posted', 'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-10', 1500.00, 'PAYSTACK pst_ref', 'PST-001');

    $counts = $this->matcher->matchUnreconciled($this->stmt);

    expect($counts['high'])->toBe(1);
    expect($line->fresh()->matched_type)->toBe(ArReceipt::class);
    expect($line->fresh()->matched_id)->toBe($receipt->id);
    expect($line->fresh()->confidence)->toBe('high');
});

it('Tier 2: matches a debit line to AP payment via amount + date + reference-in-description', function () {
    $vendor = Vendor::create(['code' => 'V1', 'name' => 'V', 'status' => 'active']);
    $pay = ApPayment::create([
        'reference' => 'AP-2026-000001', 'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-05', 'amount' => 500,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-05', -500.00, 'SALARY ADV REF AP-2026-000001');

    $counts = $this->matcher->matchUnreconciled($this->stmt);

    expect($counts['medium'])->toBe(1);
    expect($line->fresh()->matched_id)->toBe($pay->id);
    expect($line->fresh()->confidence)->toBe('medium');
});

it('Tier 3 single candidate: amount + date match with no reference', function () {
    $vendor = Vendor::create(['code' => 'V2', 'name' => 'V', 'status' => 'active']);
    ApPayment::create([
        'reference' => 'AP-X', 'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-20', 'amount' => 49.50,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-20', -49.50, 'BANK CHARGES MAY');

    $counts = $this->matcher->matchUnreconciled($this->stmt);

    expect($counts['low'])->toBe(1);
    expect($line->fresh()->confidence)->toBe('low');
});

it('Tier 3 multi-candidate: leaves matched_id null but sets confidence=low', function () {
    $vendor = Vendor::create(['code' => 'V3', 'name' => 'V', 'status' => 'active']);
    for ($i = 1; $i <= 2; $i++) {
        ApPayment::create([
            'reference' => "AP-{$i}", 'vendor_id' => $vendor->id,
            'payment_date' => '2026-05-20', 'amount' => 100.00,
            'org_bank_account_id' => $this->bank->id,
            'created_by' => $this->user->id,
        ]);
    }

    $line = mkLine($this->stmt->id, 1, '2026-05-20', -100.00, 'PAYMENT');

    $this->matcher->matchUnreconciled($this->stmt);

    expect($line->fresh()->matched_id)->toBeNull();
    expect($line->fresh()->confidence)->toBe('low');
});

it('credit lines never match AP payments', function () {
    $vendor = Vendor::create(['code' => 'V4', 'name' => 'V', 'status' => 'active']);
    ApPayment::create([
        'reference' => 'AP-Y', 'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-15', 'amount' => 300.00,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-15', 300.00, 'SOMETHING');

    $this->matcher->matchUnreconciled($this->stmt);

    expect($line->fresh()->matched_id)->toBeNull();
});

it('idempotent: re-running on the same statement leaves already-reconciled lines alone', function () {
    $cust = Customer::create(['code' => 'C2', 'name' => 'C', 'status' => 'active']);
    $r = ArReceipt::create([
        'reference' => 'AR-Z', 'customer_id' => $cust->id,
        'receipt_date' => '2026-05-10', 'amount' => 200, 'currency' => 'GHS',
        'org_bank_account_id' => $this->bank->id, 'external_ref' => 'X',
        'status' => 'posted', 'created_by' => $this->user->id,
    ]);
    $line = mkLine($this->stmt->id, 1, '2026-05-10', 200.00, 'DEPOSIT', 'X');

    $this->matcher->matchUnreconciled($this->stmt);
    $firstReconciledAt = $line->fresh()->reconciled_at;

    $this->matcher->matchUnreconciled($this->stmt);

    expect($line->fresh()->reconciled_at?->toDateTimeString())->toBe($firstReconciledAt?->toDateTimeString());
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=ReconciliationMatcherTest
```

- [ ] **Step 3: Create a minimal `ReconciliationService` stub**

`app/Services/Finance/ReconciliationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function link(BankStatementLine $line, Model $target, User $user, string $confidence): BankTransactionMatch
    {
        if ($line->reconciled_at !== null) {
            throw new DomainException("line {$line->id} is already reconciled");
        }

        return DB::transaction(function () use ($line, $target, $user, $confidence) {
            $line->update([
                'matched_type'  => get_class($target),
                'matched_id'    => $target->getKey(),
                'confidence'    => $confidence,
                'reconciled_at' => now(),
            ]);

            // Back-populate ap_payments.external_ref so future Tier 1 matches work.
            if ($target instanceof \App\Models\ApPayment && empty($target->external_ref) && ! empty($line->reference)) {
                $target->update(['external_ref' => $line->reference]);
            }

            return BankTransactionMatch::create([
                'bank_statement_line_id' => $line->id,
                'matched_type'           => get_class($target),
                'matched_id'             => $target->getKey(),
                'confidence'             => $confidence,
                'matched_by'             => $user->id,
                'matched_at'             => now(),
            ]);
        });
    }
}
```

- [ ] **Step 4: Create the `ReconciliationMatcher`**

`app/Services/Finance/ReconciliationMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\ApPayment;
use App\Models\ArReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementLine;

class ReconciliationMatcher
{
    public function __construct(private readonly ReconciliationService $reconciliation)
    {
    }

    /**
     * @return array{high:int, medium:int, low:int, unmatched:int}
     */
    public function matchUnreconciled(BankStatement $statement): array
    {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0, 'unmatched' => 0];

        $lines = $statement->lines()->unreconciled()->orderBy('line_no')->get();
        $importer = $statement->importer;

        foreach ($lines as $line) {
            $matched = $this->tryTier1($line, $statement, $importer);
            if ($matched) { $counts['high']++; continue; }

            $matched = $this->tryTier2($line, $statement, $importer);
            if ($matched) { $counts['medium']++; continue; }

            $tier3 = $this->tryTier3($line, $statement, $importer);
            if ($tier3 === 'linked') { $counts['low']++; continue; }
            if ($tier3 === 'ambiguous') { $line->update(['confidence' => 'low']); $counts['low']++; continue; }

            $counts['unmatched']++;
        }

        return $counts;
    }

    private function tryTier1(BankStatementLine $line, BankStatement $stmt, $importer): bool
    {
        $ref = $line->reference;
        $desc = $line->description;

        if ($line->isCredit()) {
            $candidates = ArReceipt::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereNotNull('external_ref')
                ->where(function ($q) use ($ref, $desc) {
                    if ($ref) $q->where('external_ref', $ref);
                    if ($desc) $q->orWhereRaw('? LIKE CONCAT(\'%\', external_ref, \'%\')', [$desc]);
                })
                ->whereDoesntHave('line', function ($q) {
                    // ArReceipt does NOT have a 'line' relation — this guard is matcher-internal
                });
            // Restrict to receipts not already linked to any line.
            $candidates = $candidates->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ArReceipt::class)
                    ->whereNotNull('matched_id');
            });
            $matches = $candidates->get();
            if ($matches->count() === 1) {
                $this->reconciliation->link($line, $matches->first(), $importer, 'high');
                return true;
            }
        } else {
            $candidates = ApPayment::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereNotNull('external_ref')
                ->where(function ($q) use ($ref, $desc) {
                    if ($ref) $q->where('external_ref', $ref);
                    if ($desc) $q->orWhereRaw('? LIKE CONCAT(\'%\', external_ref, \'%\')', [$desc]);
                })
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ApPayment::class)
                        ->whereNotNull('matched_id');
                });
            $matches = $candidates->get();
            if ($matches->count() === 1) {
                $this->reconciliation->link($line, $matches->first(), $importer, 'high');
                return true;
            }
        }

        return false;
    }

    private function tryTier2(BankStatementLine $line, BankStatement $stmt, $importer): bool
    {
        $absAmount = abs((float) $line->amount);
        $dateFrom  = $line->transaction_date->copy()->subDays(2);
        $dateTo    = $line->transaction_date->copy()->addDays(2);

        if ($line->isCredit()) {
            $candidates = ArReceipt::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
                ->whereBetween('receipt_date', [$dateFrom, $dateTo])
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ArReceipt::class)->whereNotNull('matched_id');
                })
                ->get();

            $matched = $candidates->filter(fn ($r) => str_contains($line->description, $r->reference));
            if ($matched->count() === 1) {
                $this->reconciliation->link($line, $matched->first(), $importer, 'medium');
                return true;
            }
        } else {
            $candidates = ApPayment::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
                ->whereBetween('payment_date', [$dateFrom, $dateTo])
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ApPayment::class)->whereNotNull('matched_id');
                })
                ->get();

            $matched = $candidates->filter(fn ($p) => str_contains($line->description, $p->reference));
            if ($matched->count() === 1) {
                $this->reconciliation->link($line, $matched->first(), $importer, 'medium');
                return true;
            }
        }

        return false;
    }

    /**
     * @return 'linked' | 'ambiguous' | 'none'
     */
    private function tryTier3(BankStatementLine $line, BankStatement $stmt, $importer): string
    {
        $absAmount = abs((float) $line->amount);
        $dateFrom  = $line->transaction_date->copy()->subDays(2);
        $dateTo    = $line->transaction_date->copy()->addDays(2);

        if ($line->isCredit()) {
            $candidates = ArReceipt::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
                ->whereBetween('receipt_date', [$dateFrom, $dateTo])
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ArReceipt::class)->whereNotNull('matched_id');
                })
                ->get();

            if ($candidates->count() === 1) {
                $this->reconciliation->link($line, $candidates->first(), $importer, 'low');
                return 'linked';
            }
            return $candidates->count() > 1 ? 'ambiguous' : 'none';
        }

        $candidates = ApPayment::where('org_bank_account_id', $stmt->org_bank_account_id)
            ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
            ->whereBetween('payment_date', [$dateFrom, $dateTo])
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ApPayment::class)->whereNotNull('matched_id');
            })
            ->get();

        if ($candidates->count() === 1) {
            $this->reconciliation->link($line, $candidates->first(), $importer, 'low');
            return 'linked';
        }
        return $candidates->count() > 1 ? 'ambiguous' : 'none';
    }
}
```

- [ ] **Step 5: Run test — must PASS**

```
php artisan test --filter=ReconciliationMatcherTest
```

Expected: 6 tests pass.

- [ ] **Step 6: Commit**

```
git add app/Services/Finance/ReconciliationService.php app/Services/Finance/ReconciliationMatcher.php tests/Feature/Finance/ReconciliationMatcherTest.php
git commit -m "$(cat <<'EOF'
feat(finance): ReconciliationMatcher — 3-tier matching against AP payments + AR receipts

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: ReconciliationService — full `link()` / `unlink()` / `acceptSuggestion()`

**Files:**
- Modify: `app/Services/Finance/ReconciliationService.php` (expand the Task 9 stub)
- Test: `tests/Feature/Finance/ReconciliationServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ReconciliationServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\ApPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->svc = app(ReconciliationService::class);

    $this->stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('s', 64),
        'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    $this->vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $this->pay = ApPayment::create([
        'reference' => 'AP-1', 'vendor_id' => $this->vendor->id,
        'payment_date' => '2026-05-05', 'amount' => 500,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);
    $this->line = BankStatementLine::create([
        'bank_statement_id' => $this->stmt->id, 'line_no' => 1,
        'transaction_date' => '2026-05-05',
        'description' => 'PAY V', 'reference' => 'BANK-REF-001',
        'amount' => -500.00, 'line_hash' => str_repeat('h', 64),
    ]);
});

it('link() updates the line, appends the match row, and back-populates external_ref', function () {
    $match = $this->svc->link($this->line, $this->pay, $this->user, 'manual');

    expect($this->line->fresh()->reconciled_at)->not->toBeNull();
    expect($this->line->fresh()->matched_id)->toBe($this->pay->id);
    expect($this->line->fresh()->confidence)->toBe('manual');

    expect($this->pay->fresh()->external_ref)->toBe('BANK-REF-001');

    expect($match)->toBeInstanceOf(BankTransactionMatch::class);
    expect(BankTransactionMatch::count())->toBe(1);
});

it('link() refuses an already-reconciled line', function () {
    $this->svc->link($this->line, $this->pay, $this->user, 'manual');

    expect(fn () => $this->svc->link($this->line->fresh(), $this->pay, $this->user, 'manual'))
        ->toThrow(\DomainException::class, 'already reconciled');
});

it('unlink() clears the line + stamps unmatched_at without deleting the audit row', function () {
    $this->svc->link($this->line, $this->pay, $this->user, 'manual');
    $this->svc->unlink($this->line->fresh(), $this->user, 'operator error');

    expect($this->line->fresh()->reconciled_at)->toBeNull();
    expect($this->line->fresh()->matched_id)->toBeNull();
    expect($this->line->fresh()->matched_type)->toBeNull();

    expect(BankTransactionMatch::count())->toBe(1);
    $match = BankTransactionMatch::first();
    expect($match->unmatched_at)->not->toBeNull();
    expect($match->unmatched_reason)->toBe('operator error');
});

it('unlink() does NOT clear back-populated external_ref', function () {
    $this->svc->link($this->line, $this->pay, $this->user, 'manual');
    $this->svc->unlink($this->line->fresh(), $this->user, 'operator error');

    expect($this->pay->fresh()->external_ref)->toBe('BANK-REF-001');
});
```

- [ ] **Step 2: Run test — must FAIL on `unlink()`**

```
php artisan test --filter=ReconciliationServiceTest
```

Expected: 2 of 4 fail (the unlink-related ones), because the Task-9 stub doesn't implement `unlink()`.

- [ ] **Step 3: Expand the service to include `unlink()` + `acceptSuggestion()`**

Replace `app/Services/Finance/ReconciliationService.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function link(BankStatementLine $line, Model $target, User $user, string $confidence): BankTransactionMatch
    {
        if ($line->reconciled_at !== null) {
            throw new DomainException("line {$line->id} is already reconciled");
        }

        return DB::transaction(function () use ($line, $target, $user, $confidence) {
            $line->update([
                'matched_type'  => get_class($target),
                'matched_id'    => $target->getKey(),
                'confidence'    => $confidence,
                'reconciled_at' => now(),
            ]);

            if ($target instanceof \App\Models\ApPayment && empty($target->external_ref) && ! empty($line->reference)) {
                $target->update(['external_ref' => $line->reference]);
            }

            return BankTransactionMatch::create([
                'bank_statement_line_id' => $line->id,
                'matched_type'           => get_class($target),
                'matched_id'             => $target->getKey(),
                'confidence'             => $confidence,
                'matched_by'             => $user->id,
                'matched_at'             => now(),
            ]);
        });
    }

    public function unlink(BankStatementLine $line, User $user, string $reason): void
    {
        if ($line->reconciled_at === null) {
            throw new DomainException("line {$line->id} is not currently reconciled");
        }

        DB::transaction(function () use ($line, $user, $reason) {
            // Find the live (un-unmatched) match row and stamp it.
            $match = BankTransactionMatch::where('bank_statement_line_id', $line->id)
                ->whereNull('unmatched_at')
                ->orderByDesc('id')
                ->first();

            if ($match !== null) {
                $match->update([
                    'unmatched_at'     => now(),
                    'unmatched_by'     => $user->id,
                    'unmatched_reason' => $reason,
                ]);
            }

            $line->update([
                'matched_type'  => null,
                'matched_id'    => null,
                'confidence'    => null,
                'reconciled_at' => null,
            ]);
        });
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=ReconciliationServiceTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/ReconciliationService.php tests/Feature/Finance/ReconciliationServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): ReconciliationService — link/unlink with append-only audit log

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: BankAdjustmentService

**Files:**
- Create: `app/Services/Finance/BankAdjustmentService.php`
- Test: `tests/Feature/Finance/BankAdjustmentServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/BankAdjustmentServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\BankAdjustmentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->svc = app(BankAdjustmentService::class);

    $this->stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('z', 64),
        'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);
});

function mkAdjLine(int $stmtId, float $amount, string $desc, int $no = 1): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $stmtId, 'line_no' => $no,
        'transaction_date' => '2026-05-20',
        'description' => $desc, 'amount' => $amount,
        'line_hash' => hash('sha256', "{$no}|{$amount}|{$desc}"),
    ]);
}

it('posts a bank fee adjustment via JournalPostingService', function () {
    $line = mkAdjLine($this->stmt->id, -49.50, 'BANK CHARGES MAY');
    $expense = GlAccount::where('code', '5400')->first()
        ?? GlAccount::create(['code' => '5400', 'name' => 'Bank Charges', 'type' => 'expense']);
    \App\Models\GlAccountBalance::firstOrCreate(['gl_account_id' => $expense->id], ['balance' => 0]);

    $je = $this->svc->postAdjustment($line, $expense, $this->user, 'Bank fee May 2026');

    expect($je)->toBeInstanceOf(JournalEntry::class);
    expect($je->source_type)->toBe(JournalSourceType::BankAdjustment);
    expect($je->source_id)->toBe($line->id);

    // Line should be reconciled against the JE.
    expect($line->fresh()->matched_type)->toBe(JournalEntry::class);
    expect($line->fresh()->matched_id)->toBe($je->id);
    expect($line->fresh()->confidence)->toBe('manual');
});

it('posts an interest credit adjustment with reversed sign', function () {
    $line = mkAdjLine($this->stmt->id, 12.34, 'INTEREST EARNED');
    $income = GlAccount::where('code', '4900')->first()
        ?? GlAccount::create(['code' => '4900', 'name' => 'Other Income', 'type' => 'income']);
    \App\Models\GlAccountBalance::firstOrCreate(['gl_account_id' => $income->id], ['balance' => 0]);

    $je = $this->svc->postAdjustment($line, $income, $this->user, 'Interest May 2026');

    expect($je->lines->count())->toBe(2);
    // For interest: Dr bank GL, Cr income GL — bank balance should rise.
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=BankAdjustmentServiceTest
```

- [ ] **Step 3: Create the service**

`app/Services/Finance/BankAdjustmentService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalSourceType;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BankAdjustmentService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly ReconciliationService $reconciliation,
    ) {
    }

    public function postAdjustment(
        BankStatementLine $line,
        GlAccount $offsetGl,
        User $user,
        string $narration,
    ): JournalEntry {
        $bankGl = $line->statement->orgBankAccount->glAccount;
        $abs    = abs((float) $line->amount);

        return DB::transaction(function () use ($line, $offsetGl, $bankGl, $abs, $user, $narration) {
            // Debit line (fee): Dr offsetGl, Cr bank
            // Credit line (interest): Dr bank, Cr offsetGl
            if ($line->isDebit()) {
                $entries = [
                    ['gl_account_id' => $offsetGl->id, 'debit' => $abs, 'credit' => 0, 'narration' => $narration],
                    ['gl_account_id' => $bankGl->id,   'debit' => 0,    'credit' => $abs, 'narration' => $narration],
                ];
            } else {
                $entries = [
                    ['gl_account_id' => $bankGl->id,   'debit' => $abs, 'credit' => 0, 'narration' => $narration],
                    ['gl_account_id' => $offsetGl->id, 'debit' => 0,    'credit' => $abs, 'narration' => $narration],
                ];
            }

            $je = $this->journal->post([
                'date'          => $line->transaction_date->format('Y-m-d'),
                'narration'     => $narration,
                'source_type'   => JournalSourceType::BankAdjustment,
                'source_id'     => $line->id,
                'lines'         => $entries,
            ], $user);

            $this->reconciliation->link($line, $je, $user, 'manual');

            return $je->fresh();
        });
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=BankAdjustmentServiceTest
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/BankAdjustmentService.php tests/Feature/Finance/BankAdjustmentServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): BankAdjustmentService — bank fee / interest JE via JournalPostingService

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Reconciliation endpoints (controller, requests, resources, routes)

**Files:**
- Create: `app/Http/Requests/Finance/UploadBankStatementRequest.php`
- Create: `app/Http/Requests/Finance/LinkReconciliationLineRequest.php`
- Create: `app/Http/Requests/Finance/UnlinkReconciliationLineRequest.php`
- Create: `app/Http/Requests/Finance/PostBankAdjustmentRequest.php`
- Create: `app/Http/Resources/Finance/BankStatementResource.php`
- Create: `app/Http/Resources/Finance/BankStatementLineResource.php`
- Create: `app/Http/Controllers/Finance/ReconciliationController.php`
- Create: `resources/js/Pages/Finance/Reconciliation/Index.vue` (stub — Task 13 expands)
- Create: `resources/js/Pages/Finance/Reconciliation/Show.vue` (stub — Task 13 expands)
- Modify: `routes/web.php` — reconciliation routes inside the existing finance prefix
- Test: `tests/Feature/Finance/ReconciliationEndpointsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ReconciliationEndpointsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
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
    \App\Models\GlAccountBalance::firstOrCreate(['gl_account_id' => $expense->id], ['balance' => 0]);

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

    // Without fresh 2FA — blocked
    $this->actingAs($u)->post("/finance/reconciliation/lines/{$line->id}/adjust", [
        'gl_account_id' => $expense->id, 'narration' => 'fee',
    ])->assertStatus(302); // 2fa redirect

    // With fresh 2FA — succeeds
    $this->actingAs(rec2faFresh($u))->post("/finance/reconciliation/lines/{$line->id}/adjust", [
        'gl_account_id' => $expense->id, 'narration' => 'fee',
    ])->assertRedirect();

    expect($line->fresh()->reconciled_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=ReconciliationEndpointsTest
```

- [ ] **Step 3: Create `UploadBankStatementRequest`**

`app/Http/Requests/Finance/UploadBankStatementRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class UploadBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.import') === true;
    }

    public function rules(): array
    {
        return [
            'org_bank_account_id' => ['required', 'integer', 'exists:org_bank_accounts,id'],
            'bank_key'            => ['nullable', 'string', 'in:gcb,stanbic,gtb,ecobank'],
            'file'                => ['required', 'file', 'max:10240', 'mimes:csv,txt,ofx,sta,mt940,mt'],
        ];
    }
}
```

- [ ] **Step 4: Create `LinkReconciliationLineRequest`**

`app/Http/Requests/Finance/LinkReconciliationLineRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class LinkReconciliationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.match') === true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', 'in:ap_payment,ar_receipt'],
            'target_id'   => ['required', 'integer', 'min:1'],
        ];
    }
}
```

- [ ] **Step 5: Create `UnlinkReconciliationLineRequest`**

`app/Http/Requests/Finance/UnlinkReconciliationLineRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class UnlinkReconciliationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.match') === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 6: Create `PostBankAdjustmentRequest`**

`app/Http/Requests/Finance/PostBankAdjustmentRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class PostBankAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.adjust') === true;
    }

    public function rules(): array
    {
        return [
            'gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
            'narration'     => ['required', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 7: Create `BankStatementResource`**

`app/Http/Resources/Finance/BankStatementResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\BankStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BankStatement */
class BankStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalLines      = $this->lines()->count();
        $reconciledLines = $this->lines()->reconciled()->count();

        return [
            'id'                  => $this->id,
            'statement_date'      => $this->statement_date?->format('Y-m-d'),
            'period_start'        => $this->period_start?->format('Y-m-d'),
            'opening_balance'     => (float) $this->opening_balance,
            'closing_balance'     => (float) $this->closing_balance,
            'currency'            => $this->currency,
            'file_name'           => $this->file_name,
            'format'              => $this->format,
            'total_lines'         => $totalLines,
            'reconciled_lines'    => $reconciledLines,
            'reconciled_pct'      => $totalLines > 0 ? round($reconciledLines / $totalLines * 100, 1) : 0.0,
            'imported_at'         => $this->created_at?->format('Y-m-d H:i'),
            'org_bank_account'    => $this->whenLoaded('orgBankAccount', fn () => [
                'id' => $this->orgBankAccount->id, 'bank_name' => $this->orgBankAccount->bank_name,
            ]),
        ];
    }
}
```

- [ ] **Step 8: Create `BankStatementLineResource`**

`app/Http/Resources/Finance/BankStatementLineResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BankStatementLine */
class BankStatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'line_no'          => $this->line_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'value_date'       => $this->value_date?->format('Y-m-d'),
            'description'      => $this->description,
            'reference'        => $this->reference,
            'amount'           => (float) $this->amount,
            'running_balance'  => $this->running_balance !== null ? (float) $this->running_balance : null,
            'matched_type'     => $this->matched_type,
            'matched_id'       => $this->matched_id,
            'confidence'       => $this->confidence,
            'reconciled_at'    => $this->reconciled_at?->format('Y-m-d H:i'),
        ];
    }
}
```

- [ ] **Step 9: Create `ReconciliationController`**

`app/Http/Controllers/Finance/ReconciliationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\LinkReconciliationLineRequest;
use App\Http\Requests\Finance\PostBankAdjustmentRequest;
use App\Http\Requests\Finance\UnlinkReconciliationLineRequest;
use App\Http\Requests\Finance\UploadBankStatementRequest;
use App\Http\Resources\Finance\BankStatementLineResource;
use App\Http\Resources\Finance\BankStatementResource;
use App\Models\ApPayment;
use App\Models\ArReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Services\Finance\BankAdjustmentService;
use App\Services\Finance\ReconciliationMatcher;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\StatementImportService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly StatementImportService $importer,
        private readonly ReconciliationMatcher $matcher,
        private readonly ReconciliationService $reconciliation,
        private readonly BankAdjustmentService $adjustments,
    ) {
    }

    public function index(): Response
    {
        $statements = BankStatement::with('orgBankAccount:id,bank_name')
            ->orderByDesc('statement_date')
            ->paginate(50);

        return Inertia::render('Finance/Reconciliation/Index', [
            'activeModule' => 'finance-reconciliation',
            'statements'   => BankStatementResource::collection($statements),
            'bankAccounts' => OrgBankAccount::active()->orderBy('bank_name')->get(['id','bank_name','account_name','currency']),
        ]);
    }

    public function show(BankStatement $bankStatement): Response
    {
        $bankStatement->load('orgBankAccount');
        $lines = $bankStatement->lines()->orderBy('line_no')->get();

        // Show candidate AP payments and AR receipts for this bank account that are not yet matched.
        $unreconciledAp = ApPayment::where('org_bank_account_id', $bankStatement->org_bank_account_id)
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ApPayment::class)->whereNotNull('matched_id');
            })
            ->orderByDesc('payment_date')->limit(200)->get(['id','reference','payment_date','amount','external_ref']);

        $unreconciledAr = ArReceipt::where('org_bank_account_id', $bankStatement->org_bank_account_id)
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ArReceipt::class)->whereNotNull('matched_id');
            })
            ->orderByDesc('receipt_date')->limit(200)->get(['id','reference','receipt_date','amount','external_ref']);

        return Inertia::render('Finance/Reconciliation/Show', [
            'activeModule'     => 'finance-reconciliation',
            'statement'        => (new BankStatementResource($bankStatement))->resolve(),
            'lines'            => BankStatementLineResource::collection($lines)->resolve(),
            'unreconciledAp'   => $unreconciledAp,
            'unreconciledAr'   => $unreconciledAr,
        ]);
    }

    public function store(UploadBankStatementRequest $request): RedirectResponse
    {
        $bank = OrgBankAccount::findOrFail($request->validated('org_bank_account_id'));

        try {
            $statement = $this->importer->import(
                $request->file('file'),
                $bank,
                $request->user(),
                $request->validated('bank_key'),
            );
            $this->matcher->matchUnreconciled($statement);
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('finance.reconciliation.show', $statement)
            ->with('success', 'Statement imported and auto-matched.');
    }

    public function link(BankStatementLine $line, LinkReconciliationLineRequest $request): RedirectResponse
    {
        $targetClass = $request->validated('target_type') === 'ap_payment' ? ApPayment::class : ArReceipt::class;
        $target = $targetClass::findOrFail($request->validated('target_id'));

        try {
            $this->reconciliation->link($line, $target, $request->user(), 'manual');
        } catch (DomainException $e) {
            return back()->withErrors(['target_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Line linked.');
    }

    public function unlink(BankStatementLine $line, UnlinkReconciliationLineRequest $request): RedirectResponse
    {
        try {
            $this->reconciliation->unlink($line, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Line unlinked.');
    }

    public function adjust(BankStatementLine $line, PostBankAdjustmentRequest $request): RedirectResponse
    {
        $gl = GlAccount::findOrFail($request->validated('gl_account_id'));

        try {
            $this->adjustments->postAdjustment($line, $gl, $request->user(), $request->validated('narration'));
        } catch (DomainException $e) {
            return back()->withErrors(['gl_account_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Bank adjustment posted.');
    }
}
```

- [ ] **Step 10: Create the Vue stubs**

`resources/js/Pages/Finance/Reconciliation/Index.vue`:

```vue
<script setup>
defineProps({
    statements:   { type: Object, default: () => ({ data: [] }) },
    bankAccounts: { type: Array,  default: () => [] },
});
</script>

<template>
    <div>Reconciliation (stub)</div>
</template>
```

`resources/js/Pages/Finance/Reconciliation/Show.vue`:

```vue
<script setup>
defineProps({
    statement:      { type: Object, required: true },
    lines:          { type: Array, default: () => [] },
    unreconciledAp: { type: Array, default: () => [] },
    unreconciledAr: { type: Array, default: () => [] },
});
</script>

<template>
    <div>Reconciliation detail (stub)</div>
</template>
```

- [ ] **Step 11: Add routes**

In `routes/web.php`, inside the `Route::prefix('finance')->name('finance.')->group(...)` block, AFTER the F4 `payment-intents.*` routes, add:

```php
        // F5 — Bank Reconciliation
        Route::prefix('reconciliation')->name('reconciliation.')->group(function () {
            Route::middleware('permission:reconciliation.view')->group(function () {
                Route::get('/',                          [\App\Http\Controllers\Finance\ReconciliationController::class, 'index'])->name('index');
                Route::get('/{bankStatement}',           [\App\Http\Controllers\Finance\ReconciliationController::class, 'show'])->name('show');
            });
            Route::middleware('permission:reconciliation.import')->group(function () {
                Route::post('/',                         [\App\Http\Controllers\Finance\ReconciliationController::class, 'store'])->name('store');
            });
            Route::middleware('permission:reconciliation.match')->group(function () {
                Route::post('/lines/{line}/link',        [\App\Http\Controllers\Finance\ReconciliationController::class, 'link'])->name('link');
                Route::post('/lines/{line}/unlink',      [\App\Http\Controllers\Finance\ReconciliationController::class, 'unlink'])->name('unlink');
            });
            Route::middleware(['permission:reconciliation.adjust', '2fa:fresh'])->group(function () {
                Route::post('/lines/{line}/adjust',      [\App\Http\Controllers\Finance\ReconciliationController::class, 'adjust'])->name('adjust');
            });
        });
```

The `{line}` parameter resolves to `BankStatementLine` automatically because Laravel uses the parameter name + camelCase model lookup. Make sure your route uses `{line}` and the controller method signature reads `BankStatementLine $line`.

- [ ] **Step 12: Run test — must PASS**

```
php artisan test --filter=ReconciliationEndpointsTest
```

Expected: 5 tests pass.

- [ ] **Step 13: Commit**

```
git add app/Http/Requests/Finance/UploadBankStatementRequest.php app/Http/Requests/Finance/LinkReconciliationLineRequest.php app/Http/Requests/Finance/UnlinkReconciliationLineRequest.php app/Http/Requests/Finance/PostBankAdjustmentRequest.php app/Http/Resources/Finance/BankStatementResource.php app/Http/Resources/Finance/BankStatementLineResource.php app/Http/Controllers/Finance/ReconciliationController.php resources/js/Pages/Finance/Reconciliation/Index.vue resources/js/Pages/Finance/Reconciliation/Show.vue routes/web.php tests/Feature/Finance/ReconciliationEndpointsTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 reconciliation endpoints + routes + 2fa:fresh on adjust

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Real Inertia pages (Index + split-pane Show)

**Files:**
- Replace stub: `resources/js/Pages/Finance/Reconciliation/Index.vue`
- Replace stub: `resources/js/Pages/Finance/Reconciliation/Show.vue`

- [ ] **Step 1: Replace `Index.vue` with the real page**

Full source:

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    statements:   { type: Object, default: () => ({ data: [] }) },
    bankAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canImport = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('reconciliation.import');
});

const rows = computed(() => props.statements.data ?? props.statements ?? []);

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
const form = useForm({
    org_bank_account_id: null,
    bank_key:            'gcb',
    file:                null,
});

const openUpload = () => {
    form.reset();
    panelOpen.value = true;
};

const submit = () => form.post(route('finance.reconciliation.store'), {
    forceFormData: true,
    onSuccess: () => { panelOpen.value = false; form.reset(); },
});

const open = (id) => router.visit(route('finance.reconciliation.show', id));
</script>

<template>
    <Head title="Bank Reconciliation" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — RECONCILIATION</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Bank Reconciliation</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} imported statements.</p>
            </div>
            <PrimaryButton v-if="canImport" @click="openUpload">
                <span class="material-symbols-outlined text-[16px] mr-1">upload_file</span>Upload Statement
            </PrimaryButton>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Bank</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Statement Date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Opening</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Closing</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Progress</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Format</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in rows" :key="s.id" @click="open(s.id)"
                        class="border-t border-outline-variant/30 hover:bg-secondary/5 cursor-pointer">
                        <td class="px-4 py-2 text-on-surface">{{ s.org_bank_account?.bank_name ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono">{{ s.statement_date }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ cedi(s.opening_balance) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ cedi(s.closing_balance) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 rounded-full bg-surface-container">
                                    <div class="h-full rounded-full bg-emerald-500" :style="{ width: s.reconciled_pct + '%' }"></div>
                                </div>
                                <span class="text-[10px] font-bold text-on-surface-variant">{{ s.reconciled_pct }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-2 uppercase font-mono text-[10px]">{{ s.format }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="upload_file" title="No statements imported yet" description="Upload a bank statement CSV, OFX, or MT940 file to begin reconciliation." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="Upload Statement">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="org_bank_account_id" value="Bank Account" />
                    <select id="org_bank_account_id" v-model="form.org_bank_account_id" aria-label="Bank Account"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} · {{ b.account_name }}</option>
                    </select>
                    <InputError :message="form.errors.org_bank_account_id" />
                </div>
                <div>
                    <InputLabel for="bank_key" value="Bank Profile (for CSV)" />
                    <select id="bank_key" v-model="form.bank_key" aria-label="Bank profile"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option value="gcb">GCB Bank</option>
                        <option value="stanbic">Stanbic</option>
                        <option value="gtb">GTBank</option>
                        <option value="ecobank">Ecobank</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="file" value="Statement File (.csv, .ofx, .mt940)" />
                    <input id="file" type="file" aria-label="Statement file"
                           @input="form.file = $event.target.files[0]"
                           class="mt-1 block w-full text-[13px] text-on-surface-variant" />
                    <InputError :message="form.errors.file" />
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing || !form.org_bank_account_id || !form.file">Upload</PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>
```

- [ ] **Step 2: Replace `Show.vue` with the real split-pane page**

Full source:

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    statement:      { type: Object, required: true },
    lines:          { type: Array, default: () => [] },
    unreconciledAp: { type: Array, default: () => [] },
    unreconciledAr: { type: Array, default: () => [] },
});

const page = usePage();
const can = (perm) => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes(perm);
};
const canMatch = computed(() => can('reconciliation.match'));
const canAdjust = computed(() => can('reconciliation.adjust'));

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const selectedLine = ref(null);
const selectLine = (l) => { selectedLine.value = l; };

const linkTarget = (target, type) => {
    if (!selectedLine.value) return;
    router.post(route('finance.reconciliation.link', selectedLine.value.id), {
        target_type: type, target_id: target.id,
    }, { preserveScroll: true, onSuccess: () => { selectedLine.value = null; } });
};

const unlinkLine = (line) => {
    const reason = window.prompt('Reason for unlinking?');
    if (!reason) return;
    router.post(route('finance.reconciliation.unlink', line.id), { reason }, { preserveScroll: true });
};

const adjustModal = ref(null);
const adjForm = useForm({ gl_account_id: null, narration: '' });
const openAdjust = (line) => { adjustModal.value = line; adjForm.reset(); };
const submitAdjust = () => {
    if (!adjustModal.value) return;
    adjForm.post(route('finance.reconciliation.adjust', adjustModal.value.id), {
        preserveScroll: true,
        onSuccess: () => { adjustModal.value = null; },
    });
};

const confidenceColor = (c) => ({
    high:   'text-emerald-700 bg-emerald-50 border-emerald-100',
    medium: 'text-blue-700 bg-blue-50 border-blue-100',
    low:    'text-amber-700 bg-amber-50 border-amber-100',
    manual: 'text-violet-700 bg-violet-50 border-violet-100',
}[c] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');

const unmatched = computed(() => props.lines.filter(l => !l.reconciled_at));
const reconciled = computed(() => props.lines.filter(l => l.reconciled_at));
</script>

<template>
    <Head :title="`Statement ${statement.statement_date}`" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <Link :href="route('finance.reconciliation.index')" class="text-[11px] font-bold text-secondary hover:underline">← Back to statements</Link>
            <div class="mt-2 flex items-center justify-between">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary">{{ statement.org_bank_account?.bank_name }} · {{ statement.statement_date }}</h1>
                    <p class="text-[13px] text-on-surface-variant mt-0.5">Closing {{ cedi(statement.closing_balance) }} · {{ statement.reconciled_lines }}/{{ statement.total_lines }} lines reconciled ({{ statement.reconciled_pct }}%)</p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- LEFT: unmatched statement lines -->
            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 space-y-2">
                <h4 class="text-[13px] font-black text-primary">Unmatched statement lines ({{ unmatched.length }})</h4>
                <div v-if="unmatched.length" class="space-y-1.5">
                    <button v-for="l in unmatched" :key="l.id" @click="selectLine(l)"
                            :class="['w-full text-left rounded-xl border p-3 text-[12px] transition-colors',
                                selectedLine?.id === l.id ? 'border-secondary bg-secondary/5' : 'border-outline-variant/40 hover:border-secondary/40']">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ l.transaction_date }}</span>
                            <span class="font-mono font-bold" :class="l.amount < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ cedi(Math.abs(l.amount)) }}{{ l.amount < 0 ? ' Dr' : ' Cr' }}</span>
                        </div>
                        <p class="text-on-surface mt-0.5">{{ l.description }}</p>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">{{ l.reference ?? '—' }}</p>
                        <p v-if="l.confidence" class="mt-1">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="confidenceColor(l.confidence)">{{ l.confidence }} confidence</span>
                        </p>
                        <p v-if="canAdjust" class="mt-1 flex justify-end">
                            <button @click.stop="openAdjust(l)" class="text-[10px] font-bold text-secondary hover:underline">Post adjustment</button>
                        </p>
                    </button>
                </div>
                <p v-else class="text-[12px] text-on-surface-variant">All lines reconciled.</p>
            </section>

            <!-- RIGHT: candidates to pair with the selected line -->
            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 space-y-2">
                <h4 class="text-[13px] font-black text-primary">
                    <span v-if="selectedLine">Pair with…</span>
                    <span v-else class="text-on-surface-variant">Select a line on the left</span>
                </h4>
                <div v-if="selectedLine && selectedLine.amount < 0 && unreconciledAp.length" class="space-y-1.5">
                    <button v-for="p in unreconciledAp" :key="p.id" :disabled="!canMatch" @click="linkTarget(p, 'ap_payment')"
                            class="w-full text-left rounded-xl border border-outline-variant/40 p-3 text-[12px] hover:border-secondary/40 transition-colors">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ p.reference }}</span>
                            <span class="font-mono">{{ cedi(p.amount) }}</span>
                        </div>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">{{ p.payment_date }} · ext: {{ p.external_ref ?? '—' }}</p>
                    </button>
                </div>
                <div v-else-if="selectedLine && selectedLine.amount > 0 && unreconciledAr.length" class="space-y-1.5">
                    <button v-for="r in unreconciledAr" :key="r.id" :disabled="!canMatch" @click="linkTarget(r, 'ar_receipt')"
                            class="w-full text-left rounded-xl border border-outline-variant/40 p-3 text-[12px] hover:border-secondary/40 transition-colors">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ r.reference }}</span>
                            <span class="font-mono">{{ cedi(r.amount) }}</span>
                        </div>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">{{ r.receipt_date }} · ext: {{ r.external_ref ?? '—' }}</p>
                    </button>
                </div>
                <p v-else-if="selectedLine" class="text-[12px] text-on-surface-variant">No unreconciled candidates on this side.</p>
            </section>
        </div>

        <!-- Reconciled lines -->
        <section v-if="reconciled.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 space-y-2">
            <h4 class="text-[13px] font-black text-primary">Reconciled ({{ reconciled.length }})</h4>
            <div class="space-y-1.5">
                <div v-for="l in reconciled" :key="l.id" class="flex items-center justify-between rounded-xl border border-outline-variant/30 p-2.5 text-[12px]">
                    <div>
                        <span class="font-mono mr-2">{{ l.transaction_date }}</span>
                        <span>{{ l.description }}</span>
                        <span class="ml-2 rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="confidenceColor(l.confidence)">{{ l.confidence }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-mono font-bold" :class="l.amount < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ cedi(Math.abs(l.amount)) }}</span>
                        <button v-if="canMatch" @click="unlinkLine(l)" class="text-[10px] font-bold text-rose-700 hover:underline">Unlink</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Adjustment modal -->
        <div v-if="adjustModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center">
            <div class="bg-surface-container-lowest rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-[14px] font-black text-primary mb-3">Post Bank Adjustment</h3>
                <p class="text-[11px] text-on-surface-variant mb-4">{{ adjustModal.description }} · {{ cedi(Math.abs(adjustModal.amount)) }}</p>
                <form @submit.prevent="submitAdjust" class="space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Offset GL Account ID</label>
                        <input v-model.number="adjForm.gl_account_id" type="number" min="1"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Narration</label>
                        <input v-model="adjForm.narration" type="text"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="adjustModal = null" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                        <button type="submit" :disabled="adjForm.processing" class="rounded-xl bg-primary text-on-primary px-3 py-2 text-[12px] font-bold">Post</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Build + verify**

```
npm run build
php artisan test --filter=Finance
```

Build must compile cleanly; full Finance suite must remain green.

- [ ] **Step 4: Commit**

```
git add resources/js/Pages/Finance/Reconciliation/Index.vue resources/js/Pages/Finance/Reconciliation/Show.vue
git commit -m "$(cat <<'EOF'
feat(finance): F5 Reconciliation Index + split-pane Show pages

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Hub reconciliation stats + sidebar entry + acceptance smoke

**Files:**
- Modify: `app/Services/Finance/FinanceHubService.php` — add `reconciliationStats()`
- Modify: `resources/js/Pages/Finance/Hub.vue` — render reconciliation tile
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` — sidebar entry + icon
- Test: `tests/Feature/Finance/FinanceHubF5Test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/FinanceHubF5Test.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\OrgBankAccountSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
});

it('hub returns reconciliationStats key', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('reconciliationStats')
        );
});
```

- [ ] **Step 2: Run test — must FAIL on the missing key**

```
php artisan test --filter=FinanceHubF5Test
```

- [ ] **Step 3: Update `FinanceHubService`**

Open `app/Services/Finance/FinanceHubService.php`.

A. After the existing `gatewayHealth` line in `build()`, add:

```php
            'reconciliationStats' => $this->reconciliationStats(),
```

B. Add this private method after `gatewayHealth()`:

```php
    private function reconciliationStats(): array
    {
        $unreconciled = \App\Models\BankStatementLine::query()
            ->whereNull('reconciled_at')
            ->count();

        $oldest = \App\Models\BankStatementLine::query()
            ->whereNull('reconciled_at')
            ->min('transaction_date');

        return [
            'unreconciled_count'       => $unreconciled,
            'oldest_unreconciled_date' => $oldest,
        ];
    }
```

- [ ] **Step 4: Update `Hub.vue`**

Open `resources/js/Pages/Finance/Hub.vue`. Add the prop after the `gatewayHealth` line:

```js
reconciliationStats: { type: Object, default: () => ({ unreconciled_count: 0, oldest_unreconciled_date: null }) },
```

Add a small tile after the gateway health banner (or wherever the KPI grid sits) — copy-paste this snippet near the existing KPI tiles in the template:

```vue
<div v-if="reconciliationStats.unreconciled_count > 0" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Unreconciled Lines</p>
    <p class="mt-2 text-2xl font-black text-primary">{{ reconciliationStats.unreconciled_count }}</p>
    <p class="mt-1 text-[10px] text-on-surface-variant">Oldest: {{ reconciliationStats.oldest_unreconciled_date ?? '—' }}</p>
</div>
```

- [ ] **Step 5: Update sidebar in `AuthenticatedLayout.vue`**

A. Find `SIDEBAR_ICON_COLORS` and add:

```js
'finance-reconciliation': '#3949ab',
```

B. Find the non-admin branch's Finance section. Update its guard:

```js
if (can('finance.hub') || can('accounts.view') || can('bank_accounts.view') ||
    can('vendors.view') || can('ap_invoices.view') || can('journal.view') ||
    can('customers.view') || can('ar_invoices.view') || can('statements.view') ||
    can('gateway.view') || can('reconciliation.view')) {
```

After the F4 `Payment Links` entry inside `items`, add:

```js
                { label: 'Reconciliation', route: 'finance.reconciliation.index', module: 'finance-reconciliation', icon: 'compare_arrows', visible: can('reconciliation.view') },
```

- [ ] **Step 6: Add the FinanceHubTest assertion update**

Open `tests/Feature/Finance/FinanceHubTest.php`. In the existing `it('renders the hub for finance_officer with F2 aggregate keys'` test, append `->has('reconciliationStats')` inside the existing `assertInertia(fn ($p) => $p ...)` chain.

- [ ] **Step 7: Run tests + build**

```
php artisan test --filter='FinanceHub|Finance'
npm run build
```

Expected: full Finance suite passing including the new `FinanceHubF5Test`.

- [ ] **Step 8: Acceptance smoke**

```
php artisan test 2>&1 | tail -3
```
Expected: full Pest suite passing.

- [ ] **Step 9: Commit**

```
git add app/Services/Finance/FinanceHubService.php resources/js/Pages/Finance/Hub.vue resources/js/Layouts/AuthenticatedLayout.vue tests/Feature/Finance/FinanceHubF5Test.php tests/Feature/Finance/FinanceHubTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F5 hub reconciliationStats + Reconciliation sidebar entry

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done criteria

F5 is complete when:

1. All 14 tasks are checked off.
2. All Pest tests under `tests/Feature/Finance/` and `tests/Unit/Finance/` pass; F1+F2+F3+F4+F5 suite green (~245 tests).
3. A `finance_officer` can complete the full flow:
   - Upload a CSV statement against an active org bank account → matcher auto-links Tier 1 (Paystack `external_ref`) and Tier 2 (amount+date+ref) candidates → unmatched lines appear in the split-pane Show view.
   - Manually pair an unmatched debit with an AP payment → AP payment's `external_ref` gets back-populated → audit row appears in `bank_transaction_matches`.
   - Click "Post adjustment" on a bank-fee line → fresh-2FA prompt → JE posted with `source_type=bank_adjustment` → balances updated through `JournalPostingService`.
4. Re-uploading the same file collides on `file_hash` UNIQUE and the controller returns the existing statement (no duplicate rows).
5. `JournalPostingService` is unmodified (diff between F4 head and F5 head shows no change).
6. `ArReceiptService::record()` is unmodified.
7. F5 does not call `gateway->verifyTransaction()` anywhere (recon trusts `external_ref` already verified at F4 webhook time).
8. Hub shows the `reconciliationStats` tile.
9. Permission gates work: `auditor` view-only; `employee` 403 on listing; `2fa:fresh` required on adjust.
