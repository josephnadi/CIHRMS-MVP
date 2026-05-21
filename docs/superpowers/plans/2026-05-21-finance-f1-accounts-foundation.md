# Finance F1 — Accounts Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Chart of Accounts + Organizational Bank Accounts + Finance Hub foundation so the Finance department can manage the institute's own accounts, see a real-data treasury landing page, and have permissions in place for the upcoming Vendor Invoices / AR / Gateway / Reconciliation phases.

**Architecture:** Follows the established CIHRMS pattern (Enum → Migration → Model → Seeder → FormRequest → Service → Resource → Controller → Inertia page). Three new tables (`gl_accounts`, `org_bank_accounts`, `gl_account_balances`), two new enums, five new permission slugs, three new services, three new controllers, four new Inertia pages, plus a sidebar entry. The `gl_account_balances` table is a read-side cache that ships with zeros and will be mutated by F2's journal-posting engine; F1 does not introduce journal posting.

**Tech Stack:** Laravel 13.7, PHP 8.3, SQLite (dev), Eloquent + SoftDeletes, Inertia.js v2 + Vue 3, Tailwind v3, Pest. Existing `permission:slug` middleware (`App\Http\Middleware\EnsurePermission`) and `User::hasPermission()` API are reused unchanged.

**Spec reference:** [docs/superpowers/specs/2026-05-21-finance-f1-accounts-foundation-design.md](../specs/2026-05-21-finance-f1-accounts-foundation-design.md)

---

## File Structure

### New files

```
app/Enums/
    GlAccountType.php
    OrgBankAccountPurpose.php

app/Models/
    GlAccount.php
    OrgBankAccount.php
    GlAccountBalance.php

app/Http/Requests/Finance/
    StoreGlAccountRequest.php
    UpdateGlAccountRequest.php
    StoreOrgBankAccountRequest.php
    UpdateOrgBankAccountRequest.php

app/Http/Resources/Finance/
    GlAccountResource.php
    OrgBankAccountResource.php

app/Services/Finance/
    ChartOfAccountsService.php
    OrgBankAccountService.php
    FinanceHubService.php

app/Http/Controllers/Finance/
    FinanceHubController.php
    ChartOfAccountsController.php
    OrgBankAccountController.php

database/migrations/
    2026_05_21_000001_create_gl_accounts.php
    2026_05_21_000002_create_org_bank_accounts.php
    2026_05_21_000003_create_gl_account_balances.php

database/seeders/
    ChartOfAccountsSeeder.php
    OrgBankAccountSeeder.php
    GlAccountBalanceSeeder.php

resources/js/Pages/Finance/
    Hub.vue
    Accounts/Index.vue
    BankAccounts/Index.vue

tests/Feature/Finance/
    ChartOfAccountsTest.php
    OrgBankAccountTest.php
    FinanceHubTest.php
    ChartOfAccountsSeederTest.php
```

### Modified files

```
app/Models/User.php                              -- add new perms to ROLE_PERMISSIONS['finance_officer'] and ['auditor']
database/seeders/RolePermissionSeeder.php        -- add new perms to PERMISSIONS map and ROLE_PERMS arrays
database/seeders/DatabaseSeeder.php              -- register new seeders
routes/web.php                                   -- new /finance route group
resources/js/Layouts/AuthenticatedLayout.vue     -- add Finance sidebar entry
```

### Responsibility boundaries

- **Enums** — finite type vocabularies; no behavior.
- **Models** — schema + casts + relationships + read-only scopes. No business logic.
- **FormRequests** — input validation + per-request authorization via `hasPermission()`.
- **Resources** — output shaping for Inertia/JSON; includes the account-number masking rule.
- **Services** — all business logic: CRUD orchestration, tree building, hub-page aggregation, balance computations. Inject via constructor.
- **Controllers** — thin: inject service, delegate, return Inertia render or `back()`.
- **Seeders** — idempotent (use `updateOrCreate` keyed on natural keys); safe to re-run.
- **Inertia pages** — presentation only; no business logic. Use existing `@/Components/SlidePanel`, `@/Components/StatusBadge`, `@/Components/EmptyState`, `@/Components/Pagination`.

---

## Task 1: Enums

**Files:**
- Create: `app/Enums/GlAccountType.php`
- Create: `app/Enums/OrgBankAccountPurpose.php`
- Test: `tests/Unit/Finance/EnumsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Finance/EnumsTest.php`:

```php
<?php

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;

it('exposes all GL account types', function () {
    $values = array_map(fn ($c) => $c->value, GlAccountType::cases());
    expect($values)->toEqualCanonicalizing(['asset', 'liability', 'equity', 'income', 'expense']);
});

it('exposes all org bank account purposes', function () {
    $values = array_map(fn ($c) => $c->value, OrgBankAccountPurpose::cases());
    expect($values)->toEqualCanonicalizing(['operating', 'payroll', 'statutory_escrow', 'receipts', 'reserve']);
});

it('GL account type labels are non-empty', function () {
    foreach (GlAccountType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=EnumsTest
```
Expected: FAIL — `Class "App\Enums\GlAccountType" not found`.

- [ ] **Step 3: Create the GlAccountType enum**

`app/Enums/GlAccountType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum GlAccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Income    = 'income';
    case Expense   = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset     => 'Asset',
            self::Liability => 'Liability',
            self::Equity    => 'Equity',
            self::Income    => 'Income',
            self::Expense   => 'Expense',
        };
    }
}
```

- [ ] **Step 4: Create the OrgBankAccountPurpose enum**

`app/Enums/OrgBankAccountPurpose.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrgBankAccountPurpose: string
{
    case Operating        = 'operating';
    case Payroll          = 'payroll';
    case StatutoryEscrow  = 'statutory_escrow';
    case Receipts         = 'receipts';
    case Reserve          = 'reserve';

    public function label(): string
    {
        return match ($this) {
            self::Operating       => 'Operating',
            self::Payroll         => 'Payroll',
            self::StatutoryEscrow => 'Statutory Escrow',
            self::Receipts        => 'Receipts',
            self::Reserve         => 'Reserve',
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```
php artisan test --filter=EnumsTest
```
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```
git add app/Enums/GlAccountType.php app/Enums/OrgBankAccountPurpose.php tests/Unit/Finance/EnumsTest.php
git commit -m "feat(finance): add GlAccountType and OrgBankAccountPurpose enums"
```

---

## Task 2: Migrations

**Files:**
- Create: `database/migrations/2026_05_21_000001_create_gl_accounts.php`
- Create: `database/migrations/2026_05_21_000002_create_org_bank_accounts.php`
- Create: `database/migrations/2026_05_21_000003_create_gl_account_balances.php`
- Test: `tests/Feature/Finance/MigrationsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/MigrationsTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

it('creates the gl_accounts table', function () {
    expect(Schema::hasTable('gl_accounts'))->toBeTrue();
    expect(Schema::hasColumns('gl_accounts', [
        'id', 'code', 'name', 'type', 'parent_id',
        'is_active', 'currency', 'description',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the org_bank_accounts table', function () {
    expect(Schema::hasTable('org_bank_accounts'))->toBeTrue();
    expect(Schema::hasColumns('org_bank_accounts', [
        'id', 'gl_account_id', 'bank_name', 'branch', 'account_name',
        'account_number', 'sort_code', 'swift', 'currency', 'purpose',
        'opening_balance', 'is_active', 'notes',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the gl_account_balances table', function () {
    expect(Schema::hasTable('gl_account_balances'))->toBeTrue();
    expect(Schema::hasColumns('gl_account_balances', [
        'gl_account_id', 'balance', 'last_posted_at', 'updated_at',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=MigrationsTest
```
Expected: FAIL — `Schema::hasTable('gl_accounts')` returns false.

- [ ] **Step 3: Create `gl_accounts` migration**

`database/migrations/2026_05_21_000001_create_gl_accounts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 20)->index();
            $table->foreignId('parent_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->char('currency', 3)->default('GHS');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};
```

- [ ] **Step 4: Create `org_bank_accounts` migration**

`database/migrations/2026_05_21_000002_create_org_bank_accounts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->string('bank_name', 150);
            $table->string('branch', 150)->nullable();
            $table->string('account_name', 200);
            $table->string('account_number', 64);
            $table->string('sort_code', 20)->nullable();
            $table->string('swift', 20)->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->string('purpose', 30)->index();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bank_name', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_bank_accounts');
    }
};
```

- [ ] **Step 5: Create `gl_account_balances` migration**

`database/migrations/2026_05_21_000003_create_gl_account_balances.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_account_balances', function (Blueprint $table) {
            $table->foreignId('gl_account_id')
                ->primary()
                ->constrained('gl_accounts')
                ->cascadeOnDelete();
            $table->decimal('balance', 18, 2)->default(0);
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_account_balances');
    }
};
```

- [ ] **Step 6: Run test to verify it passes**

```
php artisan test --filter=MigrationsTest
```
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```
git add database/migrations/2026_05_21_000001_create_gl_accounts.php database/migrations/2026_05_21_000002_create_org_bank_accounts.php database/migrations/2026_05_21_000003_create_gl_account_balances.php tests/Feature/Finance/MigrationsTest.php
git commit -m "feat(finance): add gl_accounts, org_bank_accounts, gl_account_balances tables"
```

---

## Task 3: Models

**Files:**
- Create: `app/Models/GlAccount.php`
- Create: `app/Models/OrgBankAccount.php`
- Create: `app/Models/GlAccountBalance.php`
- Test: `tests/Feature/Finance/ModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ModelsTest.php`:

```php
<?php

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;

it('creates a GL account and casts enums + booleans', function () {
    $a = GlAccount::create([
        'code' => '1100',
        'name' => 'Bank — GCB Operating',
        'type' => GlAccountType::Asset->value,
        'is_active' => true,
    ]);

    expect($a->type)->toBe(GlAccountType::Asset);
    expect($a->is_active)->toBeTrue();
    expect($a->currency)->toBe('GHS');
});

it('supports parent/children relationship', function () {
    $parent = GlAccount::create(['code' => '1000', 'name' => 'Assets', 'type' => 'asset']);
    $child  = GlAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id);
    expect($parent->children->pluck('id'))->toContain($child->id);
});

it('scopes accounts by activity and type', function () {
    GlAccount::create(['code' => '1100', 'name' => 'A', 'type' => 'asset', 'is_active' => true]);
    GlAccount::create(['code' => '2100', 'name' => 'L', 'type' => 'liability', 'is_active' => true]);
    GlAccount::create(['code' => '4100', 'name' => 'I', 'type' => 'income', 'is_active' => false]);

    expect(GlAccount::active()->count())->toBe(2);
    expect(GlAccount::ofType(GlAccountType::Asset)->count())->toBe(1);
    expect(GlAccount::roots()->count())->toBe(3);
});

it('creates an org bank account linked to a GL account', function () {
    $gl = GlAccount::create(['code' => '1100', 'name' => 'Bank GCB', 'type' => 'asset']);

    $bank = OrgBankAccount::create([
        'gl_account_id'   => $gl->id,
        'bank_name'       => 'GCB',
        'account_name'    => 'CIHRM Operating',
        'account_number'  => '1234567890',
        'purpose'         => OrgBankAccountPurpose::Operating->value,
        'opening_balance' => 50000.00,
    ]);

    expect($bank->purpose)->toBe(OrgBankAccountPurpose::Operating);
    expect((float) $bank->opening_balance)->toBe(50000.00);
    expect($bank->glAccount->id)->toBe($gl->id);
});

it('balance row uses gl_account_id as the primary key', function () {
    $gl = GlAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset']);
    $bal = GlAccountBalance::create(['gl_account_id' => $gl->id, 'balance' => 1234.56]);

    expect($bal->getKeyName())->toBe('gl_account_id');
    expect($bal->incrementing)->toBeFalse();
    expect((float) $bal->balance)->toBe(1234.56);
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=ModelsTest
```
Expected: FAIL — `App\Models\GlAccount` class not found.

- [ ] **Step 3: Create `GlAccount` model**

`app/Models/GlAccount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GlAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class GlAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'gl_accounts';

    protected $fillable = [
        'code', 'name', 'type', 'parent_id', 'is_active', 'currency', 'description',
    ];

    protected function casts(): array
    {
        return [
            'type'      => GlAccountType::class,
            'is_active' => 'bool',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(GlAccountBalance::class, 'gl_account_id');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(OrgBankAccount::class, 'gl_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOfType(Builder $q, GlAccountType|string $type): Builder
    {
        return $q->where('type', $type instanceof GlAccountType ? $type->value : $type);
    }

    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_id');
    }
}
```

- [ ] **Step 4: Create `OrgBankAccount` model**

`app/Models/OrgBankAccount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgBankAccountPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrgBankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'org_bank_accounts';

    protected $fillable = [
        'gl_account_id', 'bank_name', 'branch', 'account_name', 'account_number',
        'sort_code', 'swift', 'currency', 'purpose', 'opening_balance', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purpose'         => OrgBankAccountPurpose::class,
            'opening_balance' => 'decimal:2',
            'is_active'       => 'bool',
        ];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForPurpose(Builder $q, OrgBankAccountPurpose|string $purpose): Builder
    {
        return $q->where('purpose', $purpose instanceof OrgBankAccountPurpose ? $purpose->value : $purpose);
    }
}
```

- [ ] **Step 5: Create `GlAccountBalance` model**

`app/Models/GlAccountBalance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlAccountBalance extends Model
{
    protected $table = 'gl_account_balances';
    protected $primaryKey = 'gl_account_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public const CREATED_AT = null;
    // updated_at is present; Eloquent default UPDATED_AT works.

    protected $fillable = ['gl_account_id', 'balance', 'last_posted_at'];

    protected function casts(): array
    {
        return [
            'balance'        => 'decimal:2',
            'last_posted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

```
php artisan test --filter=ModelsTest
```
Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```
git add app/Models/GlAccount.php app/Models/OrgBankAccount.php app/Models/GlAccountBalance.php tests/Feature/Finance/ModelsTest.php
git commit -m "feat(finance): add GlAccount, OrgBankAccount, GlAccountBalance models"
```

---

## Task 4: Seeders

**Files:**
- Create: `database/seeders/ChartOfAccountsSeeder.php`
- Create: `database/seeders/OrgBankAccountSeeder.php`
- Create: `database/seeders/GlAccountBalanceSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` — register the three new seeders
- Test: `tests/Feature/Finance/ChartOfAccountsSeederTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ChartOfAccountsSeederTest.php`:

```php
<?php

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

it('seeds at least 30 GL accounts with at least one per type', function () {
    (new ChartOfAccountsSeeder())->run();

    expect(GlAccount::count())->toBeGreaterThanOrEqual(30);

    foreach (GlAccountType::cases() as $type) {
        expect(GlAccount::ofType($type)->count())->toBeGreaterThanOrEqual(1);
    }
});

it('chart of accounts seeder is idempotent', function () {
    (new ChartOfAccountsSeeder())->run();
    $countAfterFirst = GlAccount::count();

    (new ChartOfAccountsSeeder())->run();
    $countAfterSecond = GlAccount::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('balance seeder creates one balance row per account at zero', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    expect(GlAccountBalance::count())->toBe(GlAccount::count());
    expect(GlAccountBalance::sum('balance'))->toBe('0.00');
});

it('balance seeder is idempotent', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $countAfterFirst = GlAccountBalance::count();

    (new GlAccountBalanceSeeder())->run();
    expect(GlAccountBalance::count())->toBe($countAfterFirst);
});

it('seeds 3 org bank accounts linked to asset GL accounts', function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    expect(OrgBankAccount::count())->toBe(3);
    foreach (OrgBankAccount::with('glAccount')->get() as $bank) {
        expect($bank->glAccount->type)->toBe(GlAccountType::Asset);
    }
});

it('org bank account seeder is idempotent', function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    $countAfterFirst = OrgBankAccount::count();

    (new OrgBankAccountSeeder())->run();
    expect(OrgBankAccount::count())->toBe($countAfterFirst);
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=ChartOfAccountsSeederTest
```
Expected: FAIL — `Database\Seeders\ChartOfAccountsSeeder` class not found.

- [ ] **Step 3: Create `ChartOfAccountsSeeder`**

`database/seeders/ChartOfAccountsSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * NPO-flavored Ghana chart of accounts.
     * Structure: [code, name, type, parent_code|null].
     * Children must follow their parents (single-pass build).
     */
    private const ACCOUNTS = [
        // Assets (1xxx)
        ['1000', 'Assets',                      'asset', null],
        ['1010', 'Cash on Hand',                'asset', '1000'],
        ['1100', 'Bank — GCB Operating',        'asset', '1000'],
        ['1110', 'Bank — Stanbic Payroll',      'asset', '1000'],
        ['1120', 'Bank — ADB Statutory Escrow', 'asset', '1000'],
        ['1200', 'Accounts Receivable',         'asset', '1000'],
        ['1300', 'Loans Receivable from Staff', 'asset', '1000'],

        // Liabilities (2xxx)
        ['2000', 'Liabilities',                  'liability', null],
        ['2100', 'Accounts Payable',             'liability', '2000'],
        ['2200', 'SSNIT Payable',                'liability', '2000'],
        ['2210', 'PAYE Payable',                 'liability', '2000'],
        ['2220', 'Tier-2 Pension Payable',       'liability', '2000'],
        ['2230', 'Tier-3 Pension Payable',       'liability', '2000'],
        ['2240', 'NHIA Payable',                 'liability', '2000'],
        ['2300', 'Salaries Payable',             'liability', '2000'],

        // Equity (3xxx)
        ['3000', 'Equity',                  'equity', null],
        ['3100', 'General Fund',            'equity', '3000'],
        ['3200', 'Accumulated Surplus',     'equity', '3000'],

        // Income (4xxx)
        ['4000', 'Income',                  'income', null],
        ['4100', 'Membership Dues',         'income', '4000'],
        ['4200', 'Course Fees',             'income', '4000'],
        ['4300', 'Certification Fees',      'income', '4000'],
        ['4400', 'Donations & Grants',      'income', '4000'],
        ['4500', 'Other Income',            'income', '4000'],

        // Expense (5xxx)
        ['5000', 'Expenses',                       'expense', null],
        ['5100', 'Salaries Expense',               'expense', '5000'],
        ['5110', 'Allowances Expense',             'expense', '5000'],
        ['5120', 'Statutory Employer Contributions','expense', '5000'],
        ['5200', 'Operations Expense',             'expense', '5000'],
        ['5300', 'IT & Technology',                'expense', '5000'],
        ['5400', 'Marketing',                      'expense', '5000'],
        ['5500', 'Other Expenses',                 'expense', '5000'],
    ];

    public function run(): void
    {
        $codeToId = [];

        foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode]) {
            $parentId = $parentCode === null ? null : ($codeToId[$parentCode] ?? null);

            $account = GlAccount::updateOrCreate(
                ['code' => $code],
                [
                    'name'      => $name,
                    'type'      => $type,
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'currency'  => 'GHS',
                ]
            );

            $codeToId[$code] = $account->id;
        }
    }
}
```

- [ ] **Step 4: Create `OrgBankAccountSeeder`**

`database/seeders/OrgBankAccountSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use Illuminate\Database\Seeder;

class OrgBankAccountSeeder extends Seeder
{
    /**
     * Seeds 3 bank accounts. Linked GL accounts (codes 1100/1110/1120) must
     * already exist — run ChartOfAccountsSeeder first.
     */
    private const BANKS = [
        ['1100', 'GCB', 'Head Office', 'CIHRM — Operating',         '1010000012345', 'GH010100', OrgBankAccountPurpose::Operating],
        ['1110', 'Stanbic', 'Accra Main', 'CIHRM — Payroll',         '9040000098765', 'GH050100', OrgBankAccountPurpose::Payroll],
        ['1120', 'ADB', 'Achimota',     'CIHRM — Statutory Escrow', '0501000054321', 'GH080100', OrgBankAccountPurpose::StatutoryEscrow],
    ];

    public function run(): void
    {
        foreach (self::BANKS as [$glCode, $bankName, $branch, $accountName, $accountNumber, $sortCode, $purpose]) {
            $gl = GlAccount::where('code', $glCode)->first();
            if (! $gl) continue;

            OrgBankAccount::updateOrCreate(
                ['bank_name' => $bankName, 'account_number' => $accountNumber],
                [
                    'gl_account_id'   => $gl->id,
                    'branch'          => $branch,
                    'account_name'    => $accountName,
                    'sort_code'       => $sortCode,
                    'currency'        => 'GHS',
                    'purpose'         => $purpose->value,
                    'opening_balance' => 0,
                    'is_active'       => true,
                ]
            );
        }
    }
}
```

- [ ] **Step 5: Create `GlAccountBalanceSeeder`**

`database/seeders/GlAccountBalanceSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use Illuminate\Database\Seeder;

class GlAccountBalanceSeeder extends Seeder
{
    /**
     * Ensures every gl_account has a corresponding balance row at zero.
     * Idempotent — safe to re-run after new accounts are added.
     */
    public function run(): void
    {
        GlAccount::query()->chunk(100, function ($accounts) {
            foreach ($accounts as $account) {
                GlAccountBalance::updateOrCreate(
                    ['gl_account_id' => $account->id],
                    ['balance' => GlAccountBalance::where('gl_account_id', $account->id)->value('balance') ?? 0]
                );
            }
        });
    }
}
```

- [ ] **Step 6: Register seeders in `DatabaseSeeder`**

Open `database/seeders/DatabaseSeeder.php`. Find the existing `call(...)` block that runs `RolePermissionSeeder`. After it, add the three new seeders (or merge into the existing list — match the existing style):

```php
$this->call([
    // ... existing seeders ...
    \Database\Seeders\ChartOfAccountsSeeder::class,
    \Database\Seeders\OrgBankAccountSeeder::class,
    \Database\Seeders\GlAccountBalanceSeeder::class,
    // ... other existing seeders ...
]);
```

If the existing `DatabaseSeeder` uses individual `$this->call(SeederClass::class)` lines, follow that style instead. The order matters: `ChartOfAccountsSeeder` first (creates accounts), then `OrgBankAccountSeeder` (links to accounts), then `GlAccountBalanceSeeder` (creates balance rows for all accounts).

- [ ] **Step 7: Run test to verify it passes**

```
php artisan test --filter=ChartOfAccountsSeederTest
```
Expected: PASS, 6 tests.

- [ ] **Step 8: Run a full migrate:fresh --seed locally to verify no regressions**

```
php artisan migrate:fresh --seed
```
Expected: completes without errors. The output should show all three new seeders running.

- [ ] **Step 9: Commit**

```
git add database/seeders/ChartOfAccountsSeeder.php database/seeders/OrgBankAccountSeeder.php database/seeders/GlAccountBalanceSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Finance/ChartOfAccountsSeederTest.php
git commit -m "feat(finance): seed chart of accounts, bank accounts, zero balances"
```

---

## Task 5: Permissions

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Modify: `app/Models/User.php` (only the `ROLE_PERMISSIONS` constant)
- Test: `tests/Feature/Finance/PermissionsSeedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PermissionsSeedTest.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the new finance permission slugs', function () {
    foreach (['accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'] as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants new finance perms to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub');
});

it('grants read-only finance perms to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('accounts.view', 'bank_accounts.view');
    expect($slugs)->not->toContain('accounts.manage', 'bank_accounts.manage', 'finance.hub');
});

it('legacy fallback ROLE_PERMISSIONS stays in lock-step', function () {
    $finance = User::ROLE_PERMISSIONS['finance_officer'];
    $auditor = User::ROLE_PERMISSIONS['auditor'];

    foreach (['accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'] as $slug) {
        expect($finance)->toContain($slug);
    }

    foreach (['accounts.view', 'bank_accounts.view'] as $slug) {
        expect($auditor)->toContain($slug);
    }
});

it('hasPermission resolves the new slugs for a finance officer', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);

    foreach (['accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'] as $slug) {
        expect($u->hasPermission($slug))->toBeTrue("finance_officer should have {$slug}");
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=PermissionsSeedTest
```
Expected: FAIL — permission slugs are not yet defined.

- [ ] **Step 3: Add the five new perms to `RolePermissionSeeder::PERMISSIONS`**

Open `database/seeders/RolePermissionSeeder.php`. In the `private const PERMISSIONS` map, add a new "Finance" group block (placement: after the existing Loans block to keep finance-related slugs together):

```php
// ── F1: Finance — Chart of Accounts & Org Banking ──
'accounts.view'        => ['Finance', 'View chart of accounts'],
'accounts.manage'      => ['Finance', 'Create / edit / archive GL accounts'],
'bank_accounts.view'   => ['Finance', 'View organisational bank accounts'],
'bank_accounts.manage' => ['Finance', 'Manage organisational bank accounts'],
'finance.hub'          => ['Finance', 'Access the Finance Hub landing page'],
```

- [ ] **Step 4: Grant the new perms to `finance_officer` and `auditor` in `ROLE_PERMS`**

In the same file, find the `'finance_officer' => [...]` block and append the new slugs:

```php
'finance_officer' => [
    // ... existing slugs unchanged ...
    'governance.view', 'governance.acknowledge',
    'reports.view',
    // F1 — Finance Hub & Chart of Accounts
    'accounts.view', 'accounts.manage',
    'bank_accounts.view', 'bank_accounts.manage',
    'finance.hub',
],
```

Find the `'auditor' => [...]` block and append the two view-only slugs:

```php
'auditor' => [
    // ... existing slugs unchanged ...
    'benefits.view', 'benefits.enrol', 'benefits.claim',
    'governance.view', 'governance.acknowledge',
    // F1 — Finance read-only oversight
    'accounts.view', 'bank_accounts.view',
],
```

- [ ] **Step 5: Mirror the changes in `User::ROLE_PERMISSIONS` legacy fallback**

Open `app/Models/User.php`. Find `public const ROLE_PERMISSIONS = [...]`. Update the `'finance_officer'` and `'auditor'` arrays to include the same new slugs as above. The legacy fallback is the source of truth used by factory-created users in tests that don't attach DB role pivots — keep both in lock-step.

`'finance_officer'`: add `'accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'`.

`'auditor'`: add `'accounts.view', 'bank_accounts.view'`.

- [ ] **Step 6: Run test to verify it passes**

```
php artisan test --filter=PermissionsSeedTest
```
Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```
git add database/seeders/RolePermissionSeeder.php app/Models/User.php tests/Feature/Finance/PermissionsSeedTest.php
git commit -m "feat(finance): add accounts/bank_accounts/finance.hub permissions"
```

---

## Task 6: ChartOfAccountsService

**Files:**
- Create: `app/Services/Finance/ChartOfAccountsService.php`
- Test: `tests/Feature/Finance/ChartOfAccountsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ChartOfAccountsServiceTest.php`:

```php
<?php

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
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=ChartOfAccountsServiceTest
```
Expected: FAIL — `App\Services\Finance\ChartOfAccountsService` class not found.

- [ ] **Step 3: Create the service**

`app/Services/Finance/ChartOfAccountsService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountsService
{
    public function list(array $filters = []): Collection
    {
        $q = GlAccount::query()->with('balance');

        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $q->where(function ($w) use ($term) {
                $w->where('code', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $q->where('is_active', (bool) $filters['is_active']);
        }

        return $q->orderBy('code')->get();
    }

    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $q = GlAccount::query()->with('balance');

        if (! empty($filters['type']))   $q->where('type', $filters['type']);
        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $q->where(function ($w) use ($term) {
                $w->where('code', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%");
            });
        }

        return $q->orderBy('code')->paginate($perPage)->withQueryString();
    }

    public function tree(): Collection
    {
        $all = GlAccount::query()
            ->with('balance')
            ->orderBy('code')
            ->get();

        $byParent = $all->groupBy('parent_id');

        $attach = function (GlAccount $node) use (&$attach, $byParent) {
            $node->setRelation('children', ($byParent->get($node->id) ?? collect())->each($attach));
            return $node;
        };

        return ($byParent->get(null) ?? collect())->each($attach);
    }

    public function create(array $data): GlAccount
    {
        return DB::transaction(function () use ($data) {
            $account = GlAccount::create($data);
            GlAccountBalance::firstOrCreate(
                ['gl_account_id' => $account->id],
                ['balance' => 0]
            );
            return $account->load('balance');
        });
    }

    public function update(GlAccount $account, array $data): GlAccount
    {
        $account->update($data);
        return $account->fresh('balance');
    }

    public function archive(GlAccount $account): void
    {
        $account->delete();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
php artisan test --filter=ChartOfAccountsServiceTest
```
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/ChartOfAccountsService.php tests/Feature/Finance/ChartOfAccountsServiceTest.php
git commit -m "feat(finance): add ChartOfAccountsService"
```

---

## Task 7: Chart of Accounts — FormRequests, Resource, Controller, Routes

**Files:**
- Create: `app/Http/Requests/Finance/StoreGlAccountRequest.php`
- Create: `app/Http/Requests/Finance/UpdateGlAccountRequest.php`
- Create: `app/Http/Resources/Finance/GlAccountResource.php`
- Create: `app/Http/Controllers/Finance/ChartOfAccountsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/ChartOfAccountsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ChartOfAccountsTest.php`:

```php
<?php

use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('lets finance_officer list chart of accounts', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance/accounts')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Accounts/Index'));
});

it('forbids employees from listing chart of accounts', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get('/finance/accounts')
        ->assertForbidden();
});

it('lets auditor list but not create accounts', function () {
    $auditor = User::factory()->create(['role' => 'auditor']);

    $this->actingAs($auditor)->get('/finance/accounts')->assertOk();
    $this->actingAs($auditor)->post('/finance/accounts', [
        'code' => '9999', 'name' => 'Hack', 'type' => 'expense',
    ])->assertForbidden();
});

it('lets finance_officer create a GL account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->post('/finance/accounts', [
            'code' => '6000',
            'name' => 'New Test Expense',
            'type' => 'expense',
        ])
        ->assertRedirect();

    expect(GlAccount::where('code', '6000')->exists())->toBeTrue();
});

it('rejects duplicate codes', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->post('/finance/accounts', ['code' => '1000', 'name' => 'Dup', 'type' => 'asset'])
        ->assertSessionHasErrors(['code']);
});

it('rejects self-referential parent on update', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    $this->actingAs($finance)
        ->patch("/finance/accounts/{$acc->id}", [
            'code' => $acc->code,
            'name' => $acc->name,
            'type' => $acc->type->value,
            'parent_id' => $acc->id,
        ])
        ->assertSessionHasErrors(['parent_id']);
});

it('lets finance_officer archive a GL account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $acc = GlAccount::where('code', '5500')->firstOrFail();

    $this->actingAs($finance)
        ->delete("/finance/accounts/{$acc->id}")
        ->assertRedirect();

    expect(GlAccount::find($acc->id))->toBeNull();
    expect(GlAccount::withTrashed()->find($acc->id)->trashed())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=ChartOfAccountsTest
```
Expected: FAIL — route `/finance/accounts` does not exist.

- [ ] **Step 3: Create `StoreGlAccountRequest`**

`app/Http/Requests/Finance/StoreGlAccountRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGlAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounts.manage') === true;
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:20', 'unique:gl_accounts,code'],
            'name'        => ['required', 'string', 'max:150'],
            'type'        => ['required', Rule::enum(GlAccountType::class)],
            'parent_id'   => ['nullable', 'integer', 'exists:gl_accounts,id'],
            'is_active'   => ['sometimes', 'boolean'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 4: Create `UpdateGlAccountRequest`**

`app/Http/Requests/Finance/UpdateGlAccountRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGlAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounts.manage') === true;
    }

    public function rules(): array
    {
        $id = $this->route('account')?->id;

        return [
            'code'        => ['required', 'string', 'max:20', Rule::unique('gl_accounts', 'code')->ignore($id)],
            'name'        => ['required', 'string', 'max:150'],
            'type'        => ['required', Rule::enum(GlAccountType::class)],
            'parent_id'   => [
                'nullable',
                'integer',
                'exists:gl_accounts,id',
                Rule::notIn([$id]),
            ],
            'is_active'   => ['sometimes', 'boolean'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 5: Create `GlAccountResource`**

`app/Http/Resources/Finance/GlAccountResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\GlAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GlAccount */
class GlAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'type'       => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'parent_id'  => $this->parent_id,
            'is_active'  => $this->is_active,
            'currency'   => $this->currency,
            'description'=> $this->description,
            'balance'    => $this->whenLoaded('balance', fn () => (float) ($this->balance?->balance ?? 0)),
            'children'   => GlAccountResource::collection($this->whenLoaded('children')),
        ];
    }
}
```

- [ ] **Step 6: Create `ChartOfAccountsController`**

`app/Http/Controllers/Finance/ChartOfAccountsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreGlAccountRequest;
use App\Http\Requests\Finance\UpdateGlAccountRequest;
use App\Http\Resources\Finance\GlAccountResource;
use App\Models\GlAccount;
use App\Services\Finance\ChartOfAccountsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChartOfAccountsController extends Controller
{
    public function __construct(private readonly ChartOfAccountsService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['type', 'search']);

        return Inertia::render('Finance/Accounts/Index', [
            'tree'    => GlAccountResource::collection($this->service->tree()),
            'flat'    => GlAccountResource::collection($this->service->list($filters)),
            'filters' => $filters,
        ]);
    }

    public function store(StoreGlAccountRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());
        return back()->with('success', 'GL account created.');
    }

    public function update(UpdateGlAccountRequest $request, GlAccount $account): RedirectResponse
    {
        $this->service->update($account, $request->validated());
        return back()->with('success', 'GL account updated.');
    }

    public function destroy(GlAccount $account): RedirectResponse
    {
        $this->service->archive($account);
        return back()->with('success', 'GL account archived.');
    }
}
```

- [ ] **Step 7: Add routes**

Open `routes/web.php`. Add the new controller imports near the top with the other `use App\Http\Controllers\...` lines:

```php
use App\Http\Controllers\Finance\ChartOfAccountsController;
use App\Http\Controllers\Finance\FinanceHubController;
use App\Http\Controllers\Finance\OrgBankAccountController;
```

Then, inside the `Route::middleware(['auth', 'audit'])->group(...)` block (the same block that wraps `employees`, `leave`, etc., at the existing line around 190), add the new Finance route group at the end of the block.

**Important:** the `finance.hub` permission gates ONLY the hub landing page (`GET /finance`). The accounts and bank-accounts endpoints have their own per-permission middleware so an auditor (who has `accounts.view` + `bank_accounts.view` but NOT `finance.hub`) can still reach the list pages.

```php
Route::prefix('finance')->name('finance.')->group(function () {
    Route::middleware('permission:finance.hub')->group(function () {
        Route::get('/', [FinanceHubController::class, 'index'])->name('hub');
    });

    Route::middleware('permission:accounts.view')->group(function () {
        Route::get('accounts', [ChartOfAccountsController::class, 'index'])->name('accounts.index');
    });
    Route::middleware('permission:accounts.manage')->group(function () {
        Route::post('accounts',                [ChartOfAccountsController::class, 'store'])->name('accounts.store');
        Route::patch('accounts/{account}',     [ChartOfAccountsController::class, 'update'])->name('accounts.update');
        Route::delete('accounts/{account}',    [ChartOfAccountsController::class, 'destroy'])->name('accounts.destroy');
    });

    Route::middleware('permission:bank_accounts.view')->group(function () {
        Route::get('bank-accounts', [OrgBankAccountController::class, 'index'])->name('bank-accounts.index');
    });
    Route::middleware('permission:bank_accounts.manage')->group(function () {
        Route::post('bank-accounts',                  [OrgBankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::patch('bank-accounts/{bankAccount}',   [OrgBankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('bank-accounts/{bankAccount}',  [OrgBankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
    });
});
```

The `{account}` and `{bankAccount}` parameters use implicit model binding via the controller's `GlAccount $account` and `OrgBankAccount $bankAccount` type hints — no explicit `Route::model(...)` registration is needed. The test in Step 8 verifies this works.

- [ ] **Step 8: Run test to verify it passes**

```
php artisan test --filter=ChartOfAccountsTest
```
Expected: PASS, 7 tests.

- [ ] **Step 9: Commit**

```
git add app/Http/Requests/Finance app/Http/Resources/Finance/GlAccountResource.php app/Http/Controllers/Finance/ChartOfAccountsController.php routes/web.php tests/Feature/Finance/ChartOfAccountsTest.php
git commit -m "feat(finance): chart of accounts CRUD endpoints and RBAC"
```

---

## Task 8: Org Bank Account — Service, FormRequests, Resource, Controller

**Files:**
- Create: `app/Services/Finance/OrgBankAccountService.php`
- Create: `app/Http/Requests/Finance/StoreOrgBankAccountRequest.php`
- Create: `app/Http/Requests/Finance/UpdateOrgBankAccountRequest.php`
- Create: `app/Http/Resources/Finance/OrgBankAccountResource.php`
- Create: `app/Http/Controllers/Finance/OrgBankAccountController.php`
- Test: `tests/Feature/Finance/OrgBankAccountTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/OrgBankAccountTest.php`:

```php
<?php

use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();
});

it('lets finance_officer list org bank accounts', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance/bank-accounts')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/BankAccounts/Index'));
});

it('forbids employees from listing org bank accounts', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get('/finance/bank-accounts')
        ->assertForbidden();
});

it('rejects bank account linked to a non-asset GL account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $liability = GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs($finance)
        ->post('/finance/bank-accounts', [
            'gl_account_id'  => $liability->id,
            'bank_name'      => 'Test Bank',
            'account_name'   => 'CIHRM Test',
            'account_number' => '9999999999',
            'purpose'        => 'operating',
        ])
        ->assertSessionHasErrors(['gl_account_id']);
});

it('lets finance_officer create a valid bank account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $asset = GlAccount::create(['code' => '1199', 'name' => 'Bank — Misc', 'type' => 'asset']);
    \App\Models\GlAccountBalance::create(['gl_account_id' => $asset->id, 'balance' => 0]);

    $this->actingAs($finance)
        ->post('/finance/bank-accounts', [
            'gl_account_id'  => $asset->id,
            'bank_name'      => 'Test Bank',
            'account_name'   => 'CIHRM Test',
            'account_number' => '9999999999',
            'purpose'        => 'reserve',
        ])
        ->assertRedirect();

    expect(OrgBankAccount::where('account_number', '9999999999')->exists())->toBeTrue();
});

it('manager users with bank_accounts.manage see full account number in response payload', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $response = $this->actingAs($finance)->get('/finance/bank-accounts');
    $response->assertInertia(function ($p) {
        $banks = $p->toArray()['props']['banks']['data'];
        expect(collect($banks)->pluck('account_number')->every(fn ($n) => strlen($n) > 4))->toBeTrue();
    });
});

it('viewer users without bank_accounts.manage see masked account number', function () {
    $auditor = User::factory()->create(['role' => 'auditor']);

    $response = $this->actingAs($auditor)->get('/finance/bank-accounts');
    $response->assertInertia(function ($p) {
        $banks = $p->toArray()['props']['banks']['data'];
        foreach ($banks as $b) {
            expect($b['account_number'])->toMatch('/^•{4,}\d{4}$/');
        }
    });
});

it('enforces unique (bank_name, account_number) combination', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $existing = OrgBankAccount::first();

    $this->actingAs($finance)
        ->post('/finance/bank-accounts', [
            'gl_account_id'  => $existing->gl_account_id,
            'bank_name'      => $existing->bank_name,
            'account_number' => $existing->account_number,
            'account_name'   => 'Dup',
            'purpose'        => 'operating',
        ])
        ->assertSessionHasErrors(['account_number']);
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=OrgBankAccountTest
```
Expected: FAIL — route `/finance/bank-accounts` does not exist (or 404).

- [ ] **Step 3: Create `OrgBankAccountService`**

`app/Services/Finance/OrgBankAccountService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OrgBankAccountService
{
    public function list(array $filters = []): Collection
    {
        $q = OrgBankAccount::query()->with('glAccount');

        if (! empty($filters['purpose']))   $q->where('purpose', $filters['purpose']);
        if (array_key_exists('is_active', $filters)) $q->where('is_active', (bool) $filters['is_active']);

        return $q->orderBy('bank_name')->orderBy('account_name')->get();
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = OrgBankAccount::query()->with('glAccount');
        if (! empty($filters['purpose'])) $q->where('purpose', $filters['purpose']);

        return $q->orderBy('bank_name')->paginate($perPage)->withQueryString();
    }

    public function create(array $data): OrgBankAccount
    {
        $this->assertGlAccountIsAsset((int) $data['gl_account_id']);
        return OrgBankAccount::create($data);
    }

    public function update(OrgBankAccount $account, array $data): OrgBankAccount
    {
        if (isset($data['gl_account_id']) && (int) $data['gl_account_id'] !== $account->gl_account_id) {
            $this->assertGlAccountIsAsset((int) $data['gl_account_id']);
        }
        $account->update($data);
        return $account->fresh('glAccount');
    }

    public function archive(OrgBankAccount $account): void
    {
        $account->delete();
    }

    private function assertGlAccountIsAsset(int $glAccountId): void
    {
        $gl = GlAccount::findOrFail($glAccountId);
        if ($gl->type !== GlAccountType::Asset) {
            throw new DomainException('Linked GL account must be of type asset.');
        }
    }
}
```

- [ ] **Step 4: Create `StoreOrgBankAccountRequest`**

`app/Http/Requests/Finance/StoreOrgBankAccountRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrgBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('bank_accounts.manage') === true;
    }

    public function rules(): array
    {
        return [
            'gl_account_id'   => [
                'required',
                'integer',
                'exists:gl_accounts,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $gl = GlAccount::find($value);
                    if ($gl && $gl->type !== GlAccountType::Asset) {
                        $fail('The linked GL account must be of type asset.');
                    }
                },
            ],
            'bank_name'       => ['required', 'string', 'max:150'],
            'branch'          => ['nullable', 'string', 'max:150'],
            'account_name'    => ['required', 'string', 'max:200'],
            'account_number'  => [
                'required', 'string', 'max:64',
                Rule::unique('org_bank_accounts')->where(fn ($q) => $q->where('bank_name', $this->input('bank_name'))),
            ],
            'sort_code'       => ['nullable', 'string', 'max:20'],
            'swift'           => ['nullable', 'string', 'max:20'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'purpose'         => ['required', Rule::enum(OrgBankAccountPurpose::class)],
            'opening_balance' => ['sometimes', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 5: Create `UpdateOrgBankAccountRequest`**

`app/Http/Requests/Finance/UpdateOrgBankAccountRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrgBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('bank_accounts.manage') === true;
    }

    public function rules(): array
    {
        $id = $this->route('bankAccount')?->id;

        return [
            'gl_account_id'   => [
                'required',
                'integer',
                'exists:gl_accounts,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $gl = GlAccount::find($value);
                    if ($gl && $gl->type !== GlAccountType::Asset) {
                        $fail('The linked GL account must be of type asset.');
                    }
                },
            ],
            'bank_name'       => ['required', 'string', 'max:150'],
            'branch'          => ['nullable', 'string', 'max:150'],
            'account_name'    => ['required', 'string', 'max:200'],
            'account_number'  => [
                'required', 'string', 'max:64',
                Rule::unique('org_bank_accounts')
                    ->where(fn ($q) => $q->where('bank_name', $this->input('bank_name')))
                    ->ignore($id),
            ],
            'sort_code'       => ['nullable', 'string', 'max:20'],
            'swift'           => ['nullable', 'string', 'max:20'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'purpose'         => ['required', Rule::enum(OrgBankAccountPurpose::class)],
            'opening_balance' => ['sometimes', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 6: Create `OrgBankAccountResource`**

`app/Http/Resources/Finance/OrgBankAccountResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\OrgBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrgBankAccount */
class OrgBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canManage = $request->user()?->hasPermission('bank_accounts.manage') === true;
        $accountNumber = (string) $this->account_number;

        return [
            'id'              => $this->id,
            'gl_account'      => new GlAccountResource($this->whenLoaded('glAccount')),
            'bank_name'       => $this->bank_name,
            'branch'          => $this->branch,
            'account_name'    => $this->account_name,
            'account_number'  => $canManage
                ? $accountNumber
                : str_repeat('•', max(4, strlen($accountNumber) - 4)) . substr($accountNumber, -4),
            'sort_code'       => $this->sort_code,
            'swift'           => $this->swift,
            'currency'        => $this->currency,
            'purpose'         => [
                'value' => $this->purpose->value,
                'label' => $this->purpose->label(),
            ],
            'opening_balance' => (float) $this->opening_balance,
            'is_active'       => $this->is_active,
            'notes'           => $this->notes,
        ];
    }
}
```

- [ ] **Step 7: Create `OrgBankAccountController`**

`app/Http/Controllers/Finance/OrgBankAccountController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreOrgBankAccountRequest;
use App\Http\Requests\Finance\UpdateOrgBankAccountRequest;
use App\Http\Resources\Finance\OrgBankAccountResource;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Services\Finance\OrgBankAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgBankAccountController extends Controller
{
    public function __construct(private readonly OrgBankAccountService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['purpose']);
        $banks = $this->service->list($filters);

        return Inertia::render('Finance/BankAccounts/Index', [
            'banks'        => OrgBankAccountResource::collection($banks),
            'filters'      => $filters,
            'assetAccounts'=> GlAccount::ofType('asset')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreOrgBankAccountRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());
        return back()->with('success', 'Bank account created.');
    }

    public function update(UpdateOrgBankAccountRequest $request, OrgBankAccount $bankAccount): RedirectResponse
    {
        $this->service->update($bankAccount, $request->validated());
        return back()->with('success', 'Bank account updated.');
    }

    public function destroy(OrgBankAccount $bankAccount): RedirectResponse
    {
        $this->service->archive($bankAccount);
        return back()->with('success', 'Bank account archived.');
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

```
php artisan test --filter=OrgBankAccountTest
```
Expected: PASS, 7 tests.

- [ ] **Step 9: Commit**

```
git add app/Services/Finance/OrgBankAccountService.php app/Http/Requests/Finance/StoreOrgBankAccountRequest.php app/Http/Requests/Finance/UpdateOrgBankAccountRequest.php app/Http/Resources/Finance/OrgBankAccountResource.php app/Http/Controllers/Finance/OrgBankAccountController.php tests/Feature/Finance/OrgBankAccountTest.php
git commit -m "feat(finance): org bank account CRUD with masked-number RBAC"
```

---

## Task 9: Finance Hub Service + Controller

**Files:**
- Create: `app/Services/Finance/FinanceHubService.php`
- Create: `app/Http/Controllers/Finance/FinanceHubController.php`
- Test: `tests/Feature/Finance/FinanceHubTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/FinanceHubTest.php`:

```php
<?php

use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('renders the hub for finance_officer with expected aggregate keys', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('cashPosition')
            ->has('outstandingLoans')
            ->has('pendingApprovals')
            ->has('statutoryCompliance')
            ->has('bankAccounts')
            ->has('nextPayroll')
        );
});

it('forbids employees from accessing the hub', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get('/finance')
        ->assertForbidden();
});

it('hub cash position equals the sum of active bank account opening balances', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    // Seed includes 3 banks at zero opening balance.
    $this->actingAs($finance)
        ->get('/finance')
        ->assertInertia(fn ($p) => $p->where('cashPosition', 0.0));

    // Now bump one bank's opening_balance and re-test.
    $bank = \App\Models\OrgBankAccount::first();
    $bank->update(['opening_balance' => 100000.00]);

    // Hub uses 60s cache; flush.
    \Illuminate\Support\Facades\Cache::flush();

    $this->actingAs($finance)
        ->get('/finance')
        ->assertInertia(fn ($p) => $p->where('cashPosition', 100000.0));
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --filter=FinanceHubTest
```
Expected: FAIL — route `/finance` not yet implemented.

- [ ] **Step 3: Create `FinanceHubService`**

`app/Services/Finance/FinanceHubService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PayrollRunStatus;
use App\Enums\LoanStatus;
use App\Models\LoanAccount;
use App\Models\OrgBankAccount;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class FinanceHubService
{
    public function summaryFor(User $user, int $ttlSeconds = 60): array
    {
        return Cache::remember(
            "finance.hub.summary.user.{$user->id}",
            $ttlSeconds,
            fn () => $this->build(),
        );
    }

    private function build(): array
    {
        return [
            'cashPosition'        => $this->cashPosition(),
            'bankAccounts'        => $this->bankAccountsSummary(),
            'nextPayroll'         => $this->nextPayroll(),
            'outstandingLoans'    => $this->outstandingLoans(),
            'pendingApprovals'    => $this->pendingApprovals(),
            'statutoryCompliance' => $this->statutoryCompliance(),
        ];
    }

    private function cashPosition(): float
    {
        return (float) OrgBankAccount::active()->sum('opening_balance');
    }

    private function bankAccountsSummary(): array
    {
        return OrgBankAccount::active()
            ->with('glAccount:id,code,name')
            ->orderBy('bank_name')
            ->get()
            ->map(fn (OrgBankAccount $b) => [
                'id'              => $b->id,
                'bank_name'       => $b->bank_name,
                'account_name'    => $b->account_name,
                'purpose'         => $b->purpose->label(),
                'opening_balance' => (float) $b->opening_balance,
                'gl_code'         => $b->glAccount?->code,
            ])
            ->all();
    }

    private function nextPayroll(): ?array
    {
        $run = PayrollRun::query()
            ->whereIn('status', [
                PayrollRunStatus::Draft->value ?? 'draft',
                PayrollRunStatus::Calculated->value ?? 'calculated',
                PayrollRunStatus::Pending->value ?? 'pending',
            ])
            ->orderBy('period_start')
            ->first();

        if (! $run) return null;

        return [
            'reference'     => $run->reference,
            'period_start'  => $run->period_start?->format('Y-m-d'),
            'period_end'    => $run->period_end?->format('Y-m-d'),
            'status'        => $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status,
            'participant_count' => $run->lines()->count(),
            'projected_net' => (float) $run->lines()->sum('net_pay'),
        ];
    }

    private function outstandingLoans(): array
    {
        $activeStatuses = [
            LoanStatus::Disbursed->value ?? 'disbursed',
            LoanStatus::Active->value    ?? 'active',
        ];

        return [
            'count'        => LoanAccount::whereIn('status', $activeStatuses)->count(),
            'total_balance' => (float) LoanAccount::whereIn('status', $activeStatuses)->sum('balance'),
        ];
    }

    private function pendingApprovals(): array
    {
        $pendingPayrollStatuses = [
            PayrollRunStatus::Pending->value      ?? 'pending',
            PayrollRunStatus::Calculated->value   ?? 'calculated',
        ];

        $pendingLoanStatuses = [
            LoanStatus::PendingApproval->value ?? 'pending_approval',
        ];

        return [
            'payroll_runs' => PayrollRun::whereIn('status', $pendingPayrollStatuses)->count(),
            'loans'        => LoanAccount::whereIn('status', $pendingLoanStatuses)->count(),
        ];
    }

    private function statutoryCompliance(): array
    {
        // Take the most recent StatutoryReturn row per kind; if none, mark as `due`.
        $latest = StatutoryReturn::query()
            ->orderBy('kind')
            ->orderByDesc('period_end')
            ->get()
            ->unique('kind')
            ->values();

        return $latest
            ->map(fn (StatutoryReturn $r) => [
                'kind'        => $r->kind instanceof \BackedEnum ? $r->kind->value : (string) $r->kind,
                'period_end'  => $r->period_end?->format('Y-m-d'),
                'status'      => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status,
            ])
            ->all();
    }
}
```

NOTE: The `?? 'value'` fallbacks above are defensive — if a PayrollRunStatus/LoanStatus case happens to be missing on a given branch, the literal fallback prevents a fatal error. In practice all cases exist; the safety net keeps the hub from breaking due to enum-rename refactors elsewhere.

- [ ] **Step 4: Create `FinanceHubController`**

`app/Http/Controllers/Finance/FinanceHubController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceHubService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceHubController extends Controller
{
    public function __construct(private readonly FinanceHubService $service)
    {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Hub', $this->service->summaryFor($request->user()));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```
php artisan test --filter=FinanceHubTest
```
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```
git add app/Services/Finance/FinanceHubService.php app/Http/Controllers/Finance/FinanceHubController.php tests/Feature/Finance/FinanceHubTest.php
git commit -m "feat(finance): FinanceHubService aggregates and Hub controller"
```

---

## Task 10: Finance Hub Inertia Page

**Files:**
- Create: `resources/js/Pages/Finance/Hub.vue`

- [ ] **Step 1: Create the hub page**

`resources/js/Pages/Finance/Hub.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    cashPosition:        { type: Number, default: 0 },
    bankAccounts:        { type: Array,  default: () => [] },
    nextPayroll:         { type: [Object, null], default: null },
    outstandingLoans:    { type: Object, default: () => ({ count: 0, total_balance: 0 }) },
    pendingApprovals:    { type: Object, default: () => ({ payroll_runs: 0, loans: 0 }) },
    statutoryCompliance: { type: Array,  default: () => [] },
});

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
});

const cediShort = (v) => {
    const n = Number(v) || 0;
    if (n >= 1_000_000) return 'GHS ' + (n / 1_000_000).toFixed(2) + 'M';
    if (n >= 1_000)     return 'GHS ' + (n / 1_000).toFixed(1) + 'k';
    return 'GHS ' + n.toFixed(2);
};

const totalPendingCount = computed(() => props.pendingApprovals.payroll_runs + props.pendingApprovals.loans);

const statusBadge = (status) => {
    const s = (status || '').toLowerCase();
    if (s.includes('filed') || s === 'submitted' || s === 'accepted') {
        return 'text-green-600 bg-green-50 border-green-100';
    }
    if (s === 'pending' || s === 'draft') {
        return 'text-amber-600 bg-amber-50 border-amber-100';
    }
    return 'text-blue-600 bg-blue-50 border-blue-100';
};
</script>

<template>
    <Head title="Finance Hub" />

    <div class="space-y-8 animate-reveal-up">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary"
                          style="font-variation-settings:'FILL' 1">account_balance</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE HUB</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Treasury &amp; Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Real-time view of the institute's cash position, payroll cycle, statutory compliance and pending approvals.
                </p>
            </div>
            <div class="flex gap-2">
                <Link :href="route('finance.accounts.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">account_tree</span>
                    Chart of Accounts
                </Link>
                <Link :href="route('finance.bank-accounts.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">account_balance_wallet</span>
                    Bank Accounts
                </Link>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Cash Position</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ cediShort(cashPosition) }}</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">Across {{ bankAccounts.length }} active accounts</p>
            </div>

            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Outstanding Loans</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ cediShort(outstandingLoans.total_balance) }}</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">{{ outstandingLoans.count }} active loans</p>
            </div>

            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Pending Approvals</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ totalPendingCount }}</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">
                    {{ pendingApprovals.payroll_runs }} payroll · {{ pendingApprovals.loans }} loans
                </p>
            </div>

            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">Next Payroll Run</p>
                <p v-if="nextPayroll" class="mt-2 text-2xl font-black text-primary">
                    {{ cediShort(nextPayroll.projected_net) }}
                </p>
                <p v-else class="mt-2 text-2xl font-black text-primary">—</p>
                <p class="mt-1 text-[10px] text-on-surface-variant">
                    <span v-if="nextPayroll">{{ nextPayroll.reference }} · {{ nextPayroll.participant_count }} staff</span>
                    <span v-else>No upcoming run</span>
                </p>
            </div>
        </div>

        <!-- Two-column body -->
        <div class="grid gap-6 lg:grid-cols-12">
            <!-- Left: Bank Accounts + Compliance -->
            <div class="lg:col-span-7 space-y-6">
                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Organisational Bank Accounts</h4>
                    <div v-if="bankAccounts.length" class="space-y-2.5">
                        <div v-for="b in bankAccounts" :key="b.id"
                             class="flex items-center justify-between rounded-xl border border-outline-variant/50 p-3">
                            <div>
                                <p class="text-[12px] font-bold text-primary">{{ b.bank_name }} · {{ b.account_name }}</p>
                                <p class="text-[10px] font-medium text-on-surface-variant">{{ b.purpose }} · GL {{ b.gl_code }}</p>
                            </div>
                            <p class="text-[13px] font-black text-primary">{{ cedi(b.opening_balance) }}</p>
                        </div>
                    </div>
                    <p v-else class="text-[12px] text-on-surface-variant">No active bank accounts.</p>
                </section>

                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Statutory Compliance</h4>
                    <div v-if="statutoryCompliance.length" class="space-y-3">
                        <div v-for="s in statutoryCompliance" :key="s.kind"
                             class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5 flex-1 min-w-0 mr-3">
                                <span class="material-symbols-outlined text-[16px] flex-shrink-0">verified</span>
                                <p class="text-[11.5px] font-bold text-on-surface-variant truncate">
                                    {{ s.kind }} · {{ s.period_end || '—' }}
                                </p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border flex-shrink-0"
                                  :class="statusBadge(s.status)">{{ s.status }}</span>
                        </div>
                    </div>
                    <p v-else class="text-[12px] text-on-surface-variant">No statutory returns recorded yet.</p>
                </section>
            </div>

            <!-- Right: Next Payroll + Outstanding Loans -->
            <div class="lg:col-span-5 space-y-6">
                <section v-if="nextPayroll" class="rounded-2xl p-6 text-white relative overflow-hidden"
                         style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
                    <div class="absolute -right-4 -top-4 opacity-10">
                        <span class="material-symbols-outlined text-9xl">payments</span>
                    </div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-2"
                       style="color:rgba(255,255,255,0.35)">Next Payroll Run</p>
                    <p class="text-3xl font-black mb-1">{{ cedi(nextPayroll.projected_net) }}</p>
                    <p class="text-[10px] mb-5" style="color:rgba(255,255,255,0.45)">
                        {{ nextPayroll.reference }} · {{ nextPayroll.period_start }} → {{ nextPayroll.period_end }}
                    </p>
                    <p class="text-[11px]" style="color:rgba(255,255,255,0.65)">
                        {{ nextPayroll.participant_count }} staff · status {{ nextPayroll.status }}
                    </p>
                </section>

                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Outstanding Loans</h4>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-medium text-on-surface-variant">Total balance</p>
                            <p class="text-2xl font-black text-primary">{{ cedi(outstandingLoans.total_balance) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-medium text-on-surface-variant">Active loans</p>
                            <p class="text-2xl font-black text-primary">{{ outstandingLoans.count }}</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Re-run the hub test to confirm component name resolves**

```
php artisan test --filter=FinanceHubTest
```
Expected: still PASS (the component name `Finance/Hub` resolves to this file via Inertia's standard path resolver).

- [ ] **Step 3: Commit**

```
git add resources/js/Pages/Finance/Hub.vue
git commit -m "feat(finance): Finance Hub landing page"
```

---

## Task 11: Chart of Accounts Inertia Page

**Files:**
- Create: `resources/js/Pages/Finance/Accounts/Index.vue`

- [ ] **Step 1: Create the page**

`resources/js/Pages/Finance/Accounts/Index.vue`:

```vue
<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    tree:    { type: Object, required: true },   // { data: GlAccountResource[] }
    flat:    { type: Object, required: true },   // { data: GlAccountResource[] }
    filters: { type: Object, default: () => ({}) },
});

const treeRows = computed(() => props.tree.data ?? props.tree ?? []);
const flatRows = computed(() => props.flat.data ?? props.flat ?? []);

const typeFilter = ref(props.filters.type ?? '');
const searchTerm = ref(props.filters.search ?? '');

const applyFilters = () => {
    router.get(route('finance.accounts.index'), {
        type:   typeFilter.value || undefined,
        search: searchTerm.value || undefined,
    }, { preserveState: true, replace: true });
};

let searchTimer = null;
watch(searchTerm, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 320);
});

// ── Slide panel ──
const panelOpen  = ref(false);
const editing    = ref(null);

const blank = () => ({ code: '', name: '', type: 'asset', parent_id: null, is_active: true, description: '' });

const form = useForm(blank());

const openNew = () => {
    editing.value = null;
    form.reset();
    Object.assign(form, blank());
    panelOpen.value = true;
};

const openEdit = (account) => {
    editing.value = account;
    Object.assign(form, {
        code:        account.code,
        name:        account.name,
        type:        account.type.value,
        parent_id:   account.parent_id,
        is_active:   account.is_active,
        description: account.description ?? '',
    });
    panelOpen.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('finance.accounts.update', editing.value.id), {
            onSuccess: () => { panelOpen.value = false; },
        });
    } else {
        form.post(route('finance.accounts.store'), {
            onSuccess: () => { panelOpen.value = false; },
        });
    }
};

const archive = (account) => {
    if (! confirm(`Archive account ${account.code} (${account.name})?`)) return;
    router.delete(route('finance.accounts.destroy', account.id));
};

const typeColor = (typeValue) => ({
    asset:     'text-emerald-700 bg-emerald-50 border-emerald-100',
    liability: 'text-rose-700 bg-rose-50 border-rose-100',
    equity:    'text-violet-700 bg-violet-50 border-violet-100',
    income:    'text-blue-700 bg-blue-50 border-blue-100',
    expense:   'text-amber-700 bg-amber-50 border-amber-100',
}[typeValue] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="Chart of Accounts" />

    <div class="space-y-6 animate-reveal-up">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Chart of Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    General-ledger account catalogue. {{ flatRows.length }} accounts.
                </p>
            </div>
            <PrimaryButton @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">add</span>
                New Account
            </PrimaryButton>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 items-center">
            <button v-for="t in [
                { v: '',          label: 'All' },
                { v: 'asset',     label: 'Assets' },
                { v: 'liability', label: 'Liabilities' },
                { v: 'equity',    label: 'Equity' },
                { v: 'income',    label: 'Income' },
                { v: 'expense',   label: 'Expenses' },
            ]" :key="t.v"
                @click="typeFilter = t.v; applyFilters();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    typeFilter === t.v
                        ? 'bg-primary text-on-primary border-primary'
                        : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
            <input v-model="searchTerm" type="text" placeholder="Search code or name..."
                   class="ml-auto rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest" />
        </div>

        <!-- Table -->
        <div v-if="flatRows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Code</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Name</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Type</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Balance</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="acc in flatRows" :key="acc.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ acc.code }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ acc.name }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="typeColor(acc.type.value)">
                                {{ acc.type.label }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-primary">
                            GHS {{ (acc.balance ?? 0).toFixed(2) }}
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            <button @click="openEdit(acc)" class="text-[11px] font-bold text-secondary hover:underline">Edit</button>
                            <button @click="archive(acc)"  class="text-[11px] font-bold text-rose-600 hover:underline">Archive</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="account_tree"
                    title="No accounts match the filters"
                    description="Adjust the type filter or search term, or seed a chart of accounts." />

        <!-- Slide panel -->
        <SlidePanel :open="panelOpen" @close="panelOpen = false"
                    :title="editing ? `Edit ${editing.code}` : 'New GL Account'">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="code" value="Code" />
                    <TextInput id="code" v-model="form.code" class="mt-1 block w-full" />
                    <InputError :message="form.errors.code" />
                </div>
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="type" value="Type" />
                    <select id="type" v-model="form.type"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option value="asset">Asset</option>
                        <option value="liability">Liability</option>
                        <option value="equity">Equity</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                    <InputError :message="form.errors.type" />
                </div>
                <div>
                    <InputLabel for="parent_id" value="Parent account" />
                    <select id="parent_id" v-model="form.parent_id"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">— (root)</option>
                        <option v-for="acc in flatRows" :key="acc.id"
                                :value="acc.id"
                                :disabled="editing && acc.id === editing.id">
                            {{ acc.code }} — {{ acc.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.parent_id" />
                </div>
                <div>
                    <InputLabel for="description" value="Description" />
                    <textarea id="description" v-model="form.description" rows="3"
                              class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                    <InputError :message="form.errors.description" />
                </div>
                <div class="flex items-center gap-2">
                    <input id="is_active" type="checkbox" v-model="form.is_active" class="rounded border-outline-variant" />
                    <label for="is_active" class="text-[12px] font-bold text-on-surface-variant">Active</label>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false"
                            class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editing ? 'Save' : 'Create' }}
                    </PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>
```

- [ ] **Step 2: Run accounts feature tests again to confirm page binds**

```
php artisan test --filter=ChartOfAccountsTest
```
Expected: PASS, 7 tests.

- [ ] **Step 3: Commit**

```
git add resources/js/Pages/Finance/Accounts/Index.vue
git commit -m "feat(finance): chart of accounts index page with slide panel"
```

---

## Task 12: Org Bank Accounts Inertia Page

**Files:**
- Create: `resources/js/Pages/Finance/BankAccounts/Index.vue`

- [ ] **Step 1: Create the page**

`resources/js/Pages/Finance/BankAccounts/Index.vue`:

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    banks:         { type: Object, required: true }, // { data: [...] }
    assetAccounts: { type: Array,  default: () => [] },
    filters:       { type: Object, default: () => ({}) },
});

const rows = computed(() => props.banks.data ?? props.banks ?? []);

const panelOpen = ref(false);
const editing   = ref(null);

const blank = () => ({
    gl_account_id:   null,
    bank_name:       '',
    branch:          '',
    account_name:    '',
    account_number:  '',
    sort_code:       '',
    swift:           '',
    currency:        'GHS',
    purpose:         'operating',
    opening_balance: 0,
    is_active:       true,
    notes:           '',
});

const form = useForm(blank());

const openNew = () => {
    editing.value = null;
    form.reset();
    Object.assign(form, blank());
    panelOpen.value = true;
};

const openEdit = (bank) => {
    editing.value = bank;
    Object.assign(form, {
        gl_account_id:   bank.gl_account?.id ?? null,
        bank_name:       bank.bank_name,
        branch:          bank.branch ?? '',
        account_name:    bank.account_name,
        account_number:  bank.account_number,
        sort_code:       bank.sort_code ?? '',
        swift:           bank.swift ?? '',
        currency:        bank.currency,
        purpose:         bank.purpose.value,
        opening_balance: bank.opening_balance,
        is_active:       bank.is_active,
        notes:           bank.notes ?? '',
    });
    panelOpen.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('finance.bank-accounts.update', editing.value.id), {
            onSuccess: () => { panelOpen.value = false; },
        });
    } else {
        form.post(route('finance.bank-accounts.store'), {
            onSuccess: () => { panelOpen.value = false; },
        });
    }
};

const archive = (bank) => {
    if (! confirm(`Archive ${bank.bank_name} — ${bank.account_name}?`)) return;
    router.delete(route('finance.bank-accounts.destroy', bank.id));
};

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
});

const purposeColor = (val) => ({
    operating:        'text-blue-700 bg-blue-50 border-blue-100',
    payroll:          'text-emerald-700 bg-emerald-50 border-emerald-100',
    statutory_escrow: 'text-amber-700 bg-amber-50 border-amber-100',
    receipts:         'text-violet-700 bg-violet-50 border-violet-100',
    reserve:          'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="Organisational Bank Accounts" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Organisational Bank Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    The institute's own bank accounts. {{ rows.length }} total.
                </p>
            </div>
            <PrimaryButton @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">add</span>
                New Bank Account
            </PrimaryButton>
        </div>

        <div v-if="rows.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="bank in rows" :key="bank.id"
                     class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-3">
                <header class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">{{ bank.bank_name }}</p>
                        <p class="text-[14px] font-black text-primary leading-tight">{{ bank.account_name }}</p>
                        <p class="text-[10px] font-medium text-on-surface-variant mt-0.5">{{ bank.branch || '—' }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border whitespace-nowrap"
                          :class="purposeColor(bank.purpose.value)">{{ bank.purpose.label }}</span>
                </header>
                <p class="font-mono text-[12px] text-on-surface tracking-wider">{{ bank.account_number }}</p>
                <p class="text-[10px] text-on-surface-variant">GL {{ bank.gl_account?.code }} — {{ bank.gl_account?.name }}</p>
                <p class="text-[13px] font-black text-primary">Opening: {{ cedi(bank.opening_balance) }}</p>
                <div class="flex gap-2 pt-1">
                    <button @click="openEdit(bank)" class="text-[11px] font-bold text-secondary hover:underline">Edit</button>
                    <button @click="archive(bank)"  class="text-[11px] font-bold text-rose-600 hover:underline">Archive</button>
                </div>
            </article>
        </div>
        <EmptyState v-else icon="account_balance_wallet"
                    title="No bank accounts yet"
                    description="Add the institute's operating, payroll and escrow accounts." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false"
                    :title="editing ? `Edit ${editing.bank_name}` : 'New Bank Account'">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="gl_account_id" value="Linked GL account (asset)" />
                    <select id="gl_account_id" v-model="form.gl_account_id"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="a in assetAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                    </select>
                    <InputError :message="form.errors.gl_account_id" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="bank_name" value="Bank" />
                        <TextInput id="bank_name" v-model="form.bank_name" class="mt-1 block w-full" />
                        <InputError :message="form.errors.bank_name" />
                    </div>
                    <div>
                        <InputLabel for="branch" value="Branch" />
                        <TextInput id="branch" v-model="form.branch" class="mt-1 block w-full" />
                    </div>
                </div>
                <div>
                    <InputLabel for="account_name" value="Account name" />
                    <TextInput id="account_name" v-model="form.account_name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.account_name" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="account_number" value="Account number" />
                        <TextInput id="account_number" v-model="form.account_number" class="mt-1 block w-full" />
                        <InputError :message="form.errors.account_number" />
                    </div>
                    <div>
                        <InputLabel for="sort_code" value="Sort code" />
                        <TextInput id="sort_code" v-model="form.sort_code" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="swift" value="SWIFT" />
                        <TextInput id="swift" v-model="form.swift" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="currency" value="Currency" />
                        <TextInput id="currency" v-model="form.currency" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="purpose" value="Purpose" />
                        <select id="purpose" v-model="form.purpose"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option value="operating">Operating</option>
                            <option value="payroll">Payroll</option>
                            <option value="statutory_escrow">Statutory Escrow</option>
                            <option value="receipts">Receipts</option>
                            <option value="reserve">Reserve</option>
                        </select>
                        <InputError :message="form.errors.purpose" />
                    </div>
                    <div>
                        <InputLabel for="opening_balance" value="Opening balance" />
                        <TextInput id="opening_balance" v-model="form.opening_balance" type="number" step="0.01" class="mt-1 block w-full" />
                        <InputError :message="form.errors.opening_balance" />
                    </div>
                </div>
                <div>
                    <InputLabel for="notes" value="Notes" />
                    <textarea id="notes" v-model="form.notes" rows="3"
                              class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                </div>
                <div class="flex items-center gap-2">
                    <input id="is_active" type="checkbox" v-model="form.is_active" class="rounded border-outline-variant" />
                    <label for="is_active" class="text-[12px] font-bold text-on-surface-variant">Active</label>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false"
                            class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editing ? 'Save' : 'Create' }}
                    </PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>
```

- [ ] **Step 2: Re-run bank-accounts feature tests**

```
php artisan test --filter=OrgBankAccountTest
```
Expected: PASS, 7 tests.

- [ ] **Step 3: Commit**

```
git add resources/js/Pages/Finance/BankAccounts/Index.vue
git commit -m "feat(finance): organisational bank accounts page"
```

---

## Task 13: Sidebar Nav Entry

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

The existing `navSections` computed has two role branches:
1. `super_admin || hr_admin` — rich, full sidebar; we add Finance to the first section's items.
2. Everyone else — minimal sidebar at the bottom of the function; we add Finance as its own section gated by `can('finance.hub')`.

- [ ] **Step 1: Add Finance to the admin branch**

Open `resources/js/Layouts/AuthenticatedLayout.vue`. In the first section of the admin branch (the one starting at the line `title: ''`), find this line:

```js
{ label: 'Loans',        route: 'loans.index',          module: 'loans',       icon: 'request_quote',  visible: can('loans.view') || can('loans.apply') },
```

Immediately after it, add:

```js
{ label: 'Finance',       route: 'finance.hub',          module: 'finance',     icon: 'account_balance', visible: can('finance.hub') },
```

- [ ] **Step 2: Add Finance to the non-admin branch**

Scroll down to the final `return [ ... ];` block (the one for `employee`, `finance_officer`, etc.). It currently returns two sections. Modify it to inject a Finance section when the user has `finance.hub`:

Replace the final `return [ ... ];` block with:

```js
const sections = [
    {
        title: '',
        items: [
            { label: 'Dashboard',        route: 'dashboard',        module: 'overview',   icon: 'grid_view',      visible: true },
            { label: 'Tasks',            route: 'modules.tickets',  module: 'tickets',    icon: 'task_alt',       visible: true },
            { label: 'Documents',        route: 'documents.index',  module: 'documents',  icon: 'description',    visible: can('documents.view') },
            { label: 'Leave & Time-Off', route: 'modules.leave',    module: 'leave',      icon: 'calendar_today', visible: true },
            { label: 'Benefits',         route: 'dashboard',        module: 'benefits',   icon: 'diversity_3',    visible: true },
            { label: 'Learning & Dev',   route: 'learning.catalog', module: 'learning',   icon: 'school',         visible: true },
        ],
    },
];

if (can('finance.hub') || can('accounts.view') || can('bank_accounts.view')) {
    sections.push({
        title: 'Finance',
        items: [
            { label: 'Finance Hub',        route: 'finance.hub',                module: 'finance',                  icon: 'account_balance',         visible: can('finance.hub') },
            { label: 'Chart of Accounts',  route: 'finance.accounts.index',     module: 'finance-accounts',         icon: 'account_tree',            visible: can('accounts.view') },
            { label: 'Bank Accounts',      route: 'finance.bank-accounts.index',module: 'finance-bank-accounts',    icon: 'account_balance_wallet',  visible: can('bank_accounts.view') },
        ],
    });
}

sections.push({
    title: 'Support',
    items: [
        { label: 'My Profile', route: 'profile.edit', module: 'profile',  icon: 'person',   visible: true },
        { label: 'Settings',   route: 'profile.edit', module: 'settings', icon: 'settings', visible: true },
    ],
});

return sections;
```

- [ ] **Step 3: Smoke-test in browser**

Start dev server: `npm run dev`. Log in as a `finance_officer` user. Verify the sidebar shows a "Finance" section with three children: Finance Hub, Chart of Accounts, Bank Accounts. Click each one and confirm the page loads.

Then log in as `super_admin`. Verify the top section shows the single "Finance" entry pointing to `/finance` (because super_admin uses the admin branch). The new label appears between Loans and Off-boarding.

Then log in as an `employee`. Confirm the sidebar does NOT show any Finance section (no `finance.hub` permission).

- [ ] **Step 4: Commit**

```
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(finance): add Finance sidebar nav entries"
```

---

## Task 14: Acceptance Smoke

**Files:** none changed; this task verifies the full system.

- [ ] **Step 1: Run the full Finance test suite**

```
php artisan test --filter=Finance
```
Expected: all Finance tests PASS. Approximate count: ~38 tests (Enums 3 + Migrations 3 + Models 5 + Seeders 6 + Permissions 5 + Service 5 + Accounts feature 7 + BankAccounts feature 7 + Hub feature 3).

- [ ] **Step 2: Run the full test suite to catch regressions**

```
php artisan test
```
Expected: no NEW failures introduced by F1. Pre-existing failures unrelated to Finance are out of scope for this plan, but note them in the final report.

- [ ] **Step 3: Run `migrate:fresh --seed` end-to-end**

```
php artisan migrate:fresh --seed
```
Expected: completes without errors. Database should contain ≥30 GL accounts, 3 org bank accounts, matching balance rows at zero.

- [ ] **Step 4: Re-run the seeder to confirm idempotency**

```
php artisan db:seed --class=ChartOfAccountsSeeder
php artisan db:seed --class=OrgBankAccountSeeder
php artisan db:seed --class=GlAccountBalanceSeeder
```
Each should complete with no errors and no duplicate row creation.

Sanity check via tinker:
```
php artisan tinker --execute="echo App\\Models\\GlAccount::count() . ' / ' . App\\Models\\OrgBankAccount::count() . ' / ' . App\\Models\\GlAccountBalance::count();"
```
Expected output: `33 / 3 / 33` (or whatever your final account count is — `GlAccount` count should equal `GlAccountBalance` count, and `OrgBankAccount` should be 3).

- [ ] **Step 5: Browser smoke against the acceptance criteria in the spec**

Start `npm run dev` and `php artisan serve` (or `npm run dev` if it concurrent-runs both). Log in as a `finance_officer` and verify:

1. Sidebar "Finance" section is visible with Finance Hub / Chart of Accounts / Bank Accounts.
2. `/finance` renders the Hub with real KPIs (cash position should equal the sum of bank opening balances; no hard-coded "GHS 1.67M" strings).
3. `/finance/accounts` shows a list of ≥30 accounts grouped by type via filter chips.
4. Create a new GL account with code `9999`, name "Smoke Test", type "expense". Confirm it appears in the list.
5. Edit the same account, change the name. Confirm the change persists.
6. Archive the same account. Confirm it disappears from the list.
7. `/finance/bank-accounts` shows 3 seeded bank accounts as cards. Account numbers are fully visible.
8. Create a new bank account linked to GL code `1010` (Cash on Hand). Confirm it appears.

Log out, log in as `auditor`. Verify:

9. `/finance` returns 403 (auditor has no `finance.hub`).
10. `/finance/accounts` loads (auditor has `accounts.view`).
11. Attempting to click "New Account" or submitting via direct POST returns 403.
12. `/finance/bank-accounts` loads, and account numbers are masked (e.g., `••••2345`).

Log out, log in as an `employee`. Verify all three Finance URLs return 403.

- [ ] **Step 6: Final commit (only if any cleanup needed)**

If you spotted any drift during smoke testing (typos, broken styles, missing route names), fix them and commit:
```
git commit -m "fix(finance): smoke-test cleanup"
```
If nothing needs fixing, no commit is needed for this step.

---

## Done criteria

This plan is complete when:

1. All 14 tasks above are checked off.
2. All Pest Feature/Unit tests under `tests/Feature/Finance/` and `tests/Unit/Finance/` pass.
3. `php artisan migrate:fresh --seed` completes cleanly and the seeder is idempotent on re-run.
4. A `finance_officer` user can navigate the Finance Hub, manage GL accounts, and manage org bank accounts via the UI.
5. RBAC matrix from the spec §11 is verifiably enforced (finance_officer full, auditor read-only, employee 403).
