# Finance Sequence References Implementation Plan

**Goal:** Eliminate the `count()+1` race in all 11 Finance reference generators by routing them through a centralized `SequenceService` backed by a row-locked `finance_sequences` table.

**Architecture:** New `finance_sequences (key PK, current_value)` table; new `App\Services\Finance\SequenceService::next(string $key): int` uses `DB::transaction` + `lockForUpdate()`; each affected service swaps its `count+1` body for `$this->sequences->next("<scope>:<year>")`. Migration seeds `current_value` from existing reference strings so no duplicates are issued post-deploy.

**Tech Stack:** Laravel 13.7, Postgres + SQLite (CI matrix), Pest.

**Spec:** [docs/superpowers/specs/2026-05-23-finance-sequence-references-design.md](../specs/2026-05-23-finance-sequence-references-design.md)

---

### Task 1: Create `finance_sequences` table + seed migration

**Files:**
- Create: `database/migrations/2026_06_11_000001_create_finance_sequences.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_sequences', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        $seeds = [
            ['key' => 'app_payment',      'table' => 'ap_payments',      'prefix' => 'APP-'],
            ['key' => 'ap_invoice',       'table' => 'vendor_invoices',  'prefix' => 'API-'],
            ['key' => 'ar_invoice',       'table' => 'ar_invoices',      'prefix' => 'ARI-'],
            ['key' => 'ar_receipt',       'table' => 'ar_receipts',      'prefix' => 'ARC-'],
            ['key' => 'payment_intent',   'table' => 'payment_intents',  'prefix' => 'PI-'],
            ['key' => 'journal',          'table' => 'journal_entries',  'prefix' => 'JE-'],
            ['key' => 'journal_reversal', 'table' => 'journal_entries',  'prefix' => 'JR-'],
        ];

        foreach ($seeds as $s) {
            if (!Schema::hasTable($s['table'])) {
                continue;
            }

            $rows = DB::table($s['table'])
                ->where('reference', 'like', $s['prefix'] . '%')
                ->pluck('reference');

            $byYear = [];
            foreach ($rows as $ref) {
                $parts = explode('-', (string) $ref);
                if (count($parts) < 3) {
                    continue;
                }
                $year = $parts[1];
                $num  = (int) $parts[2];
                $byYear[$year] = max($byYear[$year] ?? 0, $num);
            }

            foreach ($byYear as $year => $max) {
                DB::table('finance_sequences')->insert([
                    'key'           => "{$s['key']}:{$year}",
                    'current_value' => $max,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_sequences');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `Migrating: 2026_06_11_000001_create_finance_sequences` then `Migrated`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_11_000001_create_finance_sequences.php
git commit -m "feat(finance): add finance_sequences table for atomic reference generation"
```

---

### Task 2: `SequenceService` + unit test

**Files:**
- Create: `app/Services/Finance/SequenceService.php`
- Test:   `tests/Unit/Finance/SequenceServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\Finance\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns 1 for a brand-new key', function () {
    expect(app(SequenceService::class)->next('test_key:2026'))->toBe(1);
});

it('returns monotonic increments on repeated calls', function () {
    $svc = app(SequenceService::class);
    $values = collect(range(1, 5))->map(fn () => $svc->next('test_key:2026'))->all();
    expect($values)->toBe([1, 2, 3, 4, 5]);
});

it('continues from a pre-seeded value', function () {
    DB::table('finance_sequences')->insert([
        'key'           => 'seeded:2026',
        'current_value' => 42,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(app(SequenceService::class)->next('seeded:2026'))->toBe(43);
});

it('keeps keys isolated', function () {
    $svc = app(SequenceService::class);
    $svc->next('a:2026');
    $svc->next('a:2026');
    $svc->next('a:2026');
    expect($svc->next('b:2026'))->toBe(1);
    expect($svc->next('a:2026'))->toBe(4);
});
```

- [ ] **Step 2: Run test to confirm it fails**

Run: `php artisan test --filter=SequenceServiceTest`
Expected: FAIL — `SequenceService` class does not exist.

- [ ] **Step 3: Implement `SequenceService`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

class SequenceService
{
    public function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('finance_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                DB::table('finance_sequences')->insert([
                    'key'           => $key,
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $current = 0;
            } else {
                $current = (int) $row->current_value;
            }

            $next = $current + 1;

            DB::table('finance_sequences')
                ->where('key', $key)
                ->update([
                    'current_value' => $next,
                    'updated_at'    => now(),
                ]);

            return $next;
        });
    }
}
```

- [ ] **Step 4: Run test to confirm it passes**

Run: `php artisan test --filter=SequenceServiceTest`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/SequenceService.php tests/Unit/Finance/SequenceServiceTest.php
git commit -m "feat(finance): SequenceService for atomic, row-locked reference numbering"
```

---

### Task 3: Refactor all 11 reference generators

**Files (modify only the listed methods + constructor of each):**
- `app/Services/Finance/ApPaymentService.php` — `nextReference` (`APP-`, width 4) + `nextJournalReference` (`JE-`, width 6)
- `app/Services/Finance/VendorInvoiceService.php` — `nextReference` (`API-`, width 4) + `nextJournalReference` (`JE-`, width 6)
- `app/Services/Finance/ArInvoiceService.php` — `nextReference` (`ARI-`, width 4) + `nextJournalReference` (`JE-`, width 6)
- `app/Services/Finance/ArReceiptService.php` — `nextReference` (`ARC-`, width 4) + `nextJournalReference` (`JE-`, width 6)
- `app/Services/Finance/PaymentIntentService.php` — `nextReference` (`PI-`, width 6)
- `app/Services/Finance/BankAdjustmentService.php` — `nextJournalReference` (`JE-`, width 6)
- `app/Services/Finance/JournalPostingService.php` — `nextReversalReference` (`JR-`, width 6)

**Mapping (scope key → prefix/width):**

| Scope key          | Prefix | Width |
| ------------------ | ------ | ----- |
| `app_payment`      | `APP-` | 4     |
| `ap_invoice`       | `API-` | 4     |
| `ar_invoice`       | `ARI-` | 4     |
| `ar_receipt`       | `ARC-` | 4     |
| `payment_intent`   | `PI-`  | 6     |
| `journal`          | `JE-`  | 6     |
| `journal_reversal` | `JR-`  | 6     |

- [ ] **Step 1: Refactor `ApPaymentService`**

In the constructor, add `private SequenceService $sequences` (preserve the existing parameters). Replace both methods:

```php
private function nextReference(): string
{
    $year = now()->format('Y');
    return sprintf('APP-%s-%04d', $year, $this->sequences->next("app_payment:{$year}"));
}

private function nextJournalReference(): string
{
    $year = now()->format('Y');
    return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
}
```

Add the import: `use App\Services\Finance\SequenceService;` (skip if same namespace already covers it — `ApPaymentService` and `SequenceService` are in the same namespace, so no import needed).

- [ ] **Step 2: Refactor `VendorInvoiceService` the same way**

```php
private function nextReference(): string
{
    $year = now()->format('Y');
    return sprintf('API-%s-%04d', $year, $this->sequences->next("ap_invoice:{$year}"));
}

private function nextJournalReference(): string
{
    $year = now()->format('Y');
    return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
}
```

- [ ] **Step 3: Refactor `ArInvoiceService`**

```php
private function nextReference(): string
{
    $year = now()->format('Y');
    return sprintf('ARI-%s-%04d', $year, $this->sequences->next("ar_invoice:{$year}"));
}

private function nextJournalReference(): string
{
    $year = now()->format('Y');
    return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
}
```

- [ ] **Step 4: Refactor `ArReceiptService`**

```php
private function nextReference(): string
{
    $year = now()->format('Y');
    return sprintf('ARC-%s-%04d', $year, $this->sequences->next("ar_receipt:{$year}"));
}

private function nextJournalReference(): string
{
    $year = now()->format('Y');
    return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
}
```

- [ ] **Step 5: Refactor `PaymentIntentService`**

```php
private function nextReference(): string
{
    $year = now()->format('Y');
    return sprintf('PI-%s-%06d', $year, $this->sequences->next("payment_intent:{$year}"));
}
```

- [ ] **Step 6: Refactor `BankAdjustmentService`**

```php
private function nextJournalReference(): string
{
    $year = now()->format('Y');
    return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
}
```

- [ ] **Step 7: Refactor `JournalPostingService`**

```php
private function nextReversalReference(): string
{
    $year = now()->format('Y');
    return sprintf('JR-%s-%06d', $year, $this->sequences->next("journal_reversal:{$year}"));
}
```

- [ ] **Step 8: Run the existing Finance test suite**

Run: `php artisan test --filter=Feature/Finance`
Expected: all tests still pass (refactor is internal — public API unchanged).

- [ ] **Step 9: Commit**

```bash
git add app/Services/Finance/ApPaymentService.php app/Services/Finance/VendorInvoiceService.php app/Services/Finance/ArInvoiceService.php app/Services/Finance/ArReceiptService.php app/Services/Finance/PaymentIntentService.php app/Services/Finance/BankAdjustmentService.php app/Services/Finance/JournalPostingService.php
git commit -m "refactor(finance): route all 11 reference generators through SequenceService"
```

---

### Task 4: Concurrency-style integration test

**Files:**
- Create: `tests/Feature/Finance/FinanceSequenceUniquenessTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Services\Finance\SequenceService;

it('SequenceService::next returns 50 distinct, monotonic values for a single key', function () {
    $svc = app(SequenceService::class);
    $values = [];
    for ($i = 0; $i < 50; $i++) {
        $values[] = $svc->next('stress:2026');
    }
    expect($values)->toHaveCount(50);
    expect(array_unique($values))->toHaveCount(50);
    expect($values)->toBe(range(1, 50));
});

it('different scope keys advance independently', function () {
    $svc = app(SequenceService::class);
    for ($i = 0; $i < 10; $i++) {
        $svc->next('alpha:2026');
    }
    for ($i = 0; $i < 3; $i++) {
        $svc->next('beta:2026');
    }
    expect($svc->next('alpha:2026'))->toBe(11);
    expect($svc->next('beta:2026'))->toBe(4);
});
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --filter=FinanceSequenceUniquenessTest`
Expected: 2 passed.

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: green across the board.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Finance/FinanceSequenceUniquenessTest.php
git commit -m "test(finance): SequenceService uniqueness + scope isolation coverage"
```

- [ ] **Step 5: Push branch and open PR**

```bash
git push -u origin fix/finance-sequence-references
gh pr create --title "fix(finance): concurrency-safe reference generation via SequenceService (C3)" --body "$(cat <<'EOF'
## Summary
- Adds `finance_sequences` table + `SequenceService::next()` with row-locked atomic increments
- Refactors all 11 race-prone `count()+1` reference generators across 7 Finance services to use it
- Migration seeds `current_value` from existing reference strings so no duplicates are issued post-deploy

## Spec
docs/superpowers/specs/2026-05-23-finance-sequence-references-design.md

## Test plan
- [x] `SequenceServiceTest` (unit) — monotonic, isolated keys, seeded start
- [x] `FinanceSequenceUniquenessTest` (feature) — 50 calls produce distinct sequential values
- [x] Full `Feature/Finance` suite stays green (internal refactor)
EOF
)"
```

---

## Self-Review

- **Spec coverage:** every service + method listed in the spec has a refactor step in Task 3; sequences table + service + tests in Tasks 1-2; uniqueness coverage in Task 4. ✅
- **Placeholders:** none. ✅
- **Type consistency:** `next(string $key): int` referenced identically across all caller refactors and the implementation. Scope keys spelled identically in seed migration, service refactors, and tests. ✅
