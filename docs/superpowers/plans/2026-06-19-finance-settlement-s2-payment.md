# Finance — Settlement→GL S-2: Payment Leg + UI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an approved final settlement is paid, post the cash JE that clears the net-pay liability (DR 2300 / CR payroll bank), flip the settlement to Paid, and expose a "Mark settlement paid" action in the off-boarding UI — completing the end-to-end on-ledger settlement.

**Architecture:** `SettlementPostingService::postPayment` reads the exact net credited by the S-1 accrual (the accrual JE's `2300` line) and posts `DR settlement.net_pay_payable / CR active Payroll bank` through `PostingService`. `OffboardingService::paySettlement` (Approved → Paid) wraps it in a transaction and stamps `paid_at`. A policy method + route + controller action + a Show.vue button drive it.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- All GL writes go through `PostingService::post`; idempotency `source_purpose = 'payment'` on `(FinalSettlement, settlement.id)`.
- The payment amount is the **net actually credited at accrual** (read from the accrual JE's `2300` line), not the snapshot `net_payable` (which can differ in the shortfall case).
- Resolve the bank by `OrgBankAccountPurpose::Payroll` (active, first) → `gl_account_id`; fail-loud if none.
- `declare(strict_types=1)`; the pay action carries `2fa:fresh` (mirrors approve); money tolerance `0.005`.

**This is S-2 of the settlement→GL spec.** S-1 (accrual + loan clearing + subledger fix) is merged.

**Spec:** `docs/superpowers/specs/2026-06-19-finance-settlement-gl-posting-design.md`

---

### Task 1: Service layer — postPayment + paySettlement

**Files:**
- Modify: `app/Services/Offboarding/SettlementPostingService.php`
- Modify: `app/Services/Offboarding/OffboardingService.php`
- Test: `tests/Feature/Offboarding/SettlementPaymentTest.php`

**Interfaces:**
- Consumes: `PostingService`, `AccountResolver::resolve(slug)`, `JournalEntry`/`JournalLine`/`JournalEntryStatus`, `OrgBankAccount`/`OrgBankAccountPurpose`, the S-1 accrual JE.
- Produces:
  - `SettlementPostingService::postPayment(FinalSettlement $settlement, User $actor): ?JournalEntry` — DR `settlement.net_pay_payable` / CR payroll bank for the accrual's net; returns the JE, or `null` when nothing is owed (net 0). Throws if the accrual isn't posted yet.
  - `OffboardingService::paySettlement(FinalSettlement $settlement, User $payer): FinalSettlement` — guards `status === Approved`, posts the payment, sets `Paid` + `paid_at`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Enums\SettlementStatus;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // reuse seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

/** An active payroll bank mapped to GL 1110. */
function seedPayrollBank(): void
{
    OrgBankAccount::factory()->create([
        'purpose'       => 'payroll',
        'is_active'     => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
}

it('pays an approved settlement: clears 2300 and credits the payroll bank', function () {
    seedPayrollBank();
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]); // net = 10000-500-3300 = 6200
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    $paid = app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect($paid->status)->toBe(SettlementStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull()
        ->and(settlementGl('2300'))->toEqualWithDelta(0.0, 0.01)     // liability cleared
        ->and(settlementGl('1110'))->toEqualWithDelta(-6200.0, 0.01); // bank reduced by net

    expect(JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
        ->where('source_id', $settlement->id)->where('source_purpose', 'payment')->exists())->toBeTrue();
});

it('refuses to pay a settlement that is not approved', function () {
    seedPayrollBank();
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    $settlement->update(['status' => 'calculated']);

    expect(fn () => app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('marks paid with no payment JE when nothing is owed (net zero)', function () {
    seedPayrollBank();
    // gross 1100 fully absorbed by a single 1100 installment → net 0.
    [$settlement] = seedSettlementWithLoan(['gross' => 1100, 'paye' => 0, 'outstanding' => 3300]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    $paid = app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect($paid->status)->toBe(SettlementStatus::Paid)
        ->and(JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
            ->where('source_id', $settlement->id)->where('source_purpose', 'payment')->exists())->toBeFalse();
});

it('fails loud when no active payroll bank is configured', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]); // no seedPayrollBank()
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect(fn () => app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/SettlementPaymentTest.php`
Expected: FAIL — `postPayment`/`paySettlement` missing.

- [ ] **Step 3: Add `postPayment` to SettlementPostingService**

Add these imports to `app/Services/Offboarding/SettlementPostingService.php`:

```php
use App\Enums\JournalEntryStatus;
use App\Enums\OrgBankAccountPurpose;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrgBankAccount;
use App\Services\Finance\AccountResolver;
use DomainException;
```

Add `AccountResolver` to the constructor:

```php
    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountResolver $resolver,
    ) {
    }
```

Add the method (after `postAccrual`):

```php
    /**
     * Pay an approved settlement's net: DR the net-pay payable, CR the payroll
     * bank, for the exact net the accrual credited to 2300. Returns null when
     * nothing is owed (net zero). Throws if the accrual hasn't been posted.
     */
    public function postPayment(FinalSettlement $settlement, User $actor): ?JournalEntry
    {
        $accrual = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
            ->where('source_id', $settlement->id)
            ->where('source_purpose', 'accrual')
            ->where('status', JournalEntryStatus::Posted->value)
            ->first();

        if (! $accrual) {
            throw new DomainException('Cannot pay a settlement before its accrual is posted.');
        }

        $netPayAccount = $this->resolver->resolve('settlement.net_pay_payable');
        $line = JournalLine::where('journal_entry_id', $accrual->id)
            ->where('gl_account_id', $netPayAccount->id)
            ->first();
        $netToPay = $line ? round((float) $line->credit_amount, 2) : 0.0;

        if ($netToPay <= 0.0) {
            return null; // nothing owed to the leaver (e.g. a shortfall settlement)
        }

        $bankGlId = $this->resolvePayrollBankGlId();

        return $this->posting->post(new PostingDocument(
            sourceType: JournalSourceType::FinalSettlement,
            sourceId: $settlement->id,
            purpose: 'payment',
            date: now()->toDateString(),
            narration: "Final settlement payment (case {$settlement->offboarding_case_id})",
            lines: [
                PostingLine::debit(slug: 'settlement.net_pay_payable', amount: $netToPay, narration: 'Clear settlement payable'),
                PostingLine::credit(accountId: $bankGlId, amount: $netToPay, narration: 'Settlement paid from payroll bank'),
            ],
        ), $actor);
    }

    private function resolvePayrollBankGlId(): int
    {
        $bank = OrgBankAccount::query()
            ->where('purpose', OrgBankAccountPurpose::Payroll->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $bank || ! $bank->gl_account_id) {
            throw new DomainException('No active payroll bank account is configured; cannot pay the settlement.');
        }

        return (int) $bank->gl_account_id;
    }
```

- [ ] **Step 4: Add `paySettlement` to OffboardingService**

In `app/Services/Offboarding/OffboardingService.php`, add the method (after `approveSettlement`):

```php
    /**
     * Pay an approved settlement: post the cash JE (DR net-pay payable / CR
     * payroll bank) and flip the settlement to Paid. Approved → Paid only.
     */
    public function paySettlement(FinalSettlement $settlement, User $payer): FinalSettlement
    {
        if ($settlement->status !== SettlementStatus::Approved) {
            throw new \DomainException('Only an approved settlement can be paid.');
        }

        return DB::transaction(function () use ($settlement, $payer) {
            $this->settlementPosting->postPayment($settlement, $payer);

            $settlement->update([
                'status'  => SettlementStatus::Paid->value,
                'paid_at' => now(),
            ]);

            return $settlement->fresh();
        });
    }
```

(`SettlementStatus`, `DB`, `FinalSettlement`, `User`, and `$this->settlementPosting` are already imported/injected from S-1.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Offboarding/SettlementPaymentTest.php`
Expected: PASS (all four).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Offboarding/SettlementPostingService.php app/Services/Offboarding/OffboardingService.php tests/Feature/Offboarding/SettlementPaymentTest.php
git commit -m "feat(finance): settlement payment leg (DR 2300 / CR payroll bank) + paySettlement (Approved→Paid)"
```

---

### Task 2: HTTP layer — policy, route, controller action

**Files:**
- Modify: `app/Policies/OffboardingCasePolicy.php`
- Modify: `app/Http/Resources/OffboardingCaseResource.php`
- Modify: `app/Http/Controllers/OffboardingController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Offboarding/PaySettlementEndpointTest.php`

**Interfaces:**
- Consumes: `OffboardingService::paySettlement` (Task 1); permission `offboarding.approve` (reused for the pay gate).
- Produces: `POST offboarding/{case}/settlement/pay` → `offboarding.settlement.pay`; `can.pay_settle` on the case resource.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php';

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
    OrgBankAccount::factory()->create([
        'purpose' => 'payroll', 'is_active' => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
});

it('lets an authorized user pay an approved settlement', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $payer = User::factory()->create(['role' => 'super_admin']); // bypasses policy via before()
    $this->actingAs($payer)
        ->withSession(['auth.2fa_confirmed_at' => now()->timestamp]) // satisfy 2fa:fresh if enforced
        ->post("/offboarding/{$case->id}/settlement/pay")
        ->assertRedirect();

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Paid);
});

it('forbids a user without offboarding.approve from paying', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post("/offboarding/{$case->id}/settlement/pay")
        ->assertForbidden();
});
```

> If `2fa:fresh` makes the happy-path assert a redirect-to-2FA instead of success, follow the pattern the existing `approveSettlement` endpoint test uses to satisfy fresh-2FA (search `tests/Feature/Offboarding` for how the approve endpoint is tested) and mirror it. The forbidden test does not depend on 2FA.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/PaySettlementEndpointTest.php`
Expected: FAIL — route/action/policy missing.

- [ ] **Step 3: Add the policy method**

In `app/Policies/OffboardingCasePolicy.php`, after `approveSettlement`:

```php
    public function paySettlement(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('offboarding.approve');
    }
```

- [ ] **Step 4: Expose `can.pay_settle`**

In `app/Http/Resources/OffboardingCaseResource.php`, add to the `'can'` array (after `approve_settle`):

```php
                'pay_settle'      => $request->user()?->can('paySettlement', $this->resource),
```

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/OffboardingController.php`, after `approveSettlement`:

```php
    public function paySettlement(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('paySettlement', $case);

        $settlement = $case->settlement;
        if (! $settlement) {
            return back()->with('error', 'No settlement exists for this case.');
        }

        try {
            $this->service->paySettlement($settlement, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Settlement for {$case->reference} marked paid.");
    }
```

- [ ] **Step 6: Add the route**

In `routes/web.php`, inside the `offboarding` group (after the `settlement/approve` route):

```php
        Route::post('{case}/settlement/pay',               [OffboardingController::class, 'paySettlement'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.pay');
```

- [ ] **Step 7: Run test + commit**

Run: `php artisan test tests/Feature/Offboarding/PaySettlementEndpointTest.php`
Expected: PASS (both).

```bash
git add app/Policies/OffboardingCasePolicy.php app/Http/Resources/OffboardingCaseResource.php app/Http/Controllers/OffboardingController.php routes/web.php tests/Feature/Offboarding/PaySettlementEndpointTest.php
git commit -m "feat(finance): pay-settlement endpoint + policy + can.pay_settle"
```

---

### Task 3: UI button + regression gate

**Files:**
- Modify: `resources/js/Pages/Offboarding/Show.vue`
- Test: none (verification only).

- [ ] **Step 1: Add the pay action + button**

In `resources/js/Pages/Offboarding/Show.vue`, near the existing `approve`/`complete` action functions (around line 111), add:

```js
const pay = () => router.post(route('offboarding.settlement.pay', C.value.id), {}, { preserveScroll: true });
```

Find the settlement action block where the **Complete** button is rendered (`v-if="S?.status === 'approved' && C.can?.complete"`, around line 261/634) and add a **Mark Paid** button alongside it, shown when the settlement is approved-but-not-yet-paid and the user may pay. Match the surrounding button styling:

```vue
                            <button
                                v-if="S?.status === 'approved' && !S?.paid_at && C.can?.pay_settle"
                                @click="pay"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-secondary px-4 py-2.5 text-[13px] font-bold text-white shadow-card hover:bg-secondary/90 transition-colors">
                                <span class="material-symbols-outlined text-[18px]">payments</span>
                                Mark Settlement Paid
                            </button>
```

If `S.paid_at` is set, show a paid indicator instead (mirror how the page shows other completed states) — e.g. near the settlement status badge:

```vue
                            <span v-if="S?.paid_at" class="text-[12px] font-bold text-emerald-600">Paid {{ formatDate(S.paid_at) }}</span>
```

(`formatDate`, `route`, `router`, `C`, `S` are already in scope.)

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

- [ ] **Step 3: Regression gate**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Offboarding`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — allow only the known `KioskRecentTest` flake if it is the sole failure.

Run: `php artisan migrate:fresh --seed`
Expected: completes cleanly.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Offboarding/Show.vue
git commit -m "feat(finance): mark-settlement-paid action in off-boarding UI"
git commit --allow-empty -m "test(finance): settlement S-2 payment + UI regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Pay the exact accrual net**: `postPayment` reads the accrual JE's `2300` credit line, so the payment clears precisely what was raised — robust against the snapshot/`net_payable` divergence in the shortfall case.
- **Nothing-owed case**: net 0 → no payment JE, but the settlement still flips to Paid (it *is* settled, there's just no cash leg).
- **Fail-loud bank resolution** mirrors `LoanService` (active Payroll-purpose bank, else `DomainException`).
- **Idempotent**: `'payment'` purpose on the source; re-paying returns the existing JE, and the `Approved → Paid` guard prevents re-entry from the service.
- **2FA**: the pay route carries `2fa:fresh` like `approve`; mirror the existing approve-endpoint test's 2FA handling.
- **Reversal of a paid settlement remains out of scope** (per the spec).
