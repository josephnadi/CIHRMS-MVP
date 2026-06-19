# Settlement Disbursement Tracking (provider rails)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a final settlement's net payout a real money-movement record on the existing disbursement rails (MoMo/GhIPSS) — provider reference, status (Pending→Sent→Settled/Failed), failure reason — WITHOUT changing the just-shipped GL flow. The GL clear stays immediate at `paySettlement`; the disbursement is additive tracking only.

**Architecture:** `Disbursement` gains a nullable `final_settlement_id` (and its payroll FKs become nullable). `BatchDisbursementService` gains `createForSettlement` (one Pending disbursement for the paid net) and reuses its providers via extracted `dispatchOne`/`reconcileOne`. Crucially, `settle()` **skips the GL post** for a settlement-linked disbursement (paySettlement already cleared net-pay payable → posting again would double-clear). `paySettlement` creates the disbursement after marking Paid; an off-boarding "Dispatch payout" action sends it to the provider; the settlement UI shows its status.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Decisions (locked — "additive tracking")

- GL stays immediate at `paySettlement` (DR 2300 / CR bank); the disbursement **never posts GL** (skip via `final_settlement_id !== null`). This is the whole point — no double-post, no async-Paid behaviour change.
- One disbursement per paid settlement, `gross_amount` = the net the payment JE actually paid (read from the `payment` JE's bank credit), e-levy applied on MoMo channels (Act 1075), `net_to_recipient = gross − e_levy`.
- Reuse the existing providers + send/refreshStatus; dispatch is a separate explicit action (like payroll), never network I/O inside the pay transaction.
- Migration edits the original `create_disbursements` migration to make payroll FKs nullable + add `final_settlement_id` — acceptable here (MVP, no prod data, `migrate:fresh` workflow; existing callers still pass the payroll FKs).

## Global Constraints

- `declare(strict_types=1)`; money tolerance `0.005`; new form/date inputs carry `aria-label`.
- All GL through `PostingService`; the settlement disbursement is the ONE disbursement type that posts none.

**Spec context:** off-boarding settlement→GL is complete (`docs/superpowers/specs/2026-06-19-finance-settlement-gl-posting-design.md`); this closes the "no provider money-movement record" gap two audits flagged.

---

### Task 1: Schema + model — settlement-linked disbursements

**Files:**
- Modify: `database/migrations/2026_05_31_000001_create_disbursements.php`
- Modify: `app/Models/Disbursement.php`
- Test: `tests/Feature/Disbursement/SettlementDisbursementSchemaTest.php`

**Interfaces:**
- Produces: `disbursements.final_settlement_id` (nullable FK); `payroll_run_id`/`payroll_line_id` now nullable; `Disbursement::finalSettlement()` belongsTo + `final_settlement_id` fillable.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\OffboardingCase;
use App\Models\User;

it('persists a disbursement linked to a settlement with no payroll run/line', function () {
    $employee = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference' => 'OFF-D-' . uniqid(), 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'exit_type' => 'resignation',
        'status' => 'awaiting_settlement', 'notice_received_on' => '2026-06-01',
        'last_working_day' => '2026-06-30', 'effective_termination_date' => '2026-06-30',
    ]);
    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'paid',
        'basic_salary' => 2000, 'years_of_service' => 1, 'accrued_leave_days' => 0, 'working_days_per_month' => 22,
        'gratuity' => 5000, 'severance' => 0, 'leave_encashment' => 0, 'prorated_13th_month' => 0, 'ex_gratia' => 0,
        'gross_settlement' => 5000, 'outstanding_loans' => 0, 'garnishments' => 0, 'other_deductions' => 0,
        'total_deductions' => 0, 'paye_on_settlement' => 0, 'net_payable' => 5000,
        'calculated_by' => User::factory()->create()->id, 'calculated_at' => now(), 'breakdown' => [],
    ]);

    $d = Disbursement::create([
        'final_settlement_id' => $settlement->id,
        'payroll_run_id' => null, 'payroll_line_id' => null,
        'employee_id' => $employee->id, 'channel' => 'ghipss_ach', 'status' => 'pending',
        'gross_amount' => 5000, 'e_levy' => 0, 'provider_fee' => 0, 'net_to_recipient' => 5000,
        'beneficiary_account' => '000123', 'beneficiary_name' => 'Leaver',
    ]);

    expect($d->fresh()->final_settlement_id)->toBe($settlement->id)
        ->and($d->finalSettlement->id)->toBe($settlement->id)
        ->and($d->payroll_run_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/SettlementDisbursementSchemaTest.php`
Expected: FAIL — `final_settlement_id` column / not-null payroll FK.

- [ ] **Step 3: Edit the migration**

In `database/migrations/2026_05_31_000001_create_disbursements.php`, change the three FK lines so the payroll ones are nullable and add the settlement link:

```php
            $table->foreignId('payroll_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('final_settlement_id')->nullable()->constrained()->nullOnDelete();
```

(Keep everything else. `final_settlement_id` → `final_settlements` table is inferred by `constrained()`.)

- [ ] **Step 4: Update the model**

In `app/Models/Disbursement.php`: add `'final_settlement_id'` to `$fillable` (next to the payroll/employee ids) and add the relation:

```php
    public function finalSettlement(): BelongsTo
    {
        return $this->belongsTo(FinalSettlement::class, 'final_settlement_id');
    }
```

- [ ] **Step 5: Migrate fresh + run the test**

Run: `php artisan migrate:fresh --seed`
Expected: completes (the altered disbursements table builds cleanly).

Run: `php artisan test tests/Feature/Disbursement/SettlementDisbursementSchemaTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_31_000001_create_disbursements.php app/Models/Disbursement.php tests/Feature/Disbursement/SettlementDisbursementSchemaTest.php
git commit -m "feat(disbursement): nullable payroll FKs + final_settlement_id link"
```

---

### Task 2: createForSettlement + dispatchOne/reconcileOne + GL-skip

**Files:**
- Modify: `app/Services/Disbursement/BatchDisbursementService.php`
- Test: `tests/Feature/Disbursement/SettlementDisbursementServiceTest.php`

**Interfaces:**
- Consumes: providers array (already injected), `PostingService`, the `payment` JE from settlement S-2.
- Produces:
  - `createForSettlement(FinalSettlement $settlement): ?Disbursement` — one Pending disbursement for the net the settlement's `payment` JE paid; null if there is no payment JE (nothing disbursed).
  - `dispatchOne(Disbursement $d): string` (`'sent'|'failed'|'skipped'`) and `reconcileOne(Disbursement $d): bool` — extracted from the existing loops; `dispatch()`/`reconcile()` now call them.
  - `settle()` returns early (no GL) when `$d->final_settlement_id !== null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\JournalEntry;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/../Offboarding/SettlementAccrualTest.php'; // seedSettlementWithLoan + settlementGl

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

/** A provider stub that reports Settled immediately. */
function settledProvider(string $channel): DisbursementProvider
{
    return new class($channel) implements DisbursementProvider {
        public function __construct(private string $ch) {}
        public function channel(): string { return $this->ch; }
        public function send(Disbursement $d): DisbursementResult {
            return new DisbursementResult(DisbursementStatus::Settled, 'PROV-REF-1', ['ok' => true], null);
        }
        public function refreshStatus(Disbursement $d): DisbursementResult {
            return new DisbursementResult(DisbursementStatus::Settled, 'PROV-REF-1', ['ok' => true], null);
        }
    };
}

it('creates a pending settlement disbursement for the paid net', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]); // net 6200
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    $svc = app(BatchDisbursementService::class);
    $d = $svc->createForSettlement($settlement->fresh());

    expect($d)->not->toBeNull()
        ->and($d->final_settlement_id)->toBe($settlement->id)
        ->and((float) $d->gross_amount)->toEqualWithDelta(6200.0, 0.01)
        ->and($d->status)->toBe(DisbursementStatus::Pending)
        ->and($d->payroll_run_id)->toBeNull();
});

it('dispatching a settlement disbursement posts NO GL (additive tracking only)', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    // Build the service with a stub GhIPSS provider that settles immediately.
    $svc = new BatchDisbursementService(
        ['ghipss_ach' => settledProvider('ghipss_ach')],
        app(\App\Services\Finance\PostingService::class),
    );
    $d = $svc->createForSettlement($settlement->fresh());

    $glBefore = JournalEntry::where('source_type', 'disbursement')->count();
    $svc->dispatchOne($d);

    expect($d->fresh()->status)->toBe(DisbursementStatus::Settled)
        ->and($d->fresh()->provider_reference)->toBe('PROV-REF-1')
        // NO disbursement-source GL entry was posted for this settlement disbursement.
        ->and(JournalEntry::where('source_type', 'disbursement')->count())->toBe($glBefore);
});
```

> Confirm `DisbursementResult`'s constructor signature before finalising the stub (read `app/Services/Disbursement/DisbursementResult.php`) — adjust the `new DisbursementResult(...)` args to match (status, providerReference, raw, failureReason order).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/SettlementDisbursementServiceTest.php`
Expected: FAIL — methods missing.

- [ ] **Step 3: Refactor dispatch/reconcile to per-row + skip GL for settlements**

In `app/Services/Disbursement/BatchDisbursementService.php`:

**(a)** Add the early return at the top of `settle()`:

```php
    private function settle(Disbursement $d): void
    {
        // Settlement disbursements are additive tracking only — the final-settlement
        // payment JE already cleared net-pay payable, so posting here would double-clear.
        if ($d->final_settlement_id !== null) {
            return;
        }

        $bankGlId = $this->resolveSettlementBankGlId();
        // ... unchanged ...
    }
```

**(b)** Extract `dispatchOne` and have `dispatch()` use it:

```php
    public function dispatch(PayrollRun $run): array
    {
        $sent = 0; $failed = 0; $skipped = 0;
        foreach (Disbursement::where('payroll_run_id', $run->id)->pending()->get() as $d) {
            match ($this->dispatchOne($d)) {
                'sent'   => $sent++,
                'failed' => $failed++,
                default  => $skipped++,
            };
        }
        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Send one pending disbursement to its provider. Returns 'sent'|'failed'|'skipped'. */
    public function dispatchOne(Disbursement $d): string
    {
        $provider = $this->providers[$d->channel->value] ?? null;
        if (! $provider) {
            return 'skipped'; // e.g. cash/cheque — handled manually
        }

        $result = $provider->send($d);

        DB::transaction(function () use ($d, $result) {
            $d->update([
                'status'             => $result->status->value,
                'provider_reference' => $result->providerReference,
                'provider_response'  => $result->raw,
                'sent_at'            => $result->status === DisbursementStatus::Sent ? now() : $d->sent_at,
                'settled_at'         => $result->status === DisbursementStatus::Settled ? now() : $d->settled_at,
                'failed_at'          => $result->status === DisbursementStatus::Failed ? now() : null,
                'failure_reason'     => $result->failureReason,
            ]);

            if ($result->status === DisbursementStatus::Settled) {
                $this->settle($d);
            }
        });

        return $result->status === DisbursementStatus::Failed ? 'failed' : 'sent';
    }
```

**(c)** Extract `reconcileOne` and have `reconcile()` use it:

```php
    public function reconcile(PayrollRun $run): int
    {
        $stale = Disbursement::where('payroll_run_id', $run->id)
            ->where('status', DisbursementStatus::Sent->value)
            ->where('sent_at', '<=', now()->subMinutes(5))
            ->get();

        $touched = 0;
        foreach ($stale as $d) {
            if ($this->reconcileOne($d)) $touched++;
        }
        return $touched;
    }

    /** Poll one sent disbursement; returns true if its status changed. */
    public function reconcileOne(Disbursement $d): bool
    {
        $provider = $this->providers[$d->channel->value] ?? null;
        if (! $provider) return false;

        $result = $provider->refreshStatus($d);
        if ($result->status === $d->status) return false;

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

        return true;
    }
```

**(d)** Add `createForSettlement` + an e-levy-by-date helper:

```php
    /**
     * Build a Pending disbursement for a paid final settlement's net (additive
     * tracking — the GL was already cleared by paySettlement). Returns null when
     * the settlement has no payment JE (nothing was disbursed, e.g. net zero).
     */
    public function createForSettlement(\App\Models\FinalSettlement $settlement): ?Disbursement
    {
        $paymentJe = \App\Models\JournalEntry::where('source_type', \App\Enums\JournalSourceType::FinalSettlement->value)
            ->where('source_id', $settlement->id)
            ->where('source_purpose', 'payment')
            ->first();

        if (! $paymentJe) {
            return null;
        }

        $paidNet = round((float) \App\Models\JournalLine::where('journal_entry_id', $paymentJe->id)->sum('credit_amount'), 2);
        if ($paidNet <= 0.0) {
            return null;
        }

        $case     = \App\Models\OffboardingCase::find($settlement->offboarding_case_id);
        $employee = $case?->employee_id ? \App\Models\Employee::find($case->employee_id) : null;
        $channel  = $this->resolveChannel($employee);

        $eLevy  = $channel->attractsELevy() ? round($paidNet * $this->eLevyRateOn(now()), 2) : 0.0;
        $netRcv = round($paidNet - $eLevy, 2);

        return Disbursement::create([
            'final_settlement_id' => $settlement->id,
            'payroll_run_id'      => null,
            'payroll_line_id'     => null,
            'employee_id'         => $employee?->id,
            'channel'             => $channel->value,
            'status'              => DisbursementStatus::Pending->value,
            'gross_amount'        => $paidNet,
            'e_levy'              => $eLevy,
            'provider_fee'        => 0,
            'net_to_recipient'    => $netRcv,
            'beneficiary_account' => $this->resolveBeneficiaryAccount($employee, $channel),
            'beneficiary_name'    => $employee?->user?->name,
        ]);
    }

    private function eLevyRateOn(\DateTimeInterface|string $date): float
    {
        try {
            return StatutoryRate::lookup('E_LEVY_RATE', $date);
        } catch (\Throwable $e) {
            return self::E_LEVY_FALLBACK_RATE;
        }
    }
```

(Have the existing `resolveELevyRate(PayrollRun $run)` delegate to `eLevyRateOn($run->period_end)` to avoid duplication, or leave it — your call; don't change its behaviour.)

`createForSettlement` requires `employee.user` for `beneficiary_name`; it's loaded lazily here (single row, not a batch) so no N+1 concern.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/SettlementDisbursementServiceTest.php`
Expected: PASS (both). Run the existing disbursement suite too — `php artisan test tests/Feature/Disbursement` — to confirm the dispatch/reconcile refactor didn't change payroll behaviour (a payroll disbursement settling STILL posts its GL).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Disbursement/BatchDisbursementService.php tests/Feature/Disbursement/SettlementDisbursementServiceTest.php
git commit -m "feat(disbursement): createForSettlement + dispatchOne/reconcileOne; settle skips GL for settlement disbursements"
```

---

### Task 3: Wire paySettlement + dispatch endpoint

**Files:**
- Modify: `app/Services/Offboarding/OffboardingService.php`
- Modify: `app/Policies/OffboardingCasePolicy.php`
- Modify: `app/Http/Controllers/OffboardingController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Offboarding/SettlementPayoutTest.php`

**Interfaces:**
- `paySettlement` creates the settlement disbursement (Pending) after marking Paid (only when a payment JE was posted).
- `POST offboarding/{case}/settlement/dispatch-payout` → dispatches the case's pending settlement disbursement; permission `offboarding.approve` + `2fa:fresh`; policy `dispatchPayout`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
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

it('creates a pending settlement disbursement when a settlement is paid', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    $d = Disbursement::where('final_settlement_id', $settlement->id)->first();
    expect($d)->not->toBeNull()
        ->and($d->status)->toBe(DisbursementStatus::Pending)
        ->and((float) $d->gross_amount)->toEqualWithDelta(6200.0, 0.01);
});

it('does not create a disbursement when nothing was paid (net zero)', function () {
    // gross fully absorbed by the loan → net 0 → no payment JE → no disbursement.
    [$settlement] = seedSettlementWithLoan(['gross' => 1100, 'paye' => 0, 'outstanding' => 3300]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect(Disbursement::where('final_settlement_id', $settlement->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/SettlementPayoutTest.php`
Expected: FAIL — paySettlement doesn't create a disbursement yet.

- [ ] **Step 3: Wire paySettlement**

In `app/Services/Offboarding/OffboardingService.php`, inject `BatchDisbursementService` into the constructor (after `$posting`):

```php
        private readonly \App\Services\Disbursement\BatchDisbursementService $disbursements,
```

In `paySettlement`, capture the payment JE and create the disbursement when it exists:

```php
        return DB::transaction(function () use ($settlement, $payer) {
            $je = $this->settlementPosting->postPayment($settlement, $payer);

            $settlement->update([
                'status'  => SettlementStatus::Paid->value,
                'paid_at' => now(),
            ]);

            if ($je !== null) {
                $this->disbursements->createForSettlement($settlement->fresh());
            }

            return $settlement->fresh();
        });
```

(`postPayment` already returns `?JournalEntry` — null when net is zero, which correctly skips disbursement creation.)

- [ ] **Step 4: Policy + controller + route for dispatch**

Policy (`OffboardingCasePolicy`, after `reverseSettlement`):

```php
    public function dispatchPayout(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('offboarding.approve');
    }
```

Controller (`OffboardingController`, after `reverseSettlement`):

```php
    public function dispatchPayout(Request $request, OffboardingCase $case, \App\Services\Disbursement\BatchDisbursementService $disbursements): RedirectResponse
    {
        $this->authorize('dispatchPayout', $case);

        $settlement = $case->settlement;
        $disb = $settlement
            ? \App\Models\Disbursement::where('final_settlement_id', $settlement->id)->where('status', 'pending')->first()
            : null;

        if (! $disb) {
            return back()->with('error', 'No pending payout to dispatch for this settlement.');
        }

        $disbursements->dispatchOne($disb);

        return back()->with('success', 'Settlement payout dispatched to the provider.');
    }
```

Route (`routes/web.php`, in the `offboarding` group after `settlement/reverse`):

```php
        Route::post('{case}/settlement/dispatch-payout',   [OffboardingController::class, 'dispatchPayout'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.dispatch-payout');
```

- [ ] **Step 5: Run test + commit**

Run: `php artisan test tests/Feature/Offboarding/SettlementPayoutTest.php`
Expected: PASS (both).

```bash
git add app/Services/Offboarding/OffboardingService.php app/Policies/OffboardingCasePolicy.php app/Http/Controllers/OffboardingController.php routes/web.php tests/Feature/Offboarding/SettlementPayoutTest.php
git commit -m "feat(offboarding): paySettlement creates settlement payout + dispatch-payout endpoint"
```

---

### Task 4: UI + regression gate

**Files:**
- Modify: `app/Http/Resources/OffboardingCaseResource.php` (or wherever `show` builds the settlement payload)
- Modify: `app/Http/Controllers/OffboardingController.php` (`show` — load the settlement disbursement)
- Modify: `app/Http/Resources/OffboardingCaseResource.php` — expose `settlement_payout`
- Modify: `resources/js/Pages/Offboarding/Show.vue`
- Test: none new (verification only).

- [ ] **Step 1: Expose the settlement payout to the page**

In `OffboardingController::show`, load the case's settlement disbursement and pass it (or add it to `OffboardingCaseResource`). Simplest: in the resource, when `settlement` exists, include a `settlement_payout` block:

```php
        // inside OffboardingCaseResource::toArray, where settlement is serialised:
        'settlement_payout' => optional(
            \App\Models\Disbursement::where('final_settlement_id', $this->settlement?->id)->latest('id')->first()
        )?->only(['id', 'channel', 'status', 'gross_amount', 'net_to_recipient', 'provider_reference', 'failure_reason']),
```

(Use the existing `can` block's `dispatchPayout` too:)

```php
                'dispatch_payout' => $request->user()?->can('dispatchPayout', $this->resource),
```

- [ ] **Step 2: Show payout status + dispatch action**

In `resources/js/Pages/Offboarding/Show.vue`, where the settlement section renders (near the pay/reverse actions), show the payout when present: channel + a status badge (pending=amber, sent=blue, settled=emerald, failed=rose), `net_to_recipient`, and `provider_reference` / `failure_reason`. When the payout status is `pending` and `C.can?.dispatch_payout`, render a "Dispatch payout" button posting to `route('offboarding.settlement.dispatch-payout', C.value.id)`. Mirror the file's existing action-button conventions.

```js
const dispatchPayout = () => router.post(route('offboarding.settlement.dispatch-payout', C.value.id), {}, { preserveScroll: true });
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds, no Vue errors.

- [ ] **Step 4: Regression gate**

Run: `php artisan test tests/Feature/Disbursement tests/Feature/Offboarding tests/Feature/Finance tests/Unit/Finance`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: completes cleanly.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/OffboardingCaseResource.php app/Http/Controllers/OffboardingController.php resources/js/Pages/Offboarding/Show.vue
git commit -m "feat(offboarding): surface settlement payout status + dispatch action in UI"
git commit --allow-empty -m "test(offboarding): settlement disbursement tracking regression gate green"
```

---

## Self-Review notes (for the implementer)

- **No double-post**: the whole design hinges on `settle()` returning early for `final_settlement_id !== null`. The Task 2 test asserts a settlement disbursement settling posts ZERO `disbursement`-source GL entries. The GL clear stays exclusively at `paySettlement`.
- **Paid net, not snapshot**: `createForSettlement` reads the actual paid amount from the `payment` JE's bank credit — correct in the shortfall case where `net_payable` (snapshot) differs.
- **Net-zero**: no payment JE → no disbursement (the Task 3 test asserts this).
- **Refactor safety**: `dispatch()`/`reconcile()` now delegate to `dispatchOne`/`reconcileOne`; existing payroll disbursement behaviour (which DOES post GL) is unchanged — verify via the existing disbursement suite.
- **Reconciliation of settlement payouts** (polling Sent→Settled for settlement disbursements via a scheduled job) is a follow-up; this plan ships create + manual dispatch + status, which is the additive-tracking goal.
- **2FA**: the dispatch route carries `2fa:fresh`; in tests mirror the S-2 `PaySettlementEndpointTest` 2FA-fresh helper if you add an endpoint happy-path test.
