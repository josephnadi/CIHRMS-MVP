# Finance Phase 2 — P2-3: Subledger↔GL Reconciliation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the books tie out — compare each subledger to its GL control account (AP→2100, AR→1200, Loans principal→1300) — surfaced as a report on the Fiscal Calendar page and consumed as a **pre-close check** that warns on a variance and requires an audited override to close anyway.

**Architecture:** A `SubledgerReconciliationService` returns one row per subledger `{subledger, gl_code, subledger_total, gl_balance, variance, in_balance}` and a `hasVariance()` helper. `PeriodCloseService::close()` gains an `$acknowledgeVariance` flag: if the books are out of balance and the flag is false, it throws `SubledgerVarianceException` (surfaced to the UI as a confirm-to-override prompt); the override request is recorded by the existing `AuditTrail` middleware. The recon rows are shown on the Fiscal Calendar page.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest. Builds on P2-1/P2-2.

**This is P2-3 — the final piece of Phase 2.** After it, the fiscal-period subsystem is complete.

**Spec:** `docs/superpowers/specs/2026-06-18-finance-fiscal-periods-design.md`

## Tie-out definitions (precise)

| Subledger | GL control | Subledger total |
|---|---|---|
| Accounts Payable | 2100 | Σ `(total − amount_paid)` of vendor invoices with status approved/partially_paid |
| Accounts Receivable | 1200 | Σ `(total − amount_received)` of AR invoices with status approved/partially_paid |
| Loans Receivable (principal) | 1300 | Σ `principal_portion` of loan repayments with status ≠ paid |

The GL control balance is the `gl_account_balances.balance` for that code. Variance = `subledger_total − gl_balance`; in balance when `|variance| < 0.005`.

**Loan note:** the subledger total is *remaining principal* (Σ unpaid `principal_portion`), NOT `LoanAccount.outstanding_balance` (which includes unearned interest and would always show a phantom variance against the principal-only GL 1300). GL 1300 decreases only on a *paid* repayment (payroll credits `principal_portion`), so Σ(non-paid `principal_portion`) = GL 1300 by construction.

---

### Task 1: SubledgerReconciliationService

**Files:**
- Create: `app/Services/Finance/SubledgerReconciliationService.php`
- Test: `tests/Feature/Finance/SubledgerReconciliationServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Services\Finance\SubledgerReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function glBalanceForCode(string $code, float $balance): void
{
    $id = GlAccount::where('code', $code)->value('id');
    GlAccountBalance::where('gl_account_id', $id)->update(['balance' => $balance]);
}

it('reports all subledgers in balance when nothing is outstanding and GL is zero', function () {
    $rows = app(SubledgerReconciliationService::class)->reconcile();

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row['variance'])->toBe(0.0)
            ->and($row['in_balance'])->toBeTrue();
    }
    expect(app(SubledgerReconciliationService::class)->hasVariance())->toBeFalse();
});

it('detects a variance when a GL control balance diverges from its subledger', function () {
    // No subledger entries (AP subledger = 0), but GL 2100 carries 500 → out of balance.
    glBalanceForCode('2100', 500.0);

    $svc = app(SubledgerReconciliationService::class);
    $rows = collect($svc->reconcile())->keyBy('gl_code');

    expect((float) $rows['2100']['subledger_total'])->toBe(0.0)
        ->and((float) $rows['2100']['gl_balance'])->toBe(500.0)
        ->and((float) $rows['2100']['variance'])->toBe(-500.0)
        ->and($rows['2100']['in_balance'])->toBeFalse()
        ->and($svc->hasVariance())->toBeTrue();

    // The other two remain in balance.
    expect($rows['1200']['in_balance'])->toBeTrue()
        ->and($rows['1300']['in_balance'])->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/SubledgerReconciliationServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LoanRepaymentStatus;
use App\Models\ArInvoice;
use App\Models\GlAccountBalance;
use App\Models\LoanRepayment;
use App\Models\VendorInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Compares each subledger to its GL control account. A non-zero variance means
 * the subledger and the ledger have drifted apart — surfaced as a report and as
 * a pre-close check. Tolerance matches the JE balance tolerance (0.005).
 */
class SubledgerReconciliationService
{
    private const TOLERANCE = 0.005;

    /** @return array<int, array{subledger:string, gl_code:string, subledger_total:float, gl_balance:float, variance:float, in_balance:bool}> */
    public function reconcile(): array
    {
        return [
            $this->row('Accounts Payable',            '2100', $this->apOutstanding()),
            $this->row('Accounts Receivable',         '1200', $this->arOutstanding()),
            $this->row('Loans Receivable (principal)', '1300', $this->loanPrincipalOutstanding()),
        ];
    }

    public function hasVariance(): bool
    {
        foreach ($this->reconcile() as $row) {
            if (! $row['in_balance']) {
                return true;
            }
        }

        return false;
    }

    private function row(string $label, string $glCode, float $subledgerTotal): array
    {
        $glBalance = $this->glBalance($glCode);
        $variance  = round($subledgerTotal - $glBalance, 2);

        return [
            'subledger'       => $label,
            'gl_code'         => $glCode,
            'subledger_total' => round($subledgerTotal, 2),
            'gl_balance'      => round($glBalance, 2),
            'variance'        => $variance,
            'in_balance'      => abs($variance) < self::TOLERANCE,
        ];
    }

    private function glBalance(string $code): float
    {
        return (float) GlAccountBalance::query()
            ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
            ->where('gl_accounts.code', $code)
            ->value('gl_account_balances.balance');
    }

    private function apOutstanding(): float
    {
        return (float) VendorInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum(DB::raw('total - amount_paid'));
    }

    private function arOutstanding(): float
    {
        return (float) ArInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum(DB::raw('total - amount_received'));
    }

    private function loanPrincipalOutstanding(): float
    {
        return (float) LoanRepayment::query()
            ->where('status', '!=', LoanRepaymentStatus::Paid->value)
            ->sum('principal_portion');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/SubledgerReconciliationServiceTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/SubledgerReconciliationService.php tests/Feature/Finance/SubledgerReconciliationServiceTest.php
git commit -m "feat(finance): SubledgerReconciliationService (AP/AR/loans vs GL control)"
```

---

### Task 2: Pre-close variance check + override

**Files:**
- Create: `app/Exceptions/Finance/SubledgerVarianceException.php`
- Modify: `app/Services/Finance/PeriodCloseService.php`
- Modify: `app/Http/Controllers/Finance/PeriodController.php`
- Test: `tests/Feature/Finance/PeriodCloseVarianceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Exceptions\Finance\SubledgerVarianceException;
use App\Models\FiscalPeriod;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $this->period = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
    $this->user = User::factory()->create();
});

it('closes normally when the books are in balance', function () {
    app(PeriodCloseService::class)->close($this->period, $this->user);
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);
});

it('blocks close on a subledger variance unless acknowledged', function () {
    $id = GlAccount::where('code', '2100')->value('id');
    GlAccountBalance::where('gl_account_id', $id)->update(['balance' => 500.0]); // AP GL diverges

    expect(fn () => app(PeriodCloseService::class)->close($this->period, $this->user))
        ->toThrow(SubledgerVarianceException::class);

    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Open); // not closed
});

it('closes despite a variance when acknowledged (audited override)', function () {
    $id = GlAccount::where('code', '2100')->value('id');
    GlAccountBalance::where('gl_account_id', $id)->update(['balance' => 500.0]);

    app(PeriodCloseService::class)->close($this->period, $this->user, acknowledgeVariance: true);

    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PeriodCloseVarianceTest.php`
Expected: FAIL — `SubledgerVarianceException` missing / close has no `acknowledgeVariance` param, so the variance case closes instead of throwing.

- [ ] **Step 3: Write the exception**

`app/Exceptions/Finance/SubledgerVarianceException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use DomainException;

class SubledgerVarianceException extends DomainException
{
}
```

- [ ] **Step 4: Wire the pre-close check into PeriodCloseService**

In `app/Services/Finance/PeriodCloseService.php`:

(a) Add imports + a constructor (the class currently has no constructor):

```php
use App\Exceptions\Finance\SubledgerVarianceException;
```

```php
    public function __construct(private readonly SubledgerReconciliationService $reconciliation)
    {
    }
```

(`SubledgerReconciliationService` is in the same namespace — no import needed.)

(b) Change the `close()` signature to accept the override flag, and run the variance check after the Open guard, before the status update:

```php
    public function close(FiscalPeriod $period, User $by, bool $acknowledgeVariance = false): FiscalPeriod
    {
        if ($period->status !== FiscalPeriodStatus::Open) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only an open period can be closed.");
        }

        if (! $acknowledgeVariance && $this->reconciliation->hasVariance()) {
            throw new SubledgerVarianceException(
                "Subledger does not tie to the general ledger. Review the reconciliation, then confirm to close with an override."
            );
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => $by->id,
        ]);

        return $period->fresh();
    }
```

Leave `reopen()` and `lock()` unchanged.

- [ ] **Step 5: Pass the override flag from the controller**

In `app/Http/Controllers/Finance/PeriodController.php`, the `close()` action currently calls `$this->service->close($fiscalPeriod, $request->user())`. Change it to pass the acknowledge flag from the request:

```php
    public function close(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(
            fn () => $this->service->close($fiscalPeriod, $request->user(), $request->boolean('acknowledge_variance')),
            "Period {$fiscalPeriod->name} closed.",
        );
    }
```

The existing `transition()` helper already catches `DomainException` (which `SubledgerVarianceException` extends) → `back()->withErrors(['period' => ...])`, so an unacknowledged variance surfaces as a redirect-back error the UI can prompt on. The override request (`acknowledge_variance=true`) is recorded by the `AuditTrail` middleware.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PeriodCloseVarianceTest.php`
Expected: PASS (all three).

- [ ] **Step 7: Confirm P2-2's existing close tests still pass (backward compat — default flag false, no variance in those tests)**

Run: `php artisan test tests/Feature/Finance/PeriodCloseServiceTest.php tests/Feature/Finance/PeriodEndpointTest.php`
Expected: PASS — those tests close periods with empty books (no variance), so the new check is a no-op.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/Finance/SubledgerVarianceException.php app/Services/Finance/PeriodCloseService.php app/Http/Controllers/Finance/PeriodController.php tests/Feature/Finance/PeriodCloseVarianceTest.php
git commit -m "feat(finance): subledger-variance pre-close check + audited override"
```

---

### Task 3: Show the reconciliation on the Fiscal Calendar page

**Files:**
- Modify: `app/Http/Controllers/Finance/PeriodController.php`
- Modify: `resources/js/Pages/Finance/FiscalCalendar/Index.vue`
- Test: extend `tests/Feature/Finance/PeriodEndpointTest.php`

- [ ] **Step 1: Add a reconciliation assertion to the existing endpoint test**

Append to `tests/Feature/Finance/PeriodEndpointTest.php`:

```php
it('includes the subledger reconciliation rows on the calendar page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/periods')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/FiscalCalendar/Index')->has('reconciliation', 3));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Finance/PeriodEndpointTest.php`
Expected: FAIL — `reconciliation` prop not present.

- [ ] **Step 3: Add the recon data to the controller's index**

In `app/Http/Controllers/Finance/PeriodController.php`, inject `SubledgerReconciliationService` and pass its rows to the view. Add the import + constructor param:

```php
use App\Services\Finance\SubledgerReconciliationService;
```

Change the constructor to also inject it:

```php
    public function __construct(
        private readonly PeriodCloseService $service,
        private readonly SubledgerReconciliationService $reconciliation,
    ) {
    }
```

In `index()`, add `reconciliation` to the Inertia props:

```php
            'reconciliation' => $this->reconciliation->reconcile(),
```

(Place it alongside the existing `periods` prop.)

- [ ] **Step 4: Show the recon panel in the Vue page**

In `resources/js/Pages/Finance/FiscalCalendar/Index.vue`, add `reconciliation` to `defineProps`:

```js
    reconciliation: { type: Array, default: () => [] },
```

And add a reconciliation panel above the periods list (after the `</header>`):

```vue
        <section class="mb-6 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-3">Subledger ↔ GL reconciliation</h2>
            <div class="grid gap-2 sm:grid-cols-3">
                <div v-for="r in reconciliation" :key="r.gl_code"
                     class="rounded-xl border border-outline-variant/40 p-3"
                     :class="r.in_balance ? '' : 'border-amber-500/50'">
                    <p class="text-[11px] font-bold text-on-surface-variant">{{ r.subledger }} <span class="opacity-60">({{ r.gl_code }})</span></p>
                    <p class="mt-1 text-sm text-primary">Subledger {{ Number(r.subledger_total).toFixed(2) }} · GL {{ Number(r.gl_balance).toFixed(2) }}</p>
                    <p class="mt-0.5 text-[11px]" :class="r.in_balance ? 'text-emerald-300' : 'text-amber-300 font-bold'">
                        {{ r.in_balance ? 'In balance' : 'Variance ' + Number(r.variance).toFixed(2) }}
                    </p>
                </div>
            </div>
        </section>
```

- [ ] **Step 5: Run test + build**

Run: `php artisan test tests/Feature/Finance/PeriodEndpointTest.php`
Expected: PASS (now 6 cases incl. the reconciliation prop).

Run: `npm run build`
Expected: succeeds, no Vue compile errors. (The `<select>` already has `aria-label` from P2-2's accessibility fix — do not regress it.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Finance/PeriodController.php resources/js/Pages/Finance/FiscalCalendar/Index.vue tests/Feature/Finance/PeriodEndpointTest.php
git commit -m "feat(finance): show subledger reconciliation on the Fiscal Calendar page"
```

---

### Task 4: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Payroll tests/Feature/Loans tests/Feature/Disbursement`
Expected: PASS.

- [ ] **Step 2: Full app suite (includes the accessibility auditor)**

Run: `php artisan test`
Expected: PASS — confirm the `AccessibilityAuditorTest` is green (the recon panel adds no unlabeled form controls; the only `<select>` already carries `aria-label`). Allow the known time-of-day `KioskRecentTest` flake only if it is the sole failure and unrelated.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P2-3 subledger reconciliation regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Loan tie-out uses remaining principal** (Σ non-paid `principal_portion`), which equals GL 1300 by construction — NOT `LoanAccount.outstanding_balance` (that carries interest and would always look out of balance). This is a deliberate, accurate deviation from the spec's loose wording.
- **The variance check is global/point-in-time** (current subledger vs current GL control), run at close — a month-end "do the books tie out?" gate, not a period-scoped diff.
- **Override is audited for free**: the close POST with `acknowledge_variance=true` is captured by the `AuditTrail` middleware (action + payload), satisfying the spec's "audited override."
- **Backward compatible**: `close()`'s new `$acknowledgeVariance` defaults to false; P2-2's close tests use empty books (no variance) so they're unaffected.
- **Accessibility**: the recon panel is display-only (no form controls). Keep the year `<select>`'s `aria-label` (added in P2-2) — the AccessibilityAuditorTest will fail if it's removed.
