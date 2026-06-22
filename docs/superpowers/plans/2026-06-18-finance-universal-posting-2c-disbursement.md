# Finance Universal Posting — Plan 2C: Disbursement Settlement Posting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a payroll disbursement settles (money actually leaves the bank), post the second leg of the two-step accrual model — Dr Net-Pay Payable, Cr the organisation's payroll bank account — clearing the liability that the payroll accrual (Plan 2B) created.

**Architecture:** Inject `PostingService` into `BatchDisbursementService`. Add a private `settle()` that posts a balanced `PostingDocument` keyed on `(Disbursement, disbursement_id, 'settlement')`, crediting the GL account of the active `OrgBankAccount` with purpose `Payroll`. Call `settle()` wherever a `Disbursement` transitions to `Settled` — inside `dispatch()` (providers that settle immediately) and `reconcile()` (providers that settle after polling).

**Tech Stack:** Laravel 13, PHP 8.3, Pest. Builds on Plan 2A (`PostingService::post(PostingDocument, ?User)`) and 2B (Net-Pay Payable is credited at payroll approval).

**This is Plan 2C of Plan 2.** 2A + 2B are merged to `main`. 2D (loan disbursement) follows.

**Spec:** `docs/superpowers/specs/2026-06-16-finance-universal-posting-design.md`

## The settlement journal entry (confirmed design)

Posted per `Disbursement` at the moment it becomes `Settled`:

| DR/CR | Account | Amount |
|---|---|---|
| DR | Net-Pay Payable (2300, via slug `payroll.net_pay_payable`) | `disbursement.gross_amount` |
| CR | Payroll bank (GL of the active `OrgBankAccount` purpose=Payroll, by literal account id) | `disbursement.gross_amount` |

`gross_amount` is the employee's net pay (the liability being cleared). E-Levy / provider fee are downstream splits handled by the provider, not separate GL legs here — the org's cash out equals `gross_amount`. If no active payroll bank account is configured, settlement throws a clear `DomainException` (fail-loud).

---

### Task 1: Post the settlement JE when a disbursement settles

**Files:**
- Modify: `app/Services/Disbursement/BatchDisbursementService.php`
- Modify: `app/Providers/DisbursementServiceProvider.php`
- Modify: `tests/Feature/Disbursement/BatchDisbursementServiceTest.php` (the two direct `new BatchDisbursementService([...])` constructions need the new 2nd constructor arg)
- Test: `tests/Feature/Disbursement/DisbursementSettlementPostingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\Department;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use App\Services\Finance\PostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // creates an active Payroll-purpose account → GL 1110

    // Settlement posts actor-less (no auth, no explicit actor), so PostingActorResolver
    // falls through to "first super_admin" for created_by/posted_by — seed one.
    User::factory()->create(['role' => 'super_admin']);

    $this->dept = Department::factory()->create();
    $this->employee = Employee::factory()->create([
        'department_id' => $this->dept->id,
        'disbursement_channel' => DisbursementChannel::MtnMomo->value,
        'mobile_money_number' => '0244000001',
    ]);
    $this->run = PayrollRun::create([
        'reference' => 'PR-2026-05-ORG', 'period_year' => 2026, 'period_month' => 5,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'status' => 'calculated',
    ]);
    PayrollLine::create([
        'payroll_run_id' => $this->run->id, 'employee_id' => $this->employee->id,
        'basic' => 5000, 'allowance_total' => 0, 'gross' => 5000,
        'ssnit_base' => 5000, 'ssnit_tier1_employee' => 275, 'ssnit_tier1_employer' => 650,
        'nhia_split' => 125, 'tier2_employer' => 250, 'tier3_employee' => 0,
        'paye' => 600, 'voluntary_deductions' => 0, 'net' => 4125, 'status' => 'calculated',
    ]);
});

function settlingProvider(): DisbursementProvider
{
    return new class implements DisbursementProvider {
        public function channel(): string { return DisbursementChannel::MtnMomo->value; }
        public function send(Disbursement $d): DisbursementResult { return DisbursementResult::settled('REF-1'); }
        public function refreshStatus(Disbursement $d): DisbursementResult { return DisbursementResult::settled('REF-1'); }
    };
}

it('posts a balanced settlement JE when a disbursement settles via dispatch', function () {
    $svc = new BatchDisbursementService(
        [DisbursementChannel::MtnMomo->value => settlingProvider()],
        app(PostingService::class),
    );
    $svc->materialise($this->run);
    $svc->dispatch($this->run);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail();
    expect($d->status)->toBe(DisbursementStatus::Settled);

    $je = JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)->where('source_purpose', 'settlement')->firstOrFail();

    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->isBalanced())->toBeTrue();

    $netPay = GlAccount::where('code', '2300')->firstOrFail();
    $bank   = GlAccount::where('code', '1110')->firstOrFail();
    $drLine = $je->lines->firstWhere('gl_account_id', $netPay->id);
    $crLine = $je->lines->firstWhere('gl_account_id', $bank->id);

    expect((float) $drLine->debit_amount)->toBe(4125.0)
        ->and((float) $crLine->credit_amount)->toBe(4125.0);
});

it('posts a settlement JE when a disbursement settles via reconcile', function () {
    $stub = new class implements DisbursementProvider {
        public function channel(): string { return DisbursementChannel::MtnMomo->value; }
        public function send(Disbursement $d): DisbursementResult { return DisbursementResult::sent('REF-2', []); }
        public function refreshStatus(Disbursement $d): DisbursementResult { return DisbursementResult::settled('REF-2'); }
    };
    $svc = new BatchDisbursementService([DisbursementChannel::MtnMomo->value => $stub], app(PostingService::class));
    $svc->materialise($this->run);
    $svc->dispatch($this->run); // → Sent

    // Backdate sent_at so reconcile()'s "older than 5 minutes" filter picks it up.
    Disbursement::where('payroll_run_id', $this->run->id)->update(['sent_at' => now()->subMinutes(10)]);
    $svc->reconcile($this->run); // → Settled, posts JE

    $d = Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail();
    expect($d->status)->toBe(DisbursementStatus::Settled);

    $je = JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)->where('source_purpose', 'settlement')->firstOrFail();
    expect($je->isBalanced())->toBeTrue();
});

it('throws when no active payroll bank account is configured', function () {
    OrgBankAccount::query()->update(['is_active' => false]);

    $svc = new BatchDisbursementService(
        [DisbursementChannel::MtnMomo->value => settlingProvider()],
        app(PostingService::class),
    );
    $svc->materialise($this->run);

    expect(fn () => $svc->dispatch($this->run))->toThrow(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/DisbursementSettlementPostingTest.php`
Expected: FAIL — `BatchDisbursementService` constructor takes only `$providers` (1 arg) / no settlement JE is posted.

- [ ] **Step 3: Inject PostingService + add settle() and bank resolution**

In `app/Services/Disbursement/BatchDisbursementService.php`:

(a) Add imports near the top:

```php
use App\Enums\JournalSourceType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\OrgBankAccount;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
```

(b) Change the constructor to require `PostingService` as a second parameter (providers stays first to preserve positional order at the one DI binding + test construction sites, which are all updated below):

```php
    public function __construct(
        /** @var array<string, DisbursementProvider> indexed by channel value */
        private readonly array $providers,
        private readonly PostingService $posting,
    ) {}
```

(c) Add these two private methods to the class:

```php
    private function settle(Disbursement $d): void
    {
        $bankGlId = $this->resolveSettlementBankGlId();

        $this->posting->post(new PostingDocument(
            sourceType: JournalSourceType::Disbursement,
            sourceId: $d->id,
            purpose: 'settlement',
            date: now()->toDateString(),
            narration: "Disbursement settlement: #{$d->id}",
            lines: [
                PostingLine::debit(slug: 'payroll.net_pay_payable', amount: (float) $d->gross_amount, narration: 'Clear net pay payable'),
                PostingLine::credit(accountId: $bankGlId, amount: (float) $d->gross_amount, narration: 'Cash out to recipient'),
            ],
        ));
    }

    private function resolveSettlementBankGlId(): int
    {
        $bank = OrgBankAccount::query()
            ->where('purpose', OrgBankAccountPurpose::Payroll->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $bank || ! $bank->gl_account_id) {
            throw new \DomainException('No active payroll bank account is configured; cannot post disbursement settlement.');
        }

        return (int) $bank->gl_account_id;
    }
```

(d) In `dispatch()`, inside the existing `DB::transaction` closure, AFTER the `$d->update([...])` call, post the settlement when the row just settled. The closure currently captures `use ($d, $result)`; add the guard at the end of the closure:

```php
                if ($result->status === DisbursementStatus::Settled) {
                    $this->settle($d);
                }
```

(e) In `reconcile()`, the per-row `$d->update([...])` is NOT currently wrapped in a transaction. Wrap the update + settle in one so the JE is atomic with the status change. Replace the `$d->update([...]); $touched++;` block with:

```php
            DB::transaction(function () use ($d, $result) {
                $d->update([
                    'status'            => $result->status->value,
                    'provider_response' => $result->raw,
                    'settled_at'        => $result->status === DisbursementStatus::Settled ? now() : $d->settled_at,
                    'failed_at'         => $result->status === DisbursementStatus::Failed ? now() : $d->failed_at,
                    'failure_reason'    => $result->failureReason,
                ]);

                if ($result->status === DisbursementStatus::Settled) {
                    $this->settle($d);
                }
            });
            $touched++;
```

- [ ] **Step 4: Update the DI binding**

In `app/Providers/DisbursementServiceProvider.php`, add the import:

```php
use App\Services\Finance\PostingService;
```

and change the return inside the `BatchDisbursementService` singleton closure from `return new BatchDisbursementService($providers);` to:

```php
            return new BatchDisbursementService($providers, $app->make(PostingService::class));
```

- [ ] **Step 5: Fix the existing test's direct constructions**

In `tests/Feature/Disbursement/BatchDisbursementServiceTest.php`, the two `new BatchDisbursementService([...])` calls now need the second argument. Add `use App\Services\Finance\PostingService;` at the top, then:

- Change `new BatchDisbursementService([DisbursementChannel::MtnMomo->value => $stub]);` to
  `new BatchDisbursementService([DisbursementChannel::MtnMomo->value => $stub], app(PostingService::class));`
- Change `new BatchDisbursementService([]);` to
  `new BatchDisbursementService([], app(PostingService::class));`

(These two existing tests dispatch with a provider that returns `sent` / no provider, so they never settle — no finance seeding needed for them.)

- [ ] **Step 6: Run the new test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/DisbursementSettlementPostingTest.php`
Expected: PASS (all three).

- [ ] **Step 7: Run the existing disbursement suite**

Run: `php artisan test tests/Feature/Disbursement`
Expected: PASS — the two existing tests now construct the service with the `PostingService` arg and otherwise behave unchanged.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Disbursement/BatchDisbursementService.php app/Providers/DisbursementServiceProvider.php tests/Feature/Disbursement/
git commit -m "feat(finance): post disbursement settlement JE clearing net-pay payable to bank"
```

---

### Task 2: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Disbursement + Finance + Payroll suites**

Run: `php artisan test tests/Feature/Disbursement tests/Feature/Finance tests/Unit/Finance tests/Feature/Payroll`
Expected: PASS. If any other test constructs `BatchDisbursementService` directly (grep `new BatchDisbursementService`), update it to pass `app(PostingService::class)` as the second arg. If a test drives a disbursement to `Settled` without seeding the finance chart + a payroll bank account, add `ChartOfAccountsSeeder` + `GlAccountBalanceSeeder` + `PostingAccountSeeder` + `OrgBankAccountSeeder` to its `beforeEach`.

- [ ] **Step 2: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): Plan 2C disbursement-settlement regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Two-step model now complete for payroll:** 2B credits Net-Pay Payable at approval; 2C debits it (clearing) and credits Bank at settlement. After a run is fully disbursed, Net-Pay Payable nets to zero.
- **Per-disbursement idempotency:** keyed `(Disbursement, d->id, 'settlement')`. A disbursement that settles in `dispatch()` won't be re-posted by `reconcile()` (it's no longer `Sent`), and `PostingService` dedupes anyway.
- **Actor:** `settle()` does not pass an explicit actor, so `PostingService` uses the Plan 2A resolver (auth → system user). `dispatch()`/`reconcile()` run in the authenticated `DisbursementController` request today; if they ever move to a queue, the resolver falls back to the system user (never null).
- **Atomicity:** `settle()` runs inside the same `DB::transaction` as the status update in BOTH `dispatch()` and `reconcile()`, so a posting failure rolls the row back to its pre-settle status.
- **Bank selection:** the active `OrgBankAccount` with purpose `Payroll` (seeded as GL 1110). Fail-loud if absent.
- **`gross_amount` not `net_to_recipient`:** the liability being cleared is the full net pay owed; E-Levy is the provider's downstream split. Crediting Bank with `gross_amount` keeps the entry balanced and reflects the org's actual cash out.
