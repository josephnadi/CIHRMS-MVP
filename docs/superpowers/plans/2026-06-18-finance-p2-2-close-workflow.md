# Finance Phase 2 — P2-2: Period Close / Reopen / Lock Workflow + UI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give finance admins a permission-gated month-end workflow to **close**, **reopen**, and **lock** fiscal periods — transitions that the P2-1 posting guard already enforces — recorded in the existing tamper-evident audit chain, with a Fiscal Calendar admin UI.

**Architecture:** A `PeriodCloseService` owns the three state transitions (Open→Closed, Closed→Open, Closed→Locked) with validation and actor attribution (`closed_by`/`locked_by`). A `PeriodController` exposes index + close/reopen/lock endpoints (gated by new `finance.period.*` permissions; mutating actions behind `2fa:fresh`). The existing `AuditTrail` middleware auto-records each POST into the audit chain — no new audit code. A Vue page lists a year's periods with status + actions.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest. Builds on P2-1 (`FiscalPeriod`, `FiscalPeriodStatus`, `FiscalCalendarService`).

**This is P2-2 of Phase 2.** P2-3 (subledger↔GL reconciliation) follows and will wire a pre-close variance check + override into the close flow.

**Spec:** `docs/superpowers/specs/2026-06-18-finance-fiscal-periods-design.md`

## Permission policy (decided)

- `finance.period.view` — see the fiscal calendar → granted to `finance_officer`.
- `finance.period.close` — Open→Closed → granted to `finance_officer`.
- `finance.period.reopen` — Closed→Open → granted to `finance_officer`.
- `finance.period.lock` — Closed→Locked (permanent) → **super_admin/ceo only** (NOT granted to finance_officer; locking is a privileged, irreversible act). super_admin/ceo inherit all via the wildcard.

---

### Task 1: finance.period.* permissions

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Test: `tests/Feature/Finance/PeriodPermissionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants view/close/reopen to finance_officer and reserves lock for super_admin', function () {
    $fo  = User::factory()->create(['role' => 'finance_officer']);
    $sa  = User::factory()->create(['role' => 'super_admin']);
    $emp = User::factory()->create(['role' => 'employee']);

    expect($fo->hasPermission('finance.period.view'))->toBeTrue()
        ->and($fo->hasPermission('finance.period.close'))->toBeTrue()
        ->and($fo->hasPermission('finance.period.reopen'))->toBeTrue()
        ->and($fo->hasPermission('finance.period.lock'))->toBeFalse()
        ->and($sa->hasPermission('finance.period.lock'))->toBeTrue()
        ->and($emp->hasPermission('finance.period.view'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PeriodPermissionsTest.php`
Expected: FAIL — permissions absent.

- [ ] **Step 3: Declare the permissions**

In `database/seeders/RolePermissionSeeder.php`, inside `private const PERMISSIONS`, add (e.g. after the `finance.posting_rules.manage` entry):

```php
        'finance.period.view'   => ['Finance', 'View the fiscal calendar and period statuses'],
        'finance.period.close'  => ['Finance', 'Close a fiscal period (month-end)'],
        'finance.period.reopen' => ['Finance', 'Reopen a closed fiscal period'],
        'finance.period.lock'   => ['Finance', 'Permanently lock a fiscal period (post-audit)'],
```

- [ ] **Step 4: Grant to finance_officer (view/close/reopen — NOT lock)**

In the `'finance_officer' => [` role array, add (e.g. after `'finance.posting_rules.manage',`):

```php
            'finance.period.view', 'finance.period.close', 'finance.period.reopen',
```

(Do NOT grant `finance.period.lock` to finance_officer — super_admin/ceo get it via the wildcard.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PeriodPermissionsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php tests/Feature/Finance/PeriodPermissionsTest.php
git commit -m "feat(finance): finance.period.view/close/reopen/lock permissions"
```

---

### Task 2: PeriodCloseService (state machine)

**Files:**
- Create: `app/Services/Finance/PeriodCloseService.php`
- Test: `tests/Feature/Finance/PeriodCloseServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;

beforeEach(function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $this->period = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
    $this->user = User::factory()->create();
});

it('closes an open period with actor attribution', function () {
    $svc = app(PeriodCloseService::class);
    $svc->close($this->period, $this->user);

    $fresh = $this->period->fresh();
    expect($fresh->status)->toBe(FiscalPeriodStatus::Closed)
        ->and($fresh->closed_by)->toBe($this->user->id)
        ->and($fresh->closed_at)->not->toBeNull();
});

it('reopens a closed period and clears close attribution', function () {
    $svc = app(PeriodCloseService::class);
    $svc->close($this->period, $this->user);
    $svc->reopen($this->period->fresh(), $this->user);

    $fresh = $this->period->fresh();
    expect($fresh->status)->toBe(FiscalPeriodStatus::Open)
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->closed_by)->toBeNull();
});

it('locks a closed period with actor attribution', function () {
    $svc = app(PeriodCloseService::class);
    $svc->close($this->period, $this->user);
    $svc->lock($this->period->fresh(), $this->user);

    $fresh = $this->period->fresh();
    expect($fresh->status)->toBe(FiscalPeriodStatus::Locked)
        ->and($fresh->locked_by)->toBe($this->user->id)
        ->and($fresh->locked_at)->not->toBeNull();
});

it('rejects invalid transitions', function () {
    $svc = app(PeriodCloseService::class);

    // cannot reopen an open period
    expect(fn () => $svc->reopen($this->period, $this->user))->toThrow(DomainException::class);
    // cannot lock an open period (must be closed first)
    expect(fn () => $svc->lock($this->period, $this->user))->toThrow(DomainException::class);

    // close, then lock, then verify a locked period cannot be reopened
    $svc->close($this->period, $this->user);
    $svc->lock($this->period->fresh(), $this->user);
    expect(fn () => $svc->reopen($this->period->fresh(), $this->user))->toThrow(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PeriodCloseServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\User;
use DomainException;

/**
 * Period lifecycle transitions. The posting guard (JournalPostingService)
 * enforces the consequences (no posting into Closed/Locked); this service
 * owns the legal state transitions + actor attribution. HTTP-level audit is
 * provided by the AuditTrail middleware on the POST endpoints.
 */
class PeriodCloseService
{
    public function close(FiscalPeriod $period, User $by): FiscalPeriod
    {
        if ($period->status !== FiscalPeriodStatus::Open) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only an open period can be closed.");
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => $by->id,
        ]);

        return $period->fresh();
    }

    public function reopen(FiscalPeriod $period, User $by): FiscalPeriod
    {
        if ($period->status === FiscalPeriodStatus::Locked) {
            throw new DomainException("Period {$period->name} is locked and cannot be reopened.");
        }
        if ($period->status !== FiscalPeriodStatus::Closed) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only a closed period can be reopened.");
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Open->value,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $period->fresh();
    }

    public function lock(FiscalPeriod $period, User $by): FiscalPeriod
    {
        if ($period->status !== FiscalPeriodStatus::Closed) {
            throw new DomainException("Period {$period->name} is {$period->status->value}; only a closed period can be locked.");
        }

        $period->update([
            'status'    => FiscalPeriodStatus::Locked->value,
            'locked_at' => now(),
            'locked_by' => $by->id,
        ]);

        return $period->fresh();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PeriodCloseServiceTest.php`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/PeriodCloseService.php tests/Feature/Finance/PeriodCloseServiceTest.php
git commit -m "feat(finance): PeriodCloseService (close/reopen/lock state machine)"
```

---

### Task 3: FiscalPeriodResource + PeriodController + routes

**Files:**
- Create: `app/Http/Resources/Finance/FiscalPeriodResource.php`
- Create: `app/Http/Controllers/Finance/PeriodController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/PeriodEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $this->period = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
});

it('finance_officer can view the fiscal calendar page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/periods')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/FiscalCalendar/Index'));
});

it('employee is forbidden from the fiscal calendar', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/periods')->assertForbidden();
});

it('finance_officer can close and reopen a period', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($u)->post("/finance/periods/{$this->period->id}/close")->assertRedirect();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);

    $this->actingAs($u)->post("/finance/periods/{$this->period->id}/reopen")->assertRedirect();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Open);
});

it('finance_officer cannot lock (privileged), super_admin can', function () {
    $fo = User::factory()->create(['role' => 'finance_officer']);
    $sa = User::factory()->create(['role' => 'super_admin']);

    // close first (lock requires closed)
    $this->actingAs($fo)->post("/finance/periods/{$this->period->id}/close")->assertRedirect();

    $this->actingAs($fo)->post("/finance/periods/{$this->period->id}/lock")->assertForbidden();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);

    $this->actingAs($sa)->post("/finance/periods/{$this->period->id}/lock")->assertRedirect();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Locked);
});

it('rejects reopening a locked period with a validation error', function () {
    $sa = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($sa)->post("/finance/periods/{$this->period->id}/close")->assertRedirect();
    $this->actingAs($sa)->post("/finance/periods/{$this->period->id}/lock")->assertRedirect();

    $this->actingAs($sa)->post("/finance/periods/{$this->period->id}/reopen")->assertSessionHasErrors();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Locked);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PeriodEndpointTest.php`
Expected: FAIL — routes/controller missing.

- [ ] **Step 3: Write the Resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\FiscalPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalPeriod */
class FiscalPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'period_no'  => $this->period_no,
            'name'       => $this->name,
            'starts_on'  => $this->starts_on?->toDateString(),
            'ends_on'    => $this->ends_on?->toDateString(),
            'status'     => ['value' => $this->status->value, 'label' => $this->status->label()],
            'closed_at'  => $this->closed_at?->toIso8601String(),
            'locked_at'  => $this->locked_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Write the Controller**

The mutating actions resolve the actor from `$request->user()`, call the service, and translate the service's `DomainException` (invalid transition, e.g. reopening a locked period) into a redirect-back validation error. The `lock` action additionally authorizes the privileged permission.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function __construct(private readonly PeriodCloseService $service)
    {
    }

    public function index(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));

        // Ensure the requested year exists so the calendar is never empty.
        app(FiscalCalendarService::class)->ensureYear($year);

        $fiscalYear = FiscalYear::where('year', $year)->with('periods')->firstOrFail();

        return Inertia::render('Finance/FiscalCalendar/Index', [
            'activeModule' => 'finance-periods',
            'year'         => $year,
            'years'        => FiscalYear::orderBy('year')->pluck('year'),
            'periods'      => FiscalPeriodResource::collection($fiscalYear->periods),
        ]);
    }

    public function close(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(fn () => $this->service->close($fiscalPeriod, $request->user()), "Period {$fiscalPeriod->name} closed.");
    }

    public function reopen(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(fn () => $this->service->reopen($fiscalPeriod, $request->user()), "Period {$fiscalPeriod->name} reopened.");
    }

    public function lock(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        return $this->transition(fn () => $this->service->lock($fiscalPeriod, $request->user()), "Period {$fiscalPeriod->name} locked.");
    }

    private function transition(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, inside the `Route::prefix('finance')->name('finance.')->group(...)` block, add (e.g. after the posting-rules group):

```php
        // Fiscal periods (Phase 2)
        Route::middleware('permission:finance.period.view')->group(function () {
            Route::get('periods', [\App\Http\Controllers\Finance\PeriodController::class, 'index'])->name('periods.index');
        });
        Route::middleware(['permission:finance.period.close', '2fa:fresh'])->group(function () {
            Route::post('periods/{fiscalPeriod}/close', [\App\Http\Controllers\Finance\PeriodController::class, 'close'])->name('periods.close');
        });
        Route::middleware(['permission:finance.period.reopen', '2fa:fresh'])->group(function () {
            Route::post('periods/{fiscalPeriod}/reopen', [\App\Http\Controllers\Finance\PeriodController::class, 'reopen'])->name('periods.reopen');
        });
        Route::middleware(['permission:finance.period.lock', '2fa:fresh'])->group(function () {
            Route::post('periods/{fiscalPeriod}/lock', [\App\Http\Controllers\Finance\PeriodController::class, 'lock'])->name('periods.lock');
        });
```

NOTE on `2fa:fresh`: the endpoint test above does not establish a fresh-2FA session, so confirm how sibling finance tests satisfy `2fa:fresh` (e.g. `ApPayment2faTest` uses a helper like `apPay2faFresh($u)`). If `2fa:fresh` blocks the test's POST, either (a) reuse the project's existing test helper that marks a fresh 2FA challenge for the acting user, or (b) if no such helper is readily reusable, drop `2fa:fresh` from these routes for now and note it as a follow-up — do NOT weaken the permission gate. Prefer (a). Read `tests/Feature/Finance/ApPayment2faTest.php` to find the helper and apply it in the close/reopen/lock test cases.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PeriodEndpointTest.php`
Expected: PASS (all five).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/Finance/FiscalPeriodResource.php app/Http/Controllers/Finance/PeriodController.php routes/web.php tests/Feature/Finance/PeriodEndpointTest.php
git commit -m "feat(finance): fiscal-period close/reopen/lock endpoints"
```

---

### Task 4: Fiscal Calendar admin UI (Vue page)

**Files:**
- Create: `resources/js/Pages/Finance/FiscalCalendar/Index.vue`
- Test: covered by the Inertia component assertion in Task 3.

- [ ] **Step 1: Study the design tokens** — read `resources/js/Pages/Finance/PostingRules/Index.vue` (created in Plan 1) for the project's Material-style tokens (`text-primary`, `text-on-surface-variant`, `bg-surface-container-lowest`, `border-outline-variant`, `material-symbols-outlined`), the `AuthenticatedLayout` via `defineOptions`, the `canManage` permission-check helper, and `router.post`/`useForm` usage.

- [ ] **Step 2: Write the page**

```vue
<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:    { type: Number, required: true },
    years:   { type: Array,  default: () => [] },
    periods: { type: Object, required: true },
});

const page = usePage();
const can = (perm) => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes(perm);
};

const rows = computed(() => props.periods.data ?? props.periods ?? []);

const gotoYear = (y) => router.get(route('finance.periods.index'), { year: y }, { preserveState: false });

const act = (period, action) => {
    if (! confirm(`${action} ${period.name}?`)) return;
    router.post(route(`finance.periods.${action}`, period.id), {}, { preserveScroll: true });
};

const statusChip = (status) => ({
    open:   'bg-emerald-500/15 text-emerald-300',
    closed: 'bg-amber-500/15 text-amber-300',
    locked: 'bg-slate-500/20 text-slate-300',
}[status.value] ?? 'bg-slate-500/20 text-slate-300');
</script>

<template>
    <Head title="Fiscal Calendar" />

    <div class="p-6 max-w-5xl mx-auto">
        <header class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-black text-primary">Fiscal Calendar</h1>
                <p class="text-on-surface-variant mt-1 text-sm">
                    Close, reopen, and lock fiscal periods. Closed and locked periods reject new postings.
                </p>
            </div>
            <select :value="year" @change="gotoYear($event.target.value)"
                    class="rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary">
                <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
        </header>

        <EmptyState v-if="rows.length === 0" title="No periods" description="This year has no fiscal periods." />

        <div v-else class="rounded-2xl border border-outline-variant/60 divide-y divide-outline-variant/40 bg-surface-container-lowest">
            <div v-for="period in rows" :key="period.id" class="p-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-primary">{{ period.name }}</p>
                    <p class="text-xs text-on-surface-variant">{{ period.starts_on }} → {{ period.ends_on }}</p>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-xs px-2 py-0.5 rounded-full font-bold" :class="statusChip(period.status)">
                        {{ period.status.label }}
                    </span>

                    <button v-if="period.status.value === 'open' && can('finance.period.close')"
                            class="text-amber-300 text-sm hover:underline" @click="act(period, 'close')">Close</button>
                    <button v-if="period.status.value === 'closed' && can('finance.period.reopen')"
                            class="text-emerald-300 text-sm hover:underline" @click="act(period, 'reopen')">Reopen</button>
                    <button v-if="period.status.value === 'closed' && can('finance.period.lock')"
                            class="text-slate-300 text-sm hover:underline" @click="act(period, 'lock')">Lock</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Build to confirm it compiles**

Run: `npm run build`
Expected: build succeeds with no Vue compile errors.

- [ ] **Step 4: Re-run the endpoint test (covers the component render)**

Run: `php artisan test tests/Feature/Finance/PeriodEndpointTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Finance/FiscalCalendar/Index.vue
git commit -m "feat(finance): Fiscal Calendar admin UI page"
```

---

### Task 5: Link Fiscal Calendar from the Finance Hub

**Files:**
- Modify: `resources/js/Pages/Finance/Hub.vue`

- [ ] **Step 1: Add the header link**

In `resources/js/Pages/Finance/Hub.vue`, inside the `<div class="flex gap-2">` header block (alongside Chart of Accounts / Bank Accounts / Posting Rules), add after the Posting Rules `<Link>`:

```vue
                <Link :href="route('finance.periods.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">calendar_month</span>
                    Fiscal Calendar
                </Link>
```

(`Link` is already imported in `Hub.vue`.)

- [ ] **Step 2: Build to confirm it compiles**

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Finance/Hub.vue
git commit -m "feat(finance): link Fiscal Calendar from the Finance Hub"
```

---

### Task 6: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Payroll tests/Feature/Loans tests/Feature/Disbursement`
Expected: PASS.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS (allowing the known time-of-day `KioskRecentTest` flake if it is the only failure).

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P2-2 period-close workflow regression gate green"
```

---

## Self-Review notes (for the implementer)

- **No new audit code.** The `AuditTrail` web-group middleware auto-records each close/reopen/lock POST (action = route name, payload = request) into the tamper-evident chain. Domain attribution (`closed_by`/`locked_by`) is on the period itself. That satisfies the spec's audit requirement.
- **`2fa:fresh` on the mutating routes** matches the money-mutating posture (payments, journal posting). The endpoint test must establish a fresh-2FA session using the project's existing helper (see `ApPayment2faTest`); if that helper can't be reused cleanly, drop `2fa:fresh` and note it as a follow-up rather than weakening the permission gate.
- **Lock is super_admin-only** (segregation of duty); finance_officer can close/reopen. The endpoint test asserts finance_officer gets 403 on lock while super_admin succeeds.
- **Invalid transitions surface as redirect-back validation errors** (`withErrors(['period' => ...])`), not 500s — the service throws `DomainException`, the controller catches it.
- **P2-3 will wire the pre-close subledger recon + variance override** into the close flow; P2-2's `close()` is intentionally recon-free.
