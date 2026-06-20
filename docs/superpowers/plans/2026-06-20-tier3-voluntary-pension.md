# Tier-3 Voluntary Pension Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire Tier-3 voluntary pension into payroll — a per-employee percentage election that deducts from net pay, reduces PAYE chargeable up to the 16.5% combined cap, credits GL 2230, and produces a per-trustee statutory schedule.

**Architecture:** `Tier3Calculator` (mirrors `Tier2Calculator`) computes elected/relieved/excess; `PayrollService::calculateLine` applies it; the accrual JE credits `payroll.tier3_payable`; `StatutoryReturnGenerator::generateTier3PerTrustee` mirrors the Tier-2 schedule. Enrolment is `employees.tier3_rate` + `tier3_trustee_id`.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- **Backwards-compatible**: `tier3_rate` null/0 ⇒ Tier-3 = 0 ⇒ chargeable/net/JE byte-identical to today. The existing payroll suite MUST stay green.
- Mirror `Tier2Calculator`, `generateTier2PerTrustee`, `tier2_trustee_id`/`tier2Trustee`.
- `declare(strict_types=1)`; effective-dated `StatutoryRate::lookup`; money tolerance 0.005.
- New form inputs carry `aria-label`.

**Spec:** `docs/superpowers/specs/2026-06-20-tier3-voluntary-pension-design.md`

---

### Task 1: Tier3Calculator (+ ensure rates seeded)

**Files:**
- Create: `app/Services/Payroll/Tier3Calculator.php`
- Modify (only if needed): `database/seeders/GhanaStatutoryReferenceSeeder.php`
- Test: `tests/Feature/Payroll/Tier3CalculatorTest.php`

**Interfaces:**
- Produces: `Tier3Calculator::calculate(float $basic, float $rate, \DateTimeInterface|string $effectiveOn): array{employee: float, relieved: float, excess: float}`.

- [ ] **Step 1: Confirm the rate constants are seeded**

Read `database/seeders/GhanaStatutoryReferenceSeeder.php`. Confirm `TIER2_EMPLOYER` (0.05) and `TIER3_MAX_COMBINED` (0.165) are seeded as `StatutoryRate` rows (Tier-2 already works, so `TIER2_EMPLOYER` is present). If `TIER3_MAX_COMBINED` is NOT seeded, add it (rate `0.165`, `is_rate=true`, currency `'GHS'`, `effective_from` matching the other rates) — match the file's array/insert pattern. Report which you added.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\Payroll\Tier3Calculator;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->calc = app(Tier3Calculator::class);
    $this->date = '2026-06-30';
});

it('computes a fully-relieved contribution under the cap', function () {
    // basic 5000, rate 5% → elected 250; cap headroom = (16.5%-5%) of 5000 = 575 → fully relieved
    $r = $this->calc->calculate(5000, 0.05, $this->date);
    expect($r['employee'])->toBe(250.0)
        ->and($r['relieved'])->toBe(250.0)
        ->and($r['excess'])->toBe(0.0);
});

it('splits an over-cap contribution into relieved + taxed excess', function () {
    // basic 5000, rate 15% → elected 750; relief headroom = 11.5% of 5000 = 575
    $r = $this->calc->calculate(5000, 0.15, $this->date);
    expect($r['employee'])->toBe(750.0)
        ->and($r['relieved'])->toBe(575.0)   // capped at (16.5-5)% of basic
        ->and($r['excess'])->toBe(175.0);    // 750 - 575, still deducted but taxed
});

it('is a no-op for a zero rate or zero basic', function () {
    expect($this->calc->calculate(5000, 0.0, $this->date))->toBe(['employee' => 0.0, 'relieved' => 0.0, 'excess' => 0.0])
        ->and($this->calc->calculate(0, 0.05, $this->date))->toBe(['employee' => 0.0, 'relieved' => 0.0, 'excess' => 0.0]);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/Tier3CalculatorTest.php`
Expected: FAIL — calculator missing.

- [ ] **Step 4: Write the calculator**

`app/Services/Payroll/Tier3Calculator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRate;

/**
 * Tier-3 voluntary pension calculator.
 *
 * Tier-3 (Pensions Act 2008, Act 766) is a voluntary employee contribution,
 * elected as a percentage of basic. It is tax-relieved up to a combined Tier-2 +
 * Tier-3 ceiling of 16.5% of basic; since Tier-2 mandatory is 5%, up to 11.5% of
 * basic of Tier-3 reduces chargeable income, and any elected excess is still
 * deducted but taxed.
 */
class Tier3Calculator
{
    /**
     * @return array{employee: float, relieved: float, excess: float}
     */
    public function calculate(float $basic, float $rate, \DateTimeInterface|string $effectiveOn): array
    {
        if ($basic <= 0 || $rate <= 0) {
            return ['employee' => 0.0, 'relieved' => 0.0, 'excess' => 0.0];
        }

        $elected = round($basic * $rate, 2);

        $cap   = StatutoryRate::lookup(StatutoryRate::TIER3_MAX_COMBINED, $effectiveOn); // 0.165
        $tier2 = StatutoryRate::lookup(StatutoryRate::TIER2_EMPLOYER, $effectiveOn);      // 0.05

        $availableRelief = round(max(0.0, $cap - $tier2) * $basic, 2);
        $relieved = round(min($elected, $availableRelief), 2);
        $excess   = round($elected - $relieved, 2);

        return ['employee' => $elected, 'relieved' => $relieved, 'excess' => $excess];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Payroll/Tier3CalculatorTest.php`
Expected: PASS (all three).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payroll/Tier3Calculator.php tests/Feature/Payroll/Tier3CalculatorTest.php database/seeders/GhanaStatutoryReferenceSeeder.php
git commit -m "feat(payroll): Tier3Calculator (percentage election, 16.5% combined relief cap)"
```

(Only stage the seeder if you modified it.)

---

### Task 2: Employee Tier-3 enrolment columns

**Files:**
- Create: `database/migrations/2026_06_20_000001_add_tier3_to_employees.php`
- Modify: `app/Models/Employee.php`
- Test: `tests/Feature/Payroll/EmployeeTier3EnrolmentTest.php`

**Interfaces:**
- Produces: `employees.tier3_rate` (decimal 6,4, default 0), `employees.tier3_trustee_id` (nullable FK); `Employee` fillable + cast `tier3_rate` decimal:4 + `tier3Trustee()` belongsTo.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\PensionTrustee;

it('stores a Tier-3 election with a trustee', function () {
    $trustee = PensionTrustee::factory()->create();
    $employee = Employee::factory()->create(['tier3_rate' => 0.05, 'tier3_trustee_id' => $trustee->id]);

    expect((float) $employee->fresh()->tier3_rate)->toBe(0.05)
        ->and($employee->fresh()->tier3Trustee->id)->toBe($trustee->id);
});

it('defaults Tier-3 rate to zero', function () {
    expect((float) Employee::factory()->create()->tier3_rate)->toBe(0.0);
});
```

> Confirm `PensionTrustee` has a factory (read `database/factories/` — if not, create the trustee with `PensionTrustee::create([...])` matching its fillable instead).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/EmployeeTier3EnrolmentTest.php`
Expected: FAIL — columns/relation missing.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_06_20_000001_add_tier3_to_employees.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('tier3_rate', 6, 4)->default(0)->after('tier2_trustee_id');
            $table->foreignId('tier3_trustee_id')->nullable()->after('tier3_rate')
                ->constrained('pension_trustees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tier3_trustee_id');
            $table->dropColumn('tier3_rate');
        });
    }
};
```

> If `tier2_trustee_id` isn't a column you can `after()` (it is — confirmed in `2026_05_25_000002`), drop the `->after(...)` clauses.

- [ ] **Step 4: Update the model**

In `app/Models/Employee.php`: add `'tier3_rate'` and `'tier3_trustee_id'` to `$fillable` (next to `'tier2_trustee_id'`); add `'tier3_rate' => 'decimal:4'` to the casts; add the relation (next to `tier2Trustee`):

```php
    public function tier3Trustee(): BelongsTo
    {
        return $this->belongsTo(PensionTrustee::class, 'tier3_trustee_id');
    }
```

- [ ] **Step 5: Migrate + run the test**

Run: `php artisan migrate:fresh --seed`
Expected: clean.

Run: `php artisan test tests/Feature/Payroll/EmployeeTier3EnrolmentTest.php`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_20_000001_add_tier3_to_employees.php app/Models/Employee.php tests/Feature/Payroll/EmployeeTier3EnrolmentTest.php
git commit -m "feat(payroll): employee Tier-3 enrolment columns (tier3_rate + tier3_trustee_id)"
```

---

### Task 3: Wire PayrollService::calculateLine

**Files:**
- Modify: `app/Services/Payroll/PayrollService.php`
- Test: `tests/Feature/Payroll/Tier3PayrollLineTest.php`

**Interfaces:**
- Consumes: `Tier3Calculator` (Task 1), `employee.tier3_rate` (Task 2).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->svc = app(PayrollService::class);
});

/** Run a single-employee payroll for an employee and return their calculated line. */
function runLineFor(Employee $employee): PayrollLine
{
    $svc = app(PayrollService::class);
    $run = $svc->createDraft(2026, 6, $employee->department_id, User::factory()->create());
    $svc->calculate($run->fresh());

    return PayrollLine::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
}

it('deducts Tier-3, lowers PAYE via relief, and reduces net for an enrolled employee', function () {
    $dept = App\Models\Department::factory()->create();
    $base = Employee::factory()->create(['department_id' => $dept->id, 'salary' => 6000, 'tier3_rate' => 0]);
    $enrolled = Employee::factory()->create(['department_id' => $dept->id, 'salary' => 6000, 'tier3_rate' => 0.05]);

    $baseLine = runLineFor($base);
    $enrolledLine = runLineFor($enrolled);

    // Tier-3 elected = 5% of basic; net pay drops by at least the Tier-3 amount; PAYE is lower (relief).
    expect((float) $enrolledLine->tier3_employee)->toBeGreaterThan(0.0)
        ->and((float) $baseLine->tier3_employee)->toBe(0.0)
        ->and((float) $enrolledLine->paye)->toBeLessThan((float) $baseLine->paye)        // relief lowered chargeable
        ->and((float) $enrolledLine->net)->toBeLessThan((float) $baseLine->net);          // Tier-3 left net pay
});
```

> Adjust `runLineFor`/factory fields to the real payroll setup — read how an existing payroll test builds a run + employee (salary/grade/step that yields a non-zero basic). The KEY assertions (tier3>0, paye lower, net lower for the enrolled vs identical base employee) are what matter; tune the scaffolding to make a clean run calculate.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/Tier3PayrollLineTest.php`
Expected: FAIL — `tier3_employee` still 0.

- [ ] **Step 3: Inject Tier3Calculator + wire the line**

In `app/Services/Payroll/PayrollService.php`:

Add `Tier3Calculator` to the constructor (next to the `$tier2` / `Tier2Calculator` dependency — match how `$ssnit`/`$tier2`/`$paye` are injected):

```php
        private readonly Tier3Calculator $tier3,
```

In `calculateLine`, after `$tier2 = $this->tier2->calculate($basic, $periodDate);` add:

```php
        $tier3 = $this->tier3->calculate($basic, (float) ($employee->tier3_rate ?? 0), $periodDate);
```

Change the chargeable line to subtract the relieved Tier-3:

```php
        $chargeable = max(round($taxableGross - $ssnit['employee'] - $tier3['relieved'], 2), 0);
```

Change the hardcoded Tier-3 line:

```php
        'tier3_employee'        => $tier3['employee'],
```

Change the net line to subtract the full Tier-3 (find the `$netAfterStatutory`/`net` computation, currently `round($gross - $ssnit['employee'] - $paye, 2)`):

```php
        $netAfterStatutory = round($gross - $ssnit['employee'] - $tier3['employee'] - $paye, 2);
```

(Use the real variable names from the file — `$netAfterStatutory` at ~line 187. If loans/voluntary deductions are subtracted further downstream to reach the final `net`, leave that chain intact; only insert the `- $tier3['employee']` at the statutory-net step.)

- [ ] **Step 4: Run the new test + the full payroll suite**

Run: `php artisan test tests/Feature/Payroll/Tier3PayrollLineTest.php`
Expected: PASS.

Run: `php artisan test tests/Feature/Payroll tests/Unit/Payroll`
Expected: PASS — existing payroll tests unchanged (non-enrolled employees have `tier3_rate` 0 ⇒ identical output). If any existing test broke, an employee in that test must have a non-zero `tier3_rate` by accident, OR the net/chargeable chain was altered for the zero case — investigate; do NOT weaken assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payroll/PayrollService.php tests/Feature/Payroll/Tier3PayrollLineTest.php
git commit -m "feat(payroll): compute Tier-3 in calculateLine (deduct + relieve chargeable + reduce net)"
```

---

### Task 4: Accrual JE credit + Tier-3 statutory schedule

**Files:**
- Modify: `app/Services/Payroll/PayrollService.php` (accrual document)
- Modify: `app/Services/Payroll/StatutoryReturnGenerator.php`
- Modify: `app/Models/Employee.php` (already has `tier3Trustee` from Task 2)
- Test: `tests/Feature/Payroll/Tier3AccrualAndReturnTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutoryReturnKind;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccountBalance;
use App\Models\PensionTrustee;
use App\Models\StatutoryReturn;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // actor-less posting fallback
});

it('credits GL 2230 and generates a Tier-3 schedule on approval', function () {
    $svc = app(PayrollService::class);
    $dept = Department::factory()->create();
    $trustee = PensionTrustee::factory()->create();
    Employee::factory()->create(['department_id' => $dept->id, 'salary' => 6000,
        'tier3_rate' => 0.05, 'tier3_trustee_id' => $trustee->id]);

    $run = $svc->calculate($svc->createDraft(2026, 6, $dept->id, User::factory()->create())->fresh());
    $approver = User::factory()->create(['role' => 'super_admin']);
    $svc->approve($run->fresh(), $approver);

    // GL 2230 (Tier-3 payable) credited by tier3_total.
    $tier3Gl = (float) GlAccountBalance::query()->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
        ->where('gl_accounts.code', '2230')->value('gl_account_balances.balance');
    expect($tier3Gl)->toBeGreaterThan(0.0)
        ->and((float) $run->fresh()->tier3_total)->toEqualWithDelta($tier3Gl, 0.01);

    // A Tier-3 statutory schedule was generated.
    expect(StatutoryReturn::where('payroll_run_id', $run->id)->where('kind', StatutoryReturnKind::Tier3->value)->exists())->toBeTrue();
});
```

> Match how the existing payroll-approval tests set up a run that approves cleanly (the approval posts the accrual + generates returns). Reuse their scaffolding. The KEY assertions: 2230 credited = tier3_total, and a Tier-3 `StatutoryReturn` row exists.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/Tier3AccrualAndReturnTest.php`
Expected: FAIL — no 2230 credit / no Tier-3 return.

- [ ] **Step 3: Add the accrual credit**

In `PayrollService` `buildAccrualDocument` (the `$candidates` array, after the `payroll.tier2_payable` credit):

```php
            $credit('payroll.tier3_payable',              round((float) $run->tier3_total, 2), 'Tier-3 voluntary'),
```

(The `$credit` closure already omits zero amounts, so runs with no Tier-3 are unaffected and stay balanced.)

- [ ] **Step 4: Add the Tier-3 statutory schedule**

In `StatutoryReturnGenerator`, add `generateTier3PerTrustee` mirroring `generateTier2PerTrustee` (group by `tier3_trustee_id`, use `tier3Trustee`, sum `tier3_employee`, `StatutoryReturnKind::Tier3`), and call it in `generateAll` alongside the Tier-2 push:

```php
    public function generateTier3PerTrustee(PayrollRun $run, Collection $lines): array
    {
        $byTrustee = $lines->filter(fn ($l) => (float) $l->tier3_employee > 0)
            ->groupBy(fn (\App\Models\PayrollLine $line) => $line->employee?->tier3_trustee_id ?? 'unassigned');

        $returns = [];
        foreach ($byTrustee as $trusteeId => $group) {
            $rows = $group->map(fn (\App\Models\PayrollLine $line) => [
                $line->employee?->user?->name ?? '',
                $line->employee?->tier3Trustee?->npra_license_number ?? '',
                $this->fmt($line->tier3_employee),
            ])->all();

            $returns[] = $this->writeFile(
                $run,
                StatutoryReturnKind::Tier3,
                ['Employee', 'Trustee NPRA', 'Tier-3'],
                $rows,
                (float) $group->sum('tier3_employee'),
                $trusteeId === 'unassigned' ? null : (int) $trusteeId,
            );
        }

        return $returns;
    }
```

In `generateAll`, eager-load `employee.tier3Trustee` (add to the existing `->with([...])`) and push the Tier-3 returns:

```php
        $generated->push(...$this->generateTier3PerTrustee($run, $lines));
```

> Match `generateTier2PerTrustee`'s exact column/row shape and `writeFile` signature (confirm `writeFile(run, kind, columns, rows, total, ?trusteeId)` — it is, per the Tier-2 method). Adjust the `$columns`/`$rows` to the real `writeFile` contract if it differs.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Payroll/Tier3AccrualAndReturnTest.php`
Expected: PASS.

Run: `php artisan test tests/Feature/Payroll tests/Feature/Finance`
Expected: PASS (accrual still balances for non-Tier-3 runs; finance posting unaffected).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payroll/PayrollService.php app/Services/Payroll/StatutoryReturnGenerator.php tests/Feature/Payroll/Tier3AccrualAndReturnTest.php
git commit -m "feat(payroll): credit Tier-3 payable (2230) + generate Tier-3 statutory schedule per trustee"
```

---

### Task 5: Employee Tier-3 form field + gate

**Files:**
- Modify: the employee store/update FormRequest (find it — `app/Http/Requests/.../*Employee*Request.php`)
- Modify: the employee create/edit Vue form (find it — `resources/js/Pages/Employees/*` or wherever the employee form lives)
- Modify: the employee resource/controller payload IF the trustee list isn't already passed
- Test: none new (verification only) — or a small request-validation test if quick.

- [ ] **Step 1: Validate the new fields**

In the employee store + update FormRequest `rules()`, add:

```php
            'tier3_rate'       => ['nullable', 'numeric', 'min:0', 'max:0.5'],
            'tier3_trustee_id' => ['nullable', 'integer', 'exists:pension_trustees,id'],
```

(Match the existing `tier2_trustee_id` validation if present.)

- [ ] **Step 2: Add the form field**

In the employee create/edit Vue form, near the Tier-2 trustee / statutory fields (SSNIT/TIN/Tier-2), add a **Tier-3 rate** numeric input (label e.g. "Tier-3 voluntary %", `aria-label="Tier-3 voluntary rate"`, stepped, bound to the form's `tier3_rate`) and a **Tier-3 trustee** select (mirror the Tier-2 trustee select if one exists; `aria-label="Tier-3 trustee"`). If the page already lists pension trustees for the Tier-2 select, reuse that prop; otherwise pass the trustee list from the controller the same way Tier-2 does.

Enter the rate as a fraction (0.05) to match storage, or as a percent with a `/100` on submit — match how the page handles `tier2`/rate fields; keep it consistent and labelled.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds, no Vue errors.

- [ ] **Step 4: Regression gate**

Run: `php artisan test tests/Feature/Payroll tests/Unit/Payroll tests/Feature/Finance`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — accessibility green (new inputs carry `aria-label`); allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: completes (the Tier-3 columns migrate).

- [ ] **Step 5: Commit**

```bash
git add <employee-request> <employee-vue-form> <controller-if-changed>
git commit -m "feat(payroll): employee Tier-3 election field (rate + trustee) in the employee form"
git commit --allow-empty -m "test(payroll): Tier-3 voluntary pension regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Backwards-compatible is the linchpin**: `tier3_rate` defaults 0; a zero rate makes `Tier3Calculator` a no-op and every downstream value identical to today. If an existing payroll test breaks, the wiring leaked into the zero case — fix the wiring, never the test.
- **Relief vs deduction**: `relieved` reduces PAYE chargeable (capped at (16.5−5)% of basic); `employee` (the full elected amount) reduces net pay. Excess above the cap is in `employee` but not `relieved` → taxed.
- **JE balance**: the line `net` already dropped by Σ Tier-3, so crediting `tier3_total` to 2230 keeps `DR gross = ΣCR`. The `$credit` closure drops zero amounts, so non-Tier-3 runs are untouched.
- **Statutory schedule**: mirror Tier-2 exactly (group by trustee, `writeFile` per group); only include lines with a positive Tier-3.
- **Accessibility**: the Tier-3 rate + trustee inputs carry `aria-label`.
