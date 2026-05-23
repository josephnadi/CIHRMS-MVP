# C3 — Concurrency-Safe Reference Generation (Finance)

**Status:** approved 2026-05-23 — Option A (sequences table)
**Branch:** `fix/finance-sequence-references`

## Problem

11 Finance service methods generate human-readable references using the same race-prone pattern:

```php
$count = Model::query()->where('reference', 'like', "<PREFIX>-{$year}-%")->count();
return sprintf('<PREFIX>-%s-%04d', $year, $count + 1);
```

Two concurrent transactions can observe the same `count` and emit the same reference. Today this is masked because there are no unique constraints on `reference` columns — duplicates would slip through silently and break audit reconciliation.

### Affected methods (7 services, 11 methods)

| Service                    | Method                  | Prefix | Width |
| -------------------------- | ----------------------- | ------ | ----- |
| `ApPaymentService`         | `nextReference`         | `APP-` | 4     |
| `ApPaymentService`         | `nextJournalReference`  | `JE-`  | 6     |
| `VendorInvoiceService`     | `nextReference`         | `API-` | 4     |
| `VendorInvoiceService`     | `nextJournalReference`  | `JE-`  | 6     |
| `ArInvoiceService`         | `nextReference`         | `ARI-` | 4     |
| `ArInvoiceService`         | `nextJournalReference`  | `JE-`  | 6     |
| `ArReceiptService`         | `nextReference`         | `ARC-` | 4     |
| `ArReceiptService`         | `nextJournalReference`  | `JE-`  | 6     |
| `PaymentIntentService`     | `nextReference`         | `PI-`  | 6     |
| `BankAdjustmentService`    | `nextJournalReference`  | `JE-`  | 6     |
| `JournalPostingService`    | `nextReversalReference` | `JR-`  | 6     |

All `JE-*` callers must share a single sequence (they all land in `journal_entries.reference`).

## Solution: `finance_sequences` table + `SequenceService`

### Schema

```sql
CREATE TABLE finance_sequences (
    key            VARCHAR(64) PRIMARY KEY,
    current_value  BIGINT NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL
);
```

### Key scheme

`<scope>:<year>` — e.g. `app_payment:2026`, `journal:2026`, `journal_reversal:2026`, `ap_invoice:2026`, `ar_invoice:2026`, `ar_receipt:2026`, `payment_intent:2026`.

### SequenceService

```php
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
                    'key' => $key,
                    'current_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
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
                    'updated_at' => now(),
                ]);

            return $next;
        });
    }
}
```

`lockForUpdate()` holds the row lock until the *outer* transaction commits — so when a service calls `SequenceService::next()` inside its own `DB::transaction(...)` for invoice creation, the lock is held for the whole invoice write. If the outer transaction rolls back, the increment rolls back too (correct: we don't burn numbers on failed writes).

### Migration: seed from existing data

The migration creates the table AND seeds `current_value` from existing references so the first post-deploy call doesn't issue `APP-2026-0001` when `APP-2026-0017` already exists.

```php
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
    $rows = DB::table($s['table'])
        ->where('reference', 'like', $s['prefix'].'%')
        ->pluck('reference');

    $byYear = [];
    foreach ($rows as $ref) {
        $parts = explode('-', $ref);
        if (count($parts) < 3) continue;
        [$_p, $year, $num] = $parts + [2 => 0];
        $byYear[$year] = max($byYear[$year] ?? 0, (int) $num);
    }

    foreach ($byYear as $year => $max) {
        DB::table('finance_sequences')->insert([
            'key' => "{$s['key']}:{$year}",
            'current_value' => $max,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

Cross-driver: works on Postgres + SQLite (no dialect-specific SQL).

### Caller refactor (pattern)

```php
// Before
private function nextReference(): string
{
    $year = now()->format('Y');
    $count = ApPayment::query()->where('reference', 'like', "APP-{$year}-%")->count();
    return sprintf('APP-%s-%04d', $year, $count + 1);
}

// After
public function __construct(
    private SequenceService $sequences,
    /* ...existing deps */
) {}

private function nextReference(): string
{
    $year = now()->format('Y');
    return sprintf('APP-%s-%04d', $year, $this->sequences->next("app_payment:{$year}"));
}
```

Width per caller stays in each caller's `sprintf` — `SequenceService` returns only the integer.

## Non-goals

- We do **not** add `UNIQUE` constraints on reference columns in this pass. Sequences guarantee uniqueness at generation time; a defensive UNIQUE constraint would require a separate data audit first (existing dupes from the race window would block the migration).
- We do **not** refactor the format strings or scope keys beyond what's listed above.
- We do **not** replace `Str::ulid()` usages that already exist for non-finance refs.

## Testing

1. `SequenceServiceTest` (Unit) — `next()` is monotonic; new key starts at 1; explicit seed value continues from there.
2. `FinanceSequenceConcurrencyTest` (Feature) — generate 50 references in a tight loop and assert all are distinct, sequential, and zero-padded correctly.
3. Existing service tests must continue to pass unchanged (the refactor is internal-only — public API of each service is identical).

## Out of scope (future work)

- Adding `UNIQUE` constraints on reference columns once a dupe audit confirms clean data.
- Migrating `Str::ulid()` callers to sequences (different design goals).
- Multi-region sequence partitioning (single-DB deployment for now).
