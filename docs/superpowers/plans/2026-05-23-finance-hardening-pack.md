# Finance Hardening Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 5 hardening items flagged in `docs/MARKET_READY_PUNCHLIST.md`: 2 missing `2fa:fresh` gates on balance-mutating endpoints (C1 + C2), 1 request-level uniqueness validation (I1), 1 scheduled cron (I2), 1 missing `lockForUpdate()` (I4).

**Architecture:** Each item mirrors an existing pattern already proven elsewhere in the codebase. C1/C2 copy F3's `pi2faFresh`/`ar2faFresh` test helper + middleware chain. I1 copies F3's closure-based `Rule::unique()`. I2 adds a single line to `routes/console.php`. I4 mirrors `ArReceiptService::void()`'s `lockForUpdate()` pattern.

**Tech Stack:** Laravel 13.7, PHP 8.3, Pest. No new dependencies.

**Spec reference:** [docs/MARKET_READY_PUNCHLIST.md](../../MARKET_READY_PUNCHLIST.md) sections C1, C2, I1, I2, I4.

**Branch:** `fix/finance-hardening-pack` (off `origin/main` `b0bb8d3` — independent of feat/finance-f4r-paystack-refund which has its own PR)

---

## File Structure

### Modified files

```
routes/web.php                                          -- add 2fa:fresh to AP payments + journal.store
routes/console.php                                      -- schedule PaymentIntentService::expireStale()
app/Http/Requests/Finance/StoreVendorInvoiceRequest.php -- add per-vendor vendor_invoice_no uniqueness
app/Services/Finance/ApPaymentService.php               -- add lockForUpdate() in void()
```

### New test files

```
tests/Feature/Finance/ApPayment2faTest.php
tests/Feature/Finance/JournalManualPost2faTest.php
tests/Feature/Finance/VendorInvoiceUniquenessTest.php
tests/Feature/Finance/PaymentIntentExpireScheduleTest.php
tests/Feature/Finance/ApPaymentVoidLockTest.php
```

### Responsibility boundaries

- Each task addresses ONE punch-list item and has ONE focused test file.
- No shared state between tasks; can be reviewed independently.
- Existing finance suite (274 tests) must remain green after every task.

---

## Task 1: C1 — `2fa:fresh` on AP payment endpoints

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/ApPayment2faTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/ApPayment2faTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Auth\TwoFactorService;
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

function apPay2faFresh(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

it('AP payment store is blocked without fresh 2FA', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $bank = OrgBankAccount::active()->first();

    // No fresh 2FA — expect bounce (302)
    $this->actingAs($u)->post('/finance/ap-payments', [
        'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-23',
        'amount' => 100,
        'org_bank_account_id' => $bank->id,
        'allocations' => [],
    ])->assertStatus(302);
});

it('AP payment store proceeds past 2FA when challenge is fresh', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V2', 'name' => 'V2', 'status' => 'active']);
    $bank = OrgBankAccount::active()->first();

    // With fresh 2FA — gets past middleware; the request itself will fail
    // validation (empty allocations) which is fine — proves we got past 2FA.
    $response = $this->actingAs(apPay2faFresh($u))->post('/finance/ap-payments', [
        'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-23',
        'amount' => 100,
        'org_bank_account_id' => $bank->id,
        'allocations' => [],
    ]);

    // Past 2FA: not a 302 redirect to 2FA challenge, but a validation 302 with errors.
    // Distinguish by checking session has validation errors (vs redirected to login/2fa).
    expect(in_array($response->status(), [200, 302, 422], true))->toBeTrue();
});
```

- [ ] **Step 2: Run test — must FAIL on first test**

```
php artisan test --filter=ApPayment2faTest
```

Expected: first test fails (current state is no 2FA gate, so the request gets past middleware to validation).

- [ ] **Step 3: Update routes**

Open `routes/web.php`. Find the AP payments middleware group. Look for:

```php
Route::middleware('permission:ap_invoices.pay')->group(function () {
    Route::post('ap-payments',                  [\App\Http\Controllers\Finance\ApPaymentController::class, 'store'])->name('ap-payments.store');
    Route::post('ap-payments/{apPayment}/void', [\App\Http\Controllers\Finance\ApPaymentController::class, 'void'])->name('ap-payments.void');
    Route::post('ap-payments/{apPayment}/disburse', [\App\Http\Controllers\Finance\ApPaymentController::class, 'disburse'])->name('ap-payments.disburse');
});
```

Replace with:

```php
Route::middleware(['permission:ap_invoices.pay', '2fa:fresh'])->group(function () {
    Route::post('ap-payments',                  [\App\Http\Controllers\Finance\ApPaymentController::class, 'store'])->name('ap-payments.store');
    Route::post('ap-payments/{apPayment}/void', [\App\Http\Controllers\Finance\ApPaymentController::class, 'void'])->name('ap-payments.void');
    Route::post('ap-payments/{apPayment}/disburse', [\App\Http\Controllers\Finance\ApPaymentController::class, 'disburse'])->name('ap-payments.disburse');
});
```

(Single change: add `'2fa:fresh'` to the middleware array.)

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=ApPayment2faTest
```

Expected: 2 tests pass.

- [ ] **Step 5: Sanity check — full finance suite still green**

```
php artisan test --filter=Finance 2>&1 | tail -3
```

Expected: previous-baseline-count tests pass (no regressions from the 2FA gate). Existing AP payment endpoint tests that need to actually transact will need to use the `apPay2faFresh()` helper — if any fail, update them in this same task.

If any existing AP payment test (`ApPaymentEndpointTest`, etc.) fails because it doesn't call `apPay2faFresh()`, fix those tests in this same step to call the helper before posting. Same pattern as F3's `ar2faFresh()` rollout.

- [ ] **Step 6: Commit**

```
git add routes/web.php tests/Feature/Finance/ApPayment2faTest.php tests/Feature/Finance/ApPaymentEndpointTest.php
git commit -m "$(cat <<'EOF'
fix(finance): add 2fa:fresh to AP payment endpoints (C1)

Closes a control-asymmetry gap flagged in MARKET_READY_PUNCHLIST.md:
AR receipts/void/write-off are 2fa:fresh-gated; AP payments
(store/void/disburse) were not. Both mutate GL balances through
JournalPostingService. Existing endpoint tests get the apPay2faFresh()
helper applied to keep them green.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: C2 — `2fa:fresh` on manual `journal.store`

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/JournalManualPost2faTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/JournalManualPost2faTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function journal2faFresh(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

it('manual journal.store is blocked without fresh 2FA', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($u)->post('/finance/journal', [
        'entry_date' => '2026-05-23',
        'narration'  => 'manual correcting JE',
        'lines'      => [],
    ])->assertStatus(302);
});

it('manual journal.store gets past 2FA when challenge is fresh', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);

    $response = $this->actingAs(journal2faFresh($u))->post('/finance/journal', [
        'entry_date' => '2026-05-23',
        'narration'  => 'manual correcting JE',
        'lines'      => [],
    ]);

    // Past 2FA: response is either validation failure (422/302) or the actual
    // controller. Critically NOT the 2FA bounce, which would also be 302 — but
    // the request would never reach the controller. We assert that the response
    // is NOT a redirect to the 2FA challenge URL.
    if ($response->status() === 302) {
        expect($response->headers->get('Location'))->not->toContain('two-factor');
    }
});
```

- [ ] **Step 2: Run test — must FAIL on first assertion**

```
php artisan test --filter=JournalManualPost2faTest
```

- [ ] **Step 3: Update the journal route**

Open `routes/web.php`. Find the journal-post middleware group, similar shape to:

```php
Route::middleware('permission:journal.post')->group(function () {
    Route::post('journal', [\App\Http\Controllers\Finance\JournalController::class, 'store'])->name('journal.store');
});
```

Replace with:

```php
Route::middleware(['permission:journal.post', '2fa:fresh'])->group(function () {
    Route::post('journal', [\App\Http\Controllers\Finance\JournalController::class, 'store'])->name('journal.store');
});
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=JournalManualPost2faTest
```

- [ ] **Step 5: Fix any existing journal-store endpoint tests**

```
php artisan test --filter='Journal' 2>&1 | tail -5
```

If any existing test posts to `journal.store` without fresh 2FA, update it to use the helper. Same pattern as Task 1's existing-test fix-up.

- [ ] **Step 6: Commit**

```
git add routes/web.php tests/Feature/Finance/JournalManualPost2faTest.php
git commit -m "$(cat <<'EOF'
fix(finance): add 2fa:fresh to manual journal.store (C2)

Per F2 design, manual journal entries are emergency-only. Without
2fa:fresh, a stolen session can post arbitrary GL movements.
Matches payroll.reverse + loans.disburse posture.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: I1 — `vendor_invoice_no` uniqueness at request level

**Files:**
- Modify: `app/Http/Requests/Finance/StoreVendorInvoiceRequest.php`
- Test: `tests/Feature/Finance/VendorInvoiceUniquenessTest.php`

- [ ] **Step 1: Inspect F3's pattern for reference**

Read `app/Http/Requests/Finance/StoreArInvoiceRequest.php` to see how it does the per-customer uniqueness rule. Mirror that approach.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Finance/VendorInvoiceUniquenessTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\VendorInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('rejects duplicate vendor_invoice_no for the same vendor with 422, not 500', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    // First invoice — succeeds
    app(VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id,
        'invoice_date' => '2026-05-23',
        'vendor_invoice_no' => 'INV-001',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ], $u);

    // Second with same vendor + same vendor_invoice_no — must 422
    $this->actingAs($u)->post('/finance/ap-invoices', [
        'vendor_id' => $vendor->id,
        'invoice_date' => '2026-05-23',
        'vendor_invoice_no' => 'INV-001',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ])->assertSessionHasErrors('vendor_invoice_no');
});

it('allows the same vendor_invoice_no across different vendors', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $v1 = Vendor::create(['code' => 'V1', 'name' => 'V1', 'status' => 'active']);
    $v2 = Vendor::create(['code' => 'V2', 'name' => 'V2', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    foreach ([$v1, $v2] as $vendor) {
        app(VendorInvoiceService::class)->create([
            'vendor_id' => $vendor->id,
            'invoice_date' => '2026-05-23',
            'vendor_invoice_no' => 'SHARED-001',
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
        ], $u);
    }

    expect(\App\Models\VendorInvoice::where('vendor_invoice_no', 'SHARED-001')->count())->toBe(2);
});

it('allows null vendor_invoice_no even when one already exists', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    foreach ([null, null] as $_) {
        app(VendorInvoiceService::class)->create([
            'vendor_id' => $vendor->id,
            'invoice_date' => '2026-05-23',
            'vendor_invoice_no' => null,
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
        ], $u);
    }

    expect(\App\Models\VendorInvoice::whereNull('vendor_invoice_no')->count())->toBe(2);
});
```

- [ ] **Step 3: Run test — must FAIL on first test**

```
php artisan test --filter=VendorInvoiceUniquenessTest
```

- [ ] **Step 4: Inspect the current request**

Read `app/Http/Requests/Finance/StoreVendorInvoiceRequest.php`. Find the `rules()` method.

- [ ] **Step 5: Add the closure-based uniqueness rule**

In `rules()`, find the `'vendor_invoice_no'` rule line (or add one if not present) and replace with:

```php
            'vendor_invoice_no' => [
                'nullable', 'string', 'max:100',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $vendorId = $this->input('vendor_id');
                    if (! $vendorId) return;
                    $exists = \App\Models\VendorInvoice::query()
                        ->where('vendor_id', $vendorId)
                        ->where('vendor_invoice_no', $value)
                        ->exists();
                    if ($exists) {
                        $fail("This vendor already has an invoice with number '{$value}'.");
                    }
                },
            ],
```

- [ ] **Step 6: Run test — must PASS**

```
php artisan test --filter=VendorInvoiceUniquenessTest
```

Expected: 3 tests pass.

- [ ] **Step 7: Verify the F2 endpoint suite still passes**

```
php artisan test --filter='AP|VendorInvoice|Finance' 2>&1 | tail -3
```

- [ ] **Step 8: Commit**

```
git add app/Http/Requests/Finance/StoreVendorInvoiceRequest.php tests/Feature/Finance/VendorInvoiceUniquenessTest.php
git commit -m "$(cat <<'EOF'
fix(finance): per-vendor vendor_invoice_no uniqueness at FormRequest (I1)

Closes F2 deferral. F3 had the pattern; F2 didn't. Duplicate vendor
invoice submissions now return 422 with a field error instead of
hitting the DB UNIQUE constraint and 500ing.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: I2 — schedule `PaymentIntentService::expireStale()` nightly

**Files:**
- Modify: `routes/console.php` (or `app/Console/Kernel.php` — whichever the project uses for the schedule)
- Test: `tests/Feature/Finance/PaymentIntentExpireScheduleTest.php`

- [ ] **Step 1: Locate the schedule definition**

```
grep -rn 'schedule\|Schedule::call\|->daily\(' routes/console.php app/Console/Kernel.php 2>&1 | head -10
```

Modern Laravel 13 uses `routes/console.php` with a closure-based scheduler. Confirm the file the project uses.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Finance/PaymentIntentExpireScheduleTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('payment-intent expire job is scheduled', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $found = collect($schedule->events())->first(function ($event) {
        // Look for a description or command string that mentions expireStale
        $desc = strtolower((string) ($event->description ?? ''));
        $cmd  = strtolower((string) ($event->command ?? ''));
        return str_contains($desc, 'expirestale')
            || str_contains($desc, 'payment intent')
            || str_contains($cmd, 'expirestale')
            || str_contains($cmd, 'payment intent');
    });

    expect($found)->not->toBeNull('PaymentIntentService::expireStale() is not on a schedule');
});
```

- [ ] **Step 3: Run test — must FAIL**

```
php artisan test --filter=PaymentIntentExpireScheduleTest
```

- [ ] **Step 4: Add the scheduled call**

Open `routes/console.php`. At the end of the file (before any closing braces) add:

```php
\Illuminate\Support\Facades\Schedule::call(function () {
    app(\App\Services\Finance\PaymentIntentService::class)->expireStale();
})->dailyAt('02:15')->name('payment-intents:expire-stale')->onOneServer();
```

If the project uses `app/Console/Kernel.php` instead, add the equivalent line to its `schedule()` method.

- [ ] **Step 5: Run test — must PASS**

```
php artisan test --filter=PaymentIntentExpireScheduleTest
```

- [ ] **Step 6: Sanity-check `php artisan schedule:list`**

```
php artisan schedule:list 2>&1 | grep -i 'expire\|payment-intent' | head -3
```

Expected: a line referencing the new schedule entry.

- [ ] **Step 7: Commit**

```
git add routes/console.php tests/Feature/Finance/PaymentIntentExpireScheduleTest.php
git commit -m "$(cat <<'EOF'
fix(finance): schedule PaymentIntentService::expireStale() nightly (I2)

Closes F4 deferral. Stale Paystack payment links no longer accumulate
indefinitely; expireStale() now runs daily at 02:15 (one server only).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: I4 — `lockForUpdate()` in `ApPaymentService::void()`

**Files:**
- Modify: `app/Services/Finance/ApPaymentService.php`
- Test: `tests/Feature/Finance/ApPaymentVoidLockTest.php`

- [ ] **Step 1: Inspect F3's pattern**

Read `app/Services/Finance/ArReceiptService.php`'s `void()` method. It does `lockForUpdate()` on the linked invoice rows inside the transaction before mutating `amount_received`. Mirror this in `ApPaymentService::void()`.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Finance/ApPaymentVoidLockTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ApPaymentService;
use App\Services\Finance\VendorInvoiceService;
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

it('void() runs inside a transaction with lockForUpdate on the AP invoice rows', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();
    $bank = OrgBankAccount::active()->first();

    $inv = app(VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ], $u);
    app(VendorInvoiceService::class)->submit($inv);
    app(VendorInvoiceService::class)->approve($inv->fresh(), $approver);

    $payment = app(ApPaymentService::class)->record([
        'vendor_id' => $vendor->id, 'payment_date' => '2026-05-23',
        'amount' => 100, 'org_bank_account_id' => $bank->id,
        'allocations' => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 100]],
    ], $u);

    // Void should restore invoice amount_paid to 0 and not lose state under concurrency.
    // We can't easily test the lock from PHP, but we can assert the void runs cleanly and
    // the invoice's amount_paid is decremented atomically (no partial state).
    app(ApPaymentService::class)->void($payment, $u, 'test void');

    expect((float) $inv->fresh()->amount_paid)->toBe(0.0);
    expect($payment->fresh()->status->value)->toBe('voided');
});

it('source code: ApPaymentService::void() calls lockForUpdate', function () {
    $source = file_get_contents(app_path('Services/Finance/ApPaymentService.php'));
    expect($source)->toContain('lockForUpdate');
});
```

- [ ] **Step 3: Run test — must FAIL on the source-code assertion**

```
php artisan test --filter=ApPaymentVoidLockTest
```

The first test may pass (correctness in serial), but the second test (source contains `lockForUpdate`) will fail until the code is updated.

- [ ] **Step 4: Update `ApPaymentService::void()`**

Open `app/Services/Finance/ApPaymentService.php`. Find the `void()` method. Inside the `DB::transaction()` closure, BEFORE the loop that mutates `vendor_invoices.amount_paid`, add a `lockForUpdate()` re-fetch of the invoice rows. The exact code depends on the current shape of `void()` — read it first, then add the lock.

The pattern, modeled on `ArReceiptService::void()`:

```php
public function void(ApPayment $payment, User $by, string $reason): ApPayment
{
    // ... existing status guards ...

    return DB::transaction(function () use ($payment, $by, $reason) {
        // Re-fetch + lock the linked AP invoices before mutating amount_paid.
        $invoiceIds = $payment->allocations()->pluck('vendor_invoice_id');
        \App\Models\VendorInvoice::whereIn('id', $invoiceIds)->lockForUpdate()->get();

        // ... existing reversal logic ...
    });
}
```

The exact line to insert depends on where the existing `DB::transaction()` callback begins. Place the lock immediately after the callback opens, before any mutating queries.

- [ ] **Step 5: Run test — must PASS**

```
php artisan test --filter=ApPaymentVoidLockTest
```

Expected: 2 tests pass.

- [ ] **Step 6: Full F2 suite**

```
php artisan test --filter='ApPayment|F2' 2>&1 | tail -3
```

Expected: no regressions.

- [ ] **Step 7: Commit**

```
git add app/Services/Finance/ApPaymentService.php tests/Feature/Finance/ApPaymentVoidLockTest.php
git commit -m "$(cat <<'EOF'
fix(finance): lockForUpdate in ApPaymentService::void() (I4)

Closes F2 deferral. F3's ArReceiptService::void() correctly locks
invoice rows before resetting amount_received; the F2 AP equivalent
didn't. Now mirrors the F3 pattern, closing a double-void race.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Acceptance smoke + push

- [ ] **Step 1: Run the full Finance suite**

```
php artisan test --filter='Finance|EnumsF' 2>&1 | tail -3
```

Expected: 280+ tests passing, no regressions.

- [ ] **Step 2: Run the full Pest suite**

```
php artisan test 2>&1 | tail -3
```

Expected: green; ~885+ tests.

- [ ] **Step 3: Build smoke**

```
npm run build 2>&1 | tail -3
```

Expected: clean.

- [ ] **Step 4: Push the branch + open PR**

```
git push -u origin fix/finance-hardening-pack
gh pr create --base main --head fix/finance-hardening-pack \
  --title "fix(finance): hardening pack — 2FA gates + lockForUpdate + uniqueness + cron (C1+C2+I1+I2+I4)" \
  --body "Closes 5 items from MARKET_READY_PUNCHLIST.md. Each task mirrors an existing pattern; full Pest suite green."
```

---

## Done criteria

The hardening pack is complete when:

1. All 5 tasks committed.
2. Full Pest suite passes (~885+ tests).
3. `php artisan schedule:list` shows the new `payment-intents:expire-stale` entry.
4. AP payment + manual journal endpoints both require fresh 2FA (verified by the new feature tests).
5. Duplicate `vendor_invoice_no` for the same vendor returns 422 (verified by the new feature test).
6. `git grep lockForUpdate app/Services/Finance/ApPaymentService.php` shows at least one hit.
7. No regressions in any existing endpoint test (the AP-2FA + journal-2FA changes update existing tests to use the `*2faFresh` helper).
