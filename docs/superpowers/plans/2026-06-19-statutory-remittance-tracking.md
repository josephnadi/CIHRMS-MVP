# Statutory Remittance Submission Tracking

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let finance record when a statutory return (PAYE / SSNIT / Tier-2 / NHIA / bank file) was actually filed, compute its statutory due date (period-end + 14 days), and surface overdue returns — so an auditor can answer "was PAYE filed on time?" from the system.

**Architecture:** A `RemittanceService` owns the deadline math (`period_end + REMITTANCE_DEADLINE_DAYS`, default 14), the `markSubmitted` write path, and the overdue `posture` (used by the dashboard, replacing today's `generated_at + 7d` approximation). A permission-gated endpoint marks a return filed; the payroll-run returns tab shows due-date + Pending/Submitted/Overdue status and a "Mark filed" action.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Context (verified in the codebase)

- `StatutoryReturn` already has columns `submitted_at`, `submitted_by`, `submission_reference`, `generated_at`, and a `run()` belongsTo (`PayrollRun`). No write path populates the submission columns today.
- `PayrollRun` has `period_end` (cast `date`), `period_year`, `period_month`, `periodLabel()`.
- `StatutoryRate::lookup(code, date)` resolves an effective-dated value; the `REMITTANCE_DEADLINE_DAYS` constant exists but **is not seeded** — this plan seeds it (14) and the service defaults to 14 if absent.
- Permissions live in `database/seeders/RolePermissionSeeder.php`; `statutory.export` exists in the Payroll group (granted to the finance/payroll approver role, and to `auditor` read-only). The new `statutory.remit` is granted to the same non-auditor role(s) that hold `statutory.export` — NOT to `auditor`.
- The payroll-run show page (`resources/js/Pages/Payroll/Runs/Show.vue`) has a "Statutory returns" tab listing returns with a download link; `PayrollRunController::show` loads `returns.trustee` and passes `StatutoryReturnResource::collection`.

## Global Constraints

- No new status column — derive status (submitted / overdue / pending), consistent with the existing "no status column, derive" approach.
- `declare(strict_types=1)` on new classes; money/day comparisons exact.
- Every new form input carries an `aria-label` (the `AccessibilityAuditorTest` gate).

---

### Task 1: RemittanceService + seed the deadline rate

**Files:**
- Create: `app/Services/Payroll/RemittanceService.php`
- Modify: `database/seeders/GhanaStatutoryReferenceSeeder.php`
- Test: `tests/Feature/Payroll/RemittanceServiceTest.php`

**Interfaces:**
- Produces:
  - `RemittanceService::deadlineDays(CarbonInterface $periodEnd): int` — `REMITTANCE_DEADLINE_DAYS` effective on the period end, default 14.
  - `dueDate(StatutoryReturn $return): ?CarbonImmutable` — `period_end + deadlineDays`; null if the run/period_end is missing.
  - `isOverdue(StatutoryReturn $return, ?CarbonInterface $now = null): bool` — not submitted AND now past the due date.
  - `status(StatutoryReturn $return, ?CarbonInterface $now = null): string` — `'submitted' | 'overdue' | 'pending'`.
  - `markSubmitted(StatutoryReturn $return, User $by, string $reference, ?CarbonInterface $submittedAt = null): StatutoryReturn` — guard: throws `DomainException` if already submitted; sets `submitted_at` (default now), `submitted_by`, `submission_reference`.
  - `posture(?CarbonInterface $now = null): array` — `['generated' => int, 'submitted' => int, 'overdue' => int]` over ALL returns, overdue by the proper period-end + deadline rule.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use App\Services\Payroll\RemittanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->svc = app(RemittanceService::class);
});

function makeReturn(string $periodEnd, ?string $submittedAt = null): StatutoryReturn
{
    $run = PayrollRun::create([
        'reference' => 'PR-' . uniqid(), 'period_year' => (int) substr($periodEnd, 0, 4),
        'period_month' => (int) substr($periodEnd, 5, 2),
        'period_start' => substr($periodEnd, 0, 8) . '01', 'period_end' => $periodEnd,
        'status' => 'approved', 'created_by' => User::factory()->create()->id,
    ]);

    return StatutoryReturn::create([
        'payroll_run_id' => $run->id, 'kind' => 'paye', 'file_path' => 'returns/x.csv',
        'total_amount' => 1000, 'record_count' => 3, 'generated_at' => now(),
        'submitted_at' => $submittedAt,
    ]);
}

it('computes the due date as period end + 14 days', function () {
    $r = makeReturn('2026-06-30');
    expect($this->svc->deadlineDays(CarbonImmutable::parse('2026-06-30')))->toBe(14)
        ->and($this->svc->dueDate($r)->toDateString())->toBe('2026-07-14');
});

it('flags an unsubmitted return as overdue only past the due date', function () {
    $r = makeReturn('2026-06-30');
    expect($this->svc->isOverdue($r, CarbonImmutable::parse('2026-07-10')))->toBeFalse() // before due
        ->and($this->svc->isOverdue($r, CarbonImmutable::parse('2026-07-20')))->toBeTrue() // after due
        ->and($this->svc->status($r, CarbonImmutable::parse('2026-07-20')))->toBe('overdue');
});

it('marks a return submitted and blocks double submission', function () {
    $r = makeReturn('2026-06-30');
    $by = User::factory()->create();

    $marked = $this->svc->markSubmitted($r, $by, 'GRA-REF-001');
    expect($marked->submitted_at)->not->toBeNull()
        ->and($marked->submitted_by)->toBe($by->id)
        ->and($marked->submission_reference)->toBe('GRA-REF-001')
        ->and($this->svc->status($marked))->toBe('submitted')
        ->and($this->svc->isOverdue($marked, CarbonImmutable::parse('2030-01-01')))->toBeFalse(); // submitted is never overdue

    expect(fn () => $this->svc->markSubmitted($marked->fresh(), $by, 'X'))->toThrow(DomainException::class);
});

it('reports posture counts using the proper deadline', function () {
    makeReturn('2026-06-30');                       // unsubmitted, due 2026-07-14
    makeReturn('2026-06-30', now()->toDateString()); // submitted

    $posture = $this->svc->posture(CarbonImmutable::parse('2026-08-01')); // well past due
    expect($posture['submitted'])->toBe(1)
        ->and($posture['overdue'])->toBe(1)
        ->and($posture['generated'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/RemittanceServiceTest.php`
Expected: FAIL — service + seeded rate missing.

- [ ] **Step 3: Seed the deadline rate**

In `database/seeders/GhanaStatutoryReferenceSeeder.php`, add a `StatutoryRate` row alongside the other rates (match the file's existing insert style — `code`, `label`, `rate`, `is_rate`, `currency`, `effective_from`, `effective_to`, `meta`):

```php
// Remittance deadline: SSNIT/GRA returns are due within 14 days of month-end.
\App\Models\StatutoryRate::updateOrCreate(
    ['code' => \App\Models\StatutoryRate::REMITTANCE_DEADLINE_DAYS, 'effective_from' => '2020-01-01'],
    ['label' => 'Statutory remittance deadline (days after period end)', 'rate' => 14, 'is_rate' => false,
     'currency' => null, 'effective_to' => null, 'meta' => null],
);
```

(If the seeder builds rows from an array, add an equivalent entry to that array instead — match the existing pattern. The value `14` lives in the `rate` column with `is_rate = false`.)

- [ ] **Step 4: Write the service**

`app/Services/Payroll/RemittanceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRate;
use App\Models\StatutoryReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Statutory remittance posture: when a return is due (period end + the
 * effective remittance-deadline days, default 14), whether it has been filed,
 * and the write path that records a filing. There is no status column — state
 * is derived from submitted_at and the computed due date.
 */
class RemittanceService
{
    private const DEFAULT_DEADLINE_DAYS = 14;

    public function deadlineDays(CarbonInterface $periodEnd): int
    {
        try {
            return (int) StatutoryRate::lookup(StatutoryRate::REMITTANCE_DEADLINE_DAYS, $periodEnd);
        } catch (\Throwable $e) {
            return self::DEFAULT_DEADLINE_DAYS;
        }
    }

    public function dueDate(StatutoryReturn $return): ?CarbonImmutable
    {
        $return->loadMissing('run');
        $periodEnd = $return->run?->period_end;
        if ($periodEnd === null) {
            return null;
        }

        $end = CarbonImmutable::parse($periodEnd instanceof \DateTimeInterface ? $periodEnd->format('Y-m-d') : (string) $periodEnd);

        return $end->addDays($this->deadlineDays($end));
    }

    public function isOverdue(StatutoryReturn $return, ?CarbonInterface $now = null): bool
    {
        if ($return->submitted_at !== null) {
            return false;
        }
        $due = $this->dueDate($return);
        if ($due === null) {
            return false;
        }

        return ($now ?? CarbonImmutable::now())->greaterThan($due);
    }

    public function status(StatutoryReturn $return, ?CarbonInterface $now = null): string
    {
        if ($return->submitted_at !== null) {
            return 'submitted';
        }

        return $this->isOverdue($return, $now) ? 'overdue' : 'pending';
    }

    public function markSubmitted(StatutoryReturn $return, User $by, string $reference, ?CarbonInterface $submittedAt = null): StatutoryReturn
    {
        if ($return->submitted_at !== null) {
            throw new DomainException('This return has already been recorded as filed.');
        }

        $return->update([
            'submitted_at'         => $submittedAt ?? CarbonImmutable::now(),
            'submitted_by'         => $by->id,
            'submission_reference' => $reference,
        ]);

        return $return->fresh();
    }

    /** @return array{generated:int, submitted:int, overdue:int} */
    public function posture(?CarbonInterface $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $total     = StatutoryReturn::count();
        $submitted = StatutoryReturn::query()->whereNotNull('submitted_at')->count();

        // Overdue: unsubmitted, and now is past period_end + default deadline days.
        // (A single current deadline value is sufficient for this aggregate nag.)
        $cutoff = $now->subDays(self::DEFAULT_DEADLINE_DAYS)->toDateString();
        $overdue = StatutoryReturn::query()
            ->whereNull('statutory_returns.submitted_at')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'statutory_returns.payroll_run_id')
            ->whereNotNull('payroll_runs.period_end')
            ->where('payroll_runs.period_end', '<', $cutoff)
            ->count();

        return [
            'generated' => $total - $submitted,
            'submitted' => $submitted,
            'overdue'   => $overdue,
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Payroll/RemittanceServiceTest.php`
Expected: PASS (all four). If `PayrollRun::create` in the test needs more required columns, add them minimally to the `makeReturn` helper (report any adjustment).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payroll/RemittanceService.php database/seeders/GhanaStatutoryReferenceSeeder.php tests/Feature/Payroll/RemittanceServiceTest.php
git commit -m "feat(payroll): RemittanceService — deadline (period-end + 14), markSubmitted, overdue posture"
```

---

### Task 2: Permission + endpoint + resource fields

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Modify: `app/Http/Controllers/PayrollRunController.php`
- Modify: `app/Http/Resources/StatutoryReturnResource.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Payroll/MarkReturnFiledTest.php`

**Interfaces:**
- Consumes: `RemittanceService` (Task 1).
- Produces: permission `statutory.remit`; `POST payroll-runs/{run}/returns/{returnId}/mark-filed` → `payroll-runs.return-mark-filed`; `StatutoryReturnResource` gains `due_date`, `status`, `submitted_at`, `submission_reference`, `submitted_by_name`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->seed(GhanaStatutoryReferenceSeeder::class);
});

function seedReturn(): StatutoryReturn
{
    $run = PayrollRun::create([
        'reference' => 'PR-' . uniqid(), 'period_year' => 2026, 'period_month' => 6,
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'status' => 'approved', 'created_by' => User::factory()->create()->id,
    ]);

    return StatutoryReturn::create([
        'payroll_run_id' => $run->id, 'kind' => 'paye', 'file_path' => 'returns/x.csv',
        'total_amount' => 1000, 'record_count' => 3, 'generated_at' => now(),
    ]);
}

it('records a return as filed for a user with statutory.remit', function () {
    $r = seedReturn();
    $u = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['statutory.remit']]);

    $this->actingAs($u)
        ->post("/payroll-runs/{$r->payroll_run_id}/returns/{$r->id}/mark-filed", ['reference' => 'GRA-2026-06'])
        ->assertRedirect();

    expect($r->fresh()->submitted_at)->not->toBeNull()
        ->and($r->fresh()->submission_reference)->toBe('GRA-2026-06');
});

it('requires a reference', function () {
    $r = seedReturn();
    $u = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['statutory.remit']]);

    $this->actingAs($u)
        ->post("/payroll-runs/{$r->payroll_run_id}/returns/{$r->id}/mark-filed", ['reference' => ''])
        ->assertSessionHasErrors('reference');
});

it('forbids a user without statutory.remit', function () {
    $r = seedReturn();
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->post("/payroll-runs/{$r->payroll_run_id}/returns/{$r->id}/mark-filed", ['reference' => 'X'])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/MarkReturnFiledTest.php`
Expected: FAIL — permission/route/action missing.

- [ ] **Step 3: Add the permission**

In `database/seeders/RolePermissionSeeder.php`, inside `PERMISSIONS`, after `'statutory.export'`:

```php
        'statutory.remit'        => ['Payroll',      'Record a statutory return as filed/remitted'],
```

Grant `'statutory.remit'` in the SAME role array(s) that already list `'statutory.export'` **except `auditor`** (auditor stays read-only). (Find the `'statutory.export',` lines; add `'statutory.remit',` next to each that is NOT the auditor role.)

- [ ] **Step 4: Add the resource fields**

In `app/Http/Resources/StatutoryReturnResource.php`, add (resolve the service once):

```php
        $remit = app(\App\Services\Payroll\RemittanceService::class);

        // ... inside the returned array, add:
        'submitted_at'        => optional($this->submitted_at)->toIso8601String(),
        'submission_reference'=> $this->submission_reference,
        'submitted_by_name'   => $this->submitter?->name,
        'due_date'            => optional($remit->dueDate($this->resource))->toDateString(),
        'status'              => $remit->status($this->resource),
```

(Keep the existing fields. `submitter` is the `submitted_by` belongsTo on `StatutoryReturn`.)

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/PayrollRunController.php`, add (inject nothing new — resolve the service in-method or via the container):

```php
    public function markReturnFiled(Request $request, PayrollRun $run, int $returnId, \App\Services\Payroll\RemittanceService $remittance): RedirectResponse
    {
        $this->authorize('view', $run); // visible to run viewers; the route permission gates the write
        if (! $request->user()->hasPermission('statutory.remit')) {
            abort(403);
        }

        $data = $request->validate([
            'reference'    => ['required', 'string', 'max:120'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $return = $run->returns()->findOrFail($returnId);

        try {
            $remittance->markSubmitted(
                $return,
                $request->user(),
                $data['reference'],
                isset($data['submitted_at']) ? \Carbon\CarbonImmutable::parse($data['submitted_at']) : null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Statutory return recorded as filed.');
    }
```

In `show()`, after `$run->load([... 'returns.trustee'])`, attach the run to each return (so the resource can compute the due date without an N+1) and expose a `canRemit` prop:

```php
        $run->returns->each(fn ($r) => $r->setRelation('run', $run));
```

Add to the `Inertia::render('Payroll/Runs/Show', [...])` props:

```php
            'canRemit'     => $request->user()->hasPermission('statutory.remit'),
```

(Add `Request $request` to the `show` signature if it isn't already there; pass it through.)

- [ ] **Step 6: Add the route**

In `routes/web.php`, next to the existing `payroll-runs.return-download` route:

```php
        Route::post('{run}/returns/{returnId}/mark-filed', [PayrollRunController::class, 'markReturnFiled'])
            ->middleware('permission:statutory.remit')->name('return-mark-filed');
```

- [ ] **Step 7: Run test + commit**

Run: `php artisan test tests/Feature/Payroll/MarkReturnFiledTest.php`
Expected: PASS (all three).

```bash
git add database/seeders/RolePermissionSeeder.php app/Http/Controllers/PayrollRunController.php app/Http/Resources/StatutoryReturnResource.php routes/web.php tests/Feature/Payroll/MarkReturnFiledTest.php
git commit -m "feat(payroll): mark-return-filed endpoint + statutory.remit permission + due-date/status on resource"
```

---

### Task 3: Dashboard fix + UI + gate

**Files:**
- Modify: `app/Services/DashboardService.php`
- Modify: `resources/js/Pages/Payroll/Runs/Show.vue`
- Test: none new (verification only).

- [ ] **Step 1: Point the dashboard at the proper rule**

In `app/Services/DashboardService.php`, change `statutoryDuePosture` to delegate to the service (inject or resolve `RemittanceService`):

```php
    private function statutoryDuePosture(Carbon $now): array
    {
        return app(\App\Services\Payroll\RemittanceService::class)->posture(
            \Carbon\CarbonImmutable::parse($now->toDateTimeString())
        );
    }
```

(Keep the method's return shape `['generated','submitted','overdue']` — `posture()` already matches it.)

- [ ] **Step 2: Surface status + Mark-filed in the returns tab**

In `resources/js/Pages/Payroll/Runs/Show.vue`, in the `returns` tab rows, for each return `rt` show its `rt.status` as a badge (Pending = amber, Overdue = red, Submitted = emerald) and `rt.due_date` ("due {{ rt.due_date }}"). For unsubmitted returns when `canRemit` is true, add a "Mark filed" control that captures a reference and posts to the new route. Mirror the file's existing inline-form / button conventions:

```vue
<!-- add canRemit to defineProps; -->
<!-- per-row, alongside the existing download link: -->
<span :class="{
        'text-amber-600': rt.status === 'pending',
        'text-rose-600': rt.status === 'overdue',
        'text-emerald-600': rt.status === 'submitted',
    }" class="text-[12px] font-bold capitalize">{{ rt.status }}</span>
<span v-if="rt.due_date && rt.status !== 'submitted'" class="text-[11px] text-slate-500">due {{ rt.due_date }}</span>
<span v-if="rt.status === 'submitted'" class="text-[11px] text-slate-500">filed {{ rt.submission_reference }}</span>

<button v-if="canRemit && rt.status !== 'submitted'" @click="openMarkFiled(rt)"
        class="text-[12px] font-bold text-blue-600 hover:underline">Mark filed</button>
```

Add the script logic (use `useForm` + a small inline prompt or a simple `window.prompt` is NOT acceptable — use an inline reference input row consistent with the page's style):

```js
import { useForm } from '@inertiajs/vue3';
const fileForm = useForm({ reference: '', submitted_at: '' });
const filingId = ref(null);
const openMarkFiled = (rt) => { filingId.value = rt.id; fileForm.reset(); };
const submitFiled = (rt) => fileForm.post(
    route('payroll-runs.return-mark-filed', { run: R.id, returnId: rt.id }),
    { preserveScroll: true, onSuccess: () => { filingId.value = null; } },
);
```

Render an inline reference input (with `aria-label="Filing reference"`) + Save/Cancel when `filingId === rt.id`. Keep it visually consistent with the existing returns rows.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

- [ ] **Step 4: Regression gate**

Run: `php artisan test tests/Feature/Payroll tests/Feature/Finance tests/Unit/Finance`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — accessibility green (the reference input carries `aria-label`); allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: completes (the new statutory rate seeds cleanly).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DashboardService.php resources/js/Pages/Payroll/Runs/Show.vue
git commit -m "feat(payroll): surface remittance due-date/status + mark-filed in run UI; dashboard uses proper deadline"
git commit --allow-empty -m "test(payroll): statutory remittance tracking regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Correct deadline rule**: due = `period_end + REMITTANCE_DEADLINE_DAYS` (14), measured from month-end (the statutory basis), NOT from `generated_at` — that was the dashboard's prior approximation, now replaced.
- **Derived state**: no status column; `status()` returns submitted/overdue/pending from `submitted_at` + the computed due date. A submitted return is never overdue.
- **Write guard**: `markSubmitted` throws if already filed (idempotent against double-recording); the endpoint surfaces it as a redirect-back error.
- **Permission**: `statutory.remit` is the write gate (NOT `auditor`); the run-view authorization still lets viewers see status.
- **Accessibility**: the filing-reference input carries `aria-label`.
- **Out of scope** (future): direct GRA/SSNIT e-filing API, Tier-2 trustee acknowledgement tracking, a bundled one-download statutory pack, per-return reminder notifications.
