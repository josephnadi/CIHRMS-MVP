# Finance — Settlement Reversal / Un-posting

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authorized user reverse an approved or paid final settlement — un-posting its GL entries, restoring the loans it cleared, and marking it cancelled — so a mistaken settlement can be safely undone instead of leaving the ledger and loans permanently wrong.

**Architecture:** `OffboardingService::reverseSettlement` reverses the payment JE (if posted) then the accrual JE via `PostingService::reverseFor`, restores the installments this settlement waived (marked `Cleared from final settlement {id}`) back to `Scheduled` + recomputes each loan, and flips the settlement to `Cancelled` with the reason in `notes` — all in one transaction. A policy method + route + controller action + a Show.vue action drive it.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Design decisions (locked)

- **Status**: a reversed settlement becomes `SettlementStatus::Cancelled` (no new enum case) with the reason appended to `notes`. A cancelled settlement can then be recalculated (the existing `calculateSettlement` soft-deletes a non-approved prior snapshot and creates a fresh one — a new row/id, so a later re-approval posts a clean new accrual).
- **What gets reversed**: the `payment` JE first (only if a posted one exists — net-zero/unpaid settlements have none), then the `accrual` JE (only if one exists — a `gross ≤ 0` settlement never posted one). Reversal uses `PostingService::reverseFor` (posts an opposite entry, leaving an audit trail; relies on the existing reverse semantics, including the closed-period behaviour other reversals already use).
- **Loan restore**: the installments this settlement waived are found by their marker `notes = 'Cleared from final settlement {id}'` AND `status = Waived`; set back to `Scheduled` (clear `notes`/`posted_at`). Per affected loan: `status → Repaying`, `actual_end_date → null`, `outstanding_balance →` Σ `scheduled_amount` of its now-`Scheduled` installments.
- **Guard**: only `Approved` or `Paid` settlements can be reversed; anything else throws. Idempotent by construction (after reversal the status is `Cancelled`, so a second call throws).
- **Authorization**: `offboarding.approve` + `2fa:fresh` (mirrors approve/pay).

**Spec context:** `docs/superpowers/specs/2026-06-19-finance-settlement-gl-posting-design.md` listed reversal as out-of-scope for S-1/S-2; this plan adds it.

---

### Task 1: reverseSettlement service

**Files:**
- Modify: `app/Services/Offboarding/OffboardingService.php`
- Test: `tests/Feature/Offboarding/SettlementReversalTest.php`

**Interfaces:**
- Consumes: `PostingService::reverseFor(JournalSourceType, int, string, User, string)`; `JournalEntry`, `LoanAccount`, `LoanRepayment`, `LoanStatus`, `LoanRepaymentStatus`, `SettlementStatus`, `JournalEntryStatus`.
- Produces: `OffboardingService::reverseSettlement(FinalSettlement $settlement, User $by, string $reason): FinalSettlement`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Enums\SettlementStatus;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

it('reverses an approved settlement: un-posts the accrual and restores the loan', function () {
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    // After accrual: loan paid off, 1300 cleared to 0, expense recognised.
    expect(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01)
        ->and($loan->fresh()->status)->toBe(LoanStatus::PaidOff);

    $reversed = app(OffboardingService::class)->reverseSettlement($settlement->fresh(), User::factory()->create(), 'Wrong figures');

    expect($reversed->status)->toBe(SettlementStatus::Cancelled)
        ->and($reversed->notes)->toContain('Wrong figures');

    // GL restored: accrual reversed → 1300 back to 3000, 5130 net 0.
    expect(settlementGl('1300'))->toEqualWithDelta(3000.0, 0.01)
        ->and(settlementGl('5130'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('4600'))->toEqualWithDelta(0.0, 0.01);

    // Loan restored: installments scheduled again, loan repaying, balance back.
    expect(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(3)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Repaying)
        ->and((float) $loan->fresh()->outstanding_balance)->toEqualWithDelta(3300.0, 0.01);
});

it('reverses a paid settlement: un-posts both payment and accrual', function () {
    OrgBankAccount::factory()->create([
        'purpose' => 'payroll', 'is_active' => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect(settlementGl('2300'))->toEqualWithDelta(0.0, 0.01)      // paid: liability cleared
        ->and(settlementGl('1110'))->toEqualWithDelta(-6200.0, 0.01);

    app(OffboardingService::class)->reverseSettlement($settlement->fresh(), User::factory()->create(), 'Paid in error');

    // Payment reversed → bank back to 0; accrual reversed → 2300 back to 0, 1300 back to 3000.
    expect(settlementGl('1110'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('2300'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('1300'))->toEqualWithDelta(3000.0, 0.01);
});

it('refuses to reverse a settlement that is neither approved nor paid', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    $settlement->update(['status' => 'calculated']);

    expect(fn () => app(OffboardingService::class)->reverseSettlement($settlement->fresh(), User::factory()->create(), 'x'))
        ->toThrow(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/SettlementReversalTest.php`
Expected: FAIL — `reverseSettlement` missing.

- [ ] **Step 3: Add the imports**

In `app/Services/Offboarding/OffboardingService.php`, ensure these are imported (add any missing — `LoanRepayment`/`LoanRepaymentStatus` were removed in S-1 and are needed again; `JournalEntry`, `JournalEntryStatus`, `JournalSourceType`, `PostingService` are new here):

```php
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Models\JournalEntry;
use App\Models\LoanRepayment;
use App\Services\Finance\PostingService;
```

(`LoanAccount`, `LoanStatus`, `SettlementStatus`, `FinalSettlement`, `User`, `DB` are already imported.)

- [ ] **Step 4: Inject PostingService**

Add `PostingService` to the constructor (after `$settlementPosting`):

```php
    public function __construct(
        private readonly FinalSettlementCalculator $calculator,
        private readonly SequenceService $sequences,
        private readonly SettlementPostingService $settlementPosting,
        private readonly PostingService $posting,
    ) {}
```

- [ ] **Step 5: Add the method**

After `paySettlement`:

```php
    /**
     * Reverse an approved or paid settlement: un-post its GL entries (payment
     * then accrual), restore the loans it cleared, and mark it Cancelled.
     */
    public function reverseSettlement(FinalSettlement $settlement, User $by, string $reason): FinalSettlement
    {
        if (! in_array($settlement->status, [SettlementStatus::Approved, SettlementStatus::Paid], true)) {
            throw new \DomainException('Only an approved or paid settlement can be reversed.');
        }

        return DB::transaction(function () use ($settlement, $by, $reason) {
            // 1) Reverse the GL entries that exist (payment first, then accrual).
            foreach (['payment', 'accrual'] as $purpose) {
                $posted = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
                    ->where('source_id', $settlement->id)
                    ->where('source_purpose', $purpose)
                    ->where('status', JournalEntryStatus::Posted->value)
                    ->exists();

                if ($posted) {
                    $this->posting->reverseFor(
                        JournalSourceType::FinalSettlement,
                        $settlement->id,
                        $purpose,
                        $by,
                        "Settlement reversal: {$reason}",
                    );
                }
            }

            // 2) Restore the loans this settlement cleared.
            $this->restoreClearedLoans($settlement);

            // 3) Mark the settlement cancelled with the reason.
            $settlement->update([
                'status' => SettlementStatus::Cancelled->value,
                'notes'  => trim(($settlement->notes ? $settlement->notes . "\n" : '') . "[REVERSED] {$reason}"),
            ]);

            return $settlement->fresh();
        });
    }

    /** Un-waive the installments this settlement cleared and rebuild each affected loan. */
    private function restoreClearedLoans(FinalSettlement $settlement): void
    {
        $marker = 'Cleared from final settlement ' . $settlement->id;

        $restored = LoanRepayment::where('notes', $marker)
            ->where('status', LoanRepaymentStatus::Waived->value)
            ->lockForUpdate()
            ->get();

        if ($restored->isEmpty()) {
            return;
        }

        foreach ($restored->groupBy('loan_account_id') as $loanId => $insts) {
            LoanRepayment::whereIn('id', $insts->pluck('id'))->update([
                'status'    => LoanRepaymentStatus::Scheduled->value,
                'notes'     => null,
                'posted_at' => null,
            ]);

            $loan = LoanAccount::find($loanId);
            if (! $loan) {
                continue;
            }

            $outstanding = (float) LoanRepayment::where('loan_account_id', $loanId)
                ->where('status', LoanRepaymentStatus::Scheduled->value)
                ->sum('scheduled_amount');

            $loan->update([
                'status'              => LoanStatus::Repaying->value,
                'outstanding_balance' => round($outstanding, 2),
                'actual_end_date'     => null,
            ]);
        }
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Offboarding/SettlementReversalTest.php`
Expected: PASS (all three).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Offboarding/OffboardingService.php tests/Feature/Offboarding/SettlementReversalTest.php
git commit -m "feat(finance): reverseSettlement — un-post accrual/payment + restore loans"
```

---

### Task 2: HTTP + UI

**Files:**
- Modify: `app/Policies/OffboardingCasePolicy.php`
- Modify: `app/Http/Resources/OffboardingCaseResource.php`
- Modify: `app/Http/Controllers/OffboardingController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Offboarding/Show.vue`
- Test: `tests/Feature/Offboarding/ReverseSettlementEndpointTest.php`

**Interfaces:**
- Consumes: `OffboardingService::reverseSettlement` (Task 1); permission `offboarding.approve`.
- Produces: `POST offboarding/{case}/settlement/reverse` → `offboarding.settlement.reverse` (validates `reason`); `can.reverse_settle` on the resource.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
use App\Models\TwoFactor\TwoFactorService;
use App\Models\User;
use App\Services\Offboarding\SettlementPostingService;
use App\Services\TwoFactor\TwoFactorService as TfService;
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
});

it('lets an authorized user reverse an approved settlement', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $actor = User::factory()->create(['role' => 'super_admin']); // before() bypass
    $this->actingAs($actor)
        ->post("/offboarding/{$case->id}/settlement/reverse", ['reason' => 'Wrong figures'])
        ->assertRedirect();

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Cancelled);
});

it('forbids a user without offboarding.approve from reversing', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post("/offboarding/{$case->id}/settlement/reverse", ['reason' => 'x'])
        ->assertForbidden();
});
```

> The `super_admin` actor bypasses the policy via `before()` and (per the existing 2FA-fresh pattern) also satisfies `2fa:fresh` only if confirmed+fresh. **Before running, check how `tests/Feature/Offboarding/PaySettlementEndpointTest.php` (added in S-2) satisfies `2fa:fresh`** and mirror that exact helper here for the happy path (it uses `forceFill(['two_factor_confirmed_at' => now()])->save()` + `TwoFactorService::markFresh($user)`). Fix the imports to the real `TwoFactorService` namespace used there (the two `use` lines above are placeholders — replace with whatever PaySettlementEndpointTest imports). The forbidden case needs no 2FA.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/ReverseSettlementEndpointTest.php`
Expected: FAIL — route/action/policy missing.

- [ ] **Step 3: Policy + resource**

In `app/Policies/OffboardingCasePolicy.php`, after `paySettlement`:

```php
    public function reverseSettlement(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('offboarding.approve');
    }
```

In `app/Http/Resources/OffboardingCaseResource.php`, after `pay_settle`:

```php
                'reverse_settle'  => $request->user()?->can('reverseSettlement', $this->resource),
```

- [ ] **Step 4: Controller action**

In `app/Http/Controllers/OffboardingController.php`, after `paySettlement`:

```php
    public function reverseSettlement(Request $request, OffboardingCase $case): RedirectResponse
    {
        $this->authorize('reverseSettlement', $case);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $settlement = $case->settlement;
        if (! $settlement) {
            return back()->with('error', 'No settlement exists for this case.');
        }

        try {
            $this->service->reverseSettlement($settlement, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Settlement for {$case->reference} reversed.");
    }
```

- [ ] **Step 5: Route**

In `routes/web.php`, inside the `offboarding` group (after the `settlement/pay` route):

```php
        Route::post('{case}/settlement/reverse',           [OffboardingController::class, 'reverseSettlement'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.reverse');
```

- [ ] **Step 6: UI — Reverse action with a reason**

In `resources/js/Pages/Offboarding/Show.vue`, add a reverse action that prompts for a reason and posts it. Mirror the existing cancel-with-reason pattern already in the file (search for `cancelForm`/`showCancel` — reuse that modal style). Add:

```js
const reverseForm = useForm({ reason: '' });
const showReverse = ref(false);
const reverse = () => reverseForm.post(route('offboarding.settlement.reverse', C.value.id), {
    preserveScroll: true,
    onSuccess: () => { showReverse.value = false; reverseForm.reset(); },
});
```

Render a **Reverse Settlement** button when `(S?.status === 'approved' || S?.status === 'paid') && C.can?.reverse_settle` (place it near the pay/complete actions, styled as a destructive/secondary action consistent with the existing cancel button), opening a small reason prompt that calls `reverse()`. Match the file's existing modal/markup conventions.

- [ ] **Step 7: Build + run the test**

Run: `npm run build`
Expected: succeeds.

Run: `php artisan test tests/Feature/Offboarding/ReverseSettlementEndpointTest.php`
Expected: PASS (both).

- [ ] **Step 8: Regression gate + commit**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Offboarding`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — allow only the known `KioskRecentTest` flake.

```bash
git add app/Policies/OffboardingCasePolicy.php app/Http/Resources/OffboardingCaseResource.php app/Http/Controllers/OffboardingController.php routes/web.php resources/js/Pages/Offboarding/Show.vue tests/Feature/Offboarding/ReverseSettlementEndpointTest.php
git commit -m "feat(finance): reverse-settlement endpoint + policy + UI action"
```

---

## Self-Review notes (for the implementer)

- **GL symmetry**: reversing the accrual restores 1300 to its pre-settlement principal and nets 5130/4600/2300 back to 0; reversing the payment restores the bank and 2300. Both rely on `PostingService::reverseFor` posting an opposite-signed entry (status pair nets to 0, matching `gl_account_balances`).
- **Loan restore is marker-driven**: only installments tagged `Cleared from final settlement {id}` and still `Waived` are restored, so installments paid/waived by other means are untouched; `outstanding_balance` is rebuilt from the now-`Scheduled` rows (not arithmetic on the old value).
- **Order matters**: reverse `payment` before `accrual` (mirror of post order); both guarded by an existence check so net-zero/unpaid/`gross≤0` settlements reverse cleanly with fewer (or no) JEs.
- **Idempotent**: after reversal the status is `Cancelled`, so a re-call throws the guard; a subsequent recalculate creates a fresh settlement row (new id → clean new accrual, no idempotency collision).
- **2FA**: mirror the S-2 `PaySettlementEndpointTest` 2FA-fresh helper exactly.
