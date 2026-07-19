# Workforce Analytics Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a read-only HR people-analytics dashboard at `/analytics/workforce` — KPI cards + charts for workforce composition and joiner/leaver flow, with department and date-range filters.

**Architecture:** Aggregation-only. A `WorkforceAnalyticsService` computes every metric via grouped SQL (no N+1, no new tables, no writes). A thin `WorkforceAnalyticsController` validates a `WorkforceAnalyticsRequest`, resolves defaults, and renders an Inertia page. The Vue page reuses the existing `StatCard` and `Components/charts/*` (Chart.js) components — the same stack `Finance/Analytics/Dashboard.vue` uses. Senior-permission gated.

**Tech Stack:** Laravel 13, PHP 8.3, Inertia v2, Vue 3, Tailwind v3, Chart.js (via existing wrapper components), Pest 4. Postgres in prod/dev, SQLite in-memory in tests.

## Global Constraints

- No new database tables; aggregation over existing `employees`, `offboarding_cases`, `departments` only. Read-only — no writes.
- Cross-DB date grouping MUST use `App\Support\DbExpr` helpers (tests run on SQLite, prod on Postgres) — never raw `strftime`/`date_trunc`.
- New permission slug: `workforce.analytics.view`. Add the case to `App\Enums\Permission` AND the slug to `App\Models\User::ROLE_PERMISSIONS` (both are required per the enum's own doc comment).
- Money/number display uses `Intl.NumberFormat('en-GH', …)` in Vue, matching `Finance/Analytics/Dashboard.vue`.
- Every metric degrades gracefully to a zero-state; null `gender` → "Unspecified"; null `date_of_birth` → omitted from age bands. Never divide by zero.
- Git: branch off `main`, conventional commits ending with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`, `--no-ff` merge, push, delete branch. Never commit directly to `main`.

---

## File Structure

- **Create** `app/Enums/Permission.php` — *modify*: add `WorkforceAnalyticsView` case.
- **Modify** `app/Models/User.php` — add slug to `hr_admin` in `ROLE_PERMISSIONS`.
- **Create** `app/Services/WorkforceAnalyticsService.php` — all aggregation logic.
- **Create** `app/Http/Requests/WorkforceAnalyticsRequest.php` — filter validation.
- **Create** `app/Http/Controllers/WorkforceAnalyticsController.php` — validate → service → render.
- **Modify** `routes/web.php` — add the permission-gated route.
- **Create** `resources/js/Pages/Analytics/Workforce.vue` — the dashboard UI.
- **Modify** `resources/js/Layouts/AuthenticatedLayout.vue` — nav entry gated by `can('workforce.analytics.view')`.
- **Create** `tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php` — exact-number metric assertions.
- **Create** `tests/Feature/Analytics/WorkforceAnalyticsPageTest.php` — render / 403 / filter assertions.

---

## Task 1: Permission + RBAC wiring

**Files:**
- Modify: `app/Enums/Permission.php`
- Modify: `app/Models/User.php` (the `hr_admin` array inside `ROLE_PERMISSIONS`, around line 58)
- Test: `tests/Feature/Analytics/WorkforceAnalyticsPageTest.php` (created here, extended in Task 4)

**Interfaces:**
- Produces: permission slug string `'workforce.analytics.view'`; enum case `Permission::WorkforceAnalyticsView`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php` is Task 2; here create the page test file with just the RBAC grant check:

```php
<?php

declare(strict_types=1);

use App\Models\User;

it('grants workforce.analytics.view to hr_admin', function () {
    $user = User::factory()->create(['role' => 'hr_admin']);

    expect($user->hasPermission('workforce.analytics.view'))->toBeTrue();
});

it('denies workforce.analytics.view to a plain employee', function () {
    $user = User::factory()->create(['role' => 'employee']);

    expect($user->hasPermission('workforce.analytics.view'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsPageTest.php`
Expected: FAIL — the first test fails because `hr_admin` does not yet include the slug.

> Note: if `User::factory()->create(['role' => 'employee'])` needs a valid role, confirm `'employee'` is an accepted legacy role value in this codebase; if not, use whatever the factory default is for a non-privileged user (the second test only needs a user lacking the slug).

- [ ] **Step 3: Add the enum case**

In `app/Enums/Permission.php`, add near the other analytics/dashboard cases:

```php
    case WorkforceAnalyticsView = 'workforce.analytics.view';
```

- [ ] **Step 4: Add the slug to hr_admin**

In `app/Models/User.php`, inside the `'hr_admin' => [ ... ]` array (near the other `*.view` entries), add:

```php
            'workforce.analytics.view',
```

(`super_admin` and `ceo` already hold `'*'`, so they inherit it automatically.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsPageTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git checkout -b feat/workforce-analytics
git add app/Enums/Permission.php app/Models/User.php tests/Feature/Analytics/WorkforceAnalyticsPageTest.php
git commit -m "feat(analytics): add workforce.analytics.view permission"
```

---

## Task 2: WorkforceAnalyticsService — KPIs

**Files:**
- Create: `app/Services/WorkforceAnalyticsService.php`
- Test: `tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\Employee` (scopes: `active()`; casts: `hire_date` date, `date_of_birth` date, `status` `EmployeeStatus`; columns `department_id`, `gender`, `salary`, `manager_id`), `App\Models\OffboardingCase` (`employee_id`, `effective_termination_date` date), `App\Models\Department`, `App\Enums\EmployeeStatus`.
- Produces: `WorkforceAnalyticsService::metrics(?int $departmentId, CarbonImmutable $from, CarbonImmutable $to): array` returning keys `kpis`, `series`, `meta`. This task implements `kpis` (and the private `scopedActive`/`scopedAll` helpers); Task 3 fills `series`. Until Task 3, `series` returns `[]` and `meta` returns `['turnover_caveat' => false]`.
- KPI contract (all in `$result['kpis']`): `headcount` int, `new_hires` int, `leavers` int, `turnover_rate` float(1dp), `avg_tenure` float(1dp), `headcount_delta` int (= `new_hires - leavers`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Services\WorkforceAnalyticsService;
use Carbon\CarbonImmutable;

function makeWindow(): array
{
    $to   = CarbonImmutable::create(2026, 7, 9);
    $from = $to->subDays(364); // 365-day inclusive window
    return [$from, $to];
}

it('computes headcount, hires, leavers, turnover, tenure and delta', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    // 10 active employees; 2 hired exactly 2y ago, rest hired 4y ago → avg tenure 3.0
    Employee::factory()->count(2)->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $to->subYears(2)->toDateString(),
    ]);
    Employee::factory()->count(8)->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $to->subYears(4)->toDateString(),
    ]);

    // 3 new hires WITHIN the window
    Employee::factory()->count(3)->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $from->addMonths(2)->toDateString(),
    ]);

    // 1 hire OUTSIDE the window (before) — must be excluded from new_hires
    Employee::factory()->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $from->subDays(1)->toDateString(),
    ]);

    // 2 leavers within the window (offboarding cases)
    $leaver1 = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated']);
    $leaver2 = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated']);
    OffboardingCase::factory()->create([
        'employee_id'                => $leaver1->id,
        'effective_termination_date' => $from->addMonths(3)->toDateString(),
    ]);
    OffboardingCase::factory()->create([
        'employee_id'                => $leaver2->id,
        'effective_termination_date' => $to->toDateString(), // boundary: on `to` counts
    ]);

    // 1 offboarding case OUTSIDE the window — excluded
    $leaver3 = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated']);
    OffboardingCase::factory()->create([
        'employee_id'                => $leaver3->id,
        'effective_termination_date' => $to->addDays(1)->toDateString(),
    ]);

    $k = app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['kpis'];

    // active headcount now = 2 + 8 + 3 + 1 = 14 (terminated excluded)
    expect($k['headcount'])->toBe(14)
        ->and($k['new_hires'])->toBe(3)
        ->and($k['leavers'])->toBe(2)
        // turnover = leavers / avgHeadcount * (365/days) * 100 = 2/14 * 1 * 100 = 14.3
        ->and($k['turnover_rate'])->toBe(14.3)
        ->and($k['headcount_delta'])->toBe(1) // 3 hires - 2 leavers
        // avg tenure over 14 active: (2*2 + 8*4 + 3*(~0.83) + 1*(~1.0)) / 14 ... asserted loosely below
        ->and($k['avg_tenure'])->toBeGreaterThan(0.0);
});

it('never divides by zero when there are no employees', function () {
    [$from, $to] = makeWindow();

    $k = app(WorkforceAnalyticsService::class)->metrics(null, $from, $to)['kpis'];

    expect($k['headcount'])->toBe(0)
        ->and($k['turnover_rate'])->toBe(0.0)
        ->and($k['avg_tenure'])->toBe(0.0);
});
```

> Note on factories: if `EmployeeFactory` / `OffboardingCaseFactory` require fields not set above (e.g. `user_id`), they supply their own defaults — only override what the assertions depend on. If the employee factory's default `status` is random, the explicit `'status' => 'active'` / `'terminated'` overrides shown here are what matter.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`
Expected: FAIL — `Class "App\Services\WorkforceAnalyticsService" not found`.

- [ ] **Step 3: Implement the service (KPIs only)**

Create `app/Services/WorkforceAnalyticsService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\OffboardingCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WorkforceAnalyticsService
{
    /**
     * @return array{kpis: array, series: array, meta: array}
     */
    public function metrics(?int $departmentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'kpis'   => $this->kpis($departmentId, $from, $to),
            'series' => [], // filled in Task 3
            'meta'   => ['turnover_caveat' => false], // filled in Task 3
        ];
    }

    private function kpis(?int $deptId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $headcount = (int) $this->scopedActive($deptId)->count();

        $newHires = (int) $this->scopedAll($deptId)
            ->whereBetween('hire_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $leavers = (int) OffboardingCase::query()
            ->whereBetween('effective_termination_date', [$from->toDateString(), $to->toDateString()])
            ->when($deptId, fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('department_id', $deptId)
            ))
            ->count();

        $days = max(1, $from->diffInDays($to) + 1);
        $turnover = $headcount > 0
            ? round(($leavers / $headcount) * (365 / $days) * 100, 1)
            : 0.0;

        $avgTenure = $this->avgTenure($deptId, $to);

        return [
            'headcount'       => $headcount,
            'new_hires'       => $newHires,
            'leavers'         => $leavers,
            'turnover_rate'   => $turnover,
            'avg_tenure'      => $avgTenure,
            'headcount_delta' => $newHires - $leavers,
        ];
    }

    private function avgTenure(?int $deptId, CarbonImmutable $to): float
    {
        $dates = $this->scopedActive($deptId)
            ->whereNotNull('hire_date')
            ->pluck('hire_date');

        if ($dates->isEmpty()) {
            return 0.0;
        }

        $years = $dates->map(fn ($d) => CarbonImmutable::parse($d)->floatDiffInYears($to));

        return round((float) $years->avg(), 1);
    }

    private function scopedActive(?int $deptId): Builder
    {
        return $this->scopedAll($deptId)->where('status', EmployeeStatus::Active->value);
    }

    private function scopedAll(?int $deptId): Builder
    {
        return Employee::query()->when($deptId, fn (Builder $q) => $q->where('department_id', $deptId));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkforceAnalyticsService.php tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php
git commit -m "feat(analytics): workforce KPIs service (headcount/hires/leavers/turnover/tenure)"
```

---

## Task 3: WorkforceAnalyticsService — series + caveat

**Files:**
- Modify: `app/Services/WorkforceAnalyticsService.php`
- Test: `tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php` (add cases)

**Interfaces:**
- Consumes: `App\Support\DbExpr::yearMonth(string $column): string` (cross-DB 'YYYY-MM' expression).
- Produces: `$result['series']` with keys `headcount_trend` (list of `{month, joiners, leavers, net}`), `by_department` (`{label,value}`), `gender` (`{label,value}` with `label` in Female/Male/Unspecified), `tenure_bands` (`{label,value}` bands `<1y|1-3y|3-5y|5y+`), `age_bands` (`{label,value}` bands `<25|25-34|35-44|45-54|55+`), `span_of_control` (`{label,value}` bands `1|2-3|4-6|7+`), `cost_by_department` (`{label,value}` value=float). `$result['meta']['turnover_caveat']` bool.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`:

```php
it('buckets tenure bands from hire_date relative to the window end', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subMonths(6)->toDateString()]);  // <1y
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subYears(2)->toDateString()]);   // 1-3y
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subYears(4)->toDateString()]);   // 3-5y
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subYears(9)->toDateString()]);   // 5y+

    $bands = collect(app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['series']['tenure_bands'])
        ->pluck('value', 'label');

    expect($bands['<1y'])->toBe(1)
        ->and($bands['1-3y'])->toBe(1)
        ->and($bands['3-5y'])->toBe(1)
        ->and($bands['5y+'])->toBe(1);
});

it('buckets gender with a null-safe Unspecified slice', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    Employee::factory()->count(2)->create(['department_id' => $dept->id, 'status' => 'active', 'gender' => 'female']);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'gender' => 'male']);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'gender' => null]);

    $g = collect(app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['series']['gender'])
        ->pluck('value', 'label');

    expect($g['Female'])->toBe(2)
        ->and($g['Male'])->toBe(1)
        ->and($g['Unspecified'])->toBe(1);
});

it('sums cost-to-company per department', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create(['name' => 'Engineering']);

    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'salary' => 1000]);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'salary' => 2500]);

    $cost = collect(app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['series']['cost_by_department'])
        ->firstWhere('label', 'Engineering');

    expect((float) $cost['value'])->toBe(3500.0);
});

it('flags turnover_caveat when a terminated employee has no offboarding case in range', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated']); // no offboarding case

    $meta = app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['meta'];

    expect($meta['turnover_caveat'])->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`
Expected: FAIL — `series` is `[]`, so `tenure_bands`/`gender`/`cost_by_department` are undefined and `turnover_caveat` is false.

- [ ] **Step 3: Implement the series + caveat**

In `app/Services/WorkforceAnalyticsService.php`, add the import:

```php
use App\Support\DbExpr;
```

Replace the `metrics()` body so `series`/`meta` are populated:

```php
    public function metrics(?int $departmentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'kpis'   => $this->kpis($departmentId, $from, $to),
            'series' => [
                'headcount_trend'    => $this->headcountTrend($departmentId, $from, $to),
                'by_department'      => $this->headcountByDepartment($departmentId),
                'gender'             => $this->genderBreakdown($departmentId),
                'tenure_bands'       => $this->tenureBands($departmentId, $to),
                'age_bands'          => $this->ageBands($departmentId, $to),
                'span_of_control'    => $this->spanOfControl($departmentId),
                'cost_by_department' => $this->costByDepartment($departmentId),
            ],
            'meta' => ['turnover_caveat' => $this->turnoverCaveat($departmentId, $from, $to)],
        ];
    }
```

Add these private methods to the class:

```php
    private function headcountTrend(?int $deptId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $expr = DbExpr::yearMonth('hire_date');
        $joiners = $this->scopedAll($deptId)
            ->whereBetween('hire_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("$expr as ym, COUNT(*) as c")->groupBy('ym')->pluck('c', 'ym');

        $leaveExpr = DbExpr::yearMonth('effective_termination_date');
        $leavers = OffboardingCase::query()
            ->whereBetween('effective_termination_date', [$from->toDateString(), $to->toDateString()])
            ->when($deptId, fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $deptId)))
            ->selectRaw("$leaveExpr as ym, COUNT(*) as c")->groupBy('ym')->pluck('c', 'ym');

        $out = [];
        $cursor = $from->startOfMonth();
        $end = $to->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $ym = $cursor->format('Y-m');
            $j = (int) ($joiners[$ym] ?? 0);
            $l = (int) ($leavers[$ym] ?? 0);
            $out[] = ['month' => $ym, 'joiners' => $j, 'leavers' => $l, 'net' => $j - $l];
            $cursor = $cursor->addMonth();
        }

        return $out;
    }

    private function headcountByDepartment(?int $deptId): array
    {
        return $this->scopedActive($deptId)
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.name as label, COUNT(*) as value')
            ->groupBy('departments.name')->orderByDesc('value')
            ->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->all();
    }

    private function genderBreakdown(?int $deptId): array
    {
        $rows = $this->scopedActive($deptId)
            ->selectRaw('gender, COUNT(*) as value')->groupBy('gender')->pluck('value', 'gender');

        $labelMap = ['female' => 'Female', 'male' => 'Male'];
        $out = [];
        foreach ($rows as $gender => $value) {
            $key = $gender ? strtolower((string) $gender) : null;
            $label = $key ? ($labelMap[$key] ?? ucfirst($key)) : 'Unspecified';
            $out[$label] = ($out[$label] ?? 0) + (int) $value;
        }

        return collect($out)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function tenureBands(?int $deptId, CarbonImmutable $to): array
    {
        $bands = ['<1y' => 0, '1-3y' => 0, '3-5y' => 0, '5y+' => 0];
        foreach ($this->scopedActive($deptId)->whereNotNull('hire_date')->pluck('hire_date') as $d) {
            $y = CarbonImmutable::parse($d)->floatDiffInYears($to);
            $key = $y < 1 ? '<1y' : ($y < 3 ? '1-3y' : ($y < 5 ? '3-5y' : '5y+'));
            $bands[$key]++;
        }

        return collect($bands)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function ageBands(?int $deptId, CarbonImmutable $to): array
    {
        $bands = ['<25' => 0, '25-34' => 0, '35-44' => 0, '45-54' => 0, '55+' => 0];
        foreach ($this->scopedActive($deptId)->whereNotNull('date_of_birth')->pluck('date_of_birth') as $d) {
            $age = CarbonImmutable::parse($d)->floatDiffInYears($to);
            $key = $age < 25 ? '<25' : ($age < 35 ? '25-34' : ($age < 45 ? '35-44' : ($age < 55 ? '45-54' : '55+')));
            $bands[$key]++;
        }

        return collect($bands)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function spanOfControl(?int $deptId): array
    {
        $counts = $this->scopedActive($deptId)
            ->whereNotNull('manager_id')
            ->selectRaw('manager_id, COUNT(*) as reports')
            ->groupBy('manager_id')->pluck('reports');

        $bands = ['1' => 0, '2-3' => 0, '4-6' => 0, '7+' => 0];
        foreach ($counts as $n) {
            $n = (int) $n;
            $key = $n === 1 ? '1' : ($n <= 3 ? '2-3' : ($n <= 6 ? '4-6' : '7+'));
            $bands[$key]++;
        }

        return collect($bands)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();
    }

    private function costByDepartment(?int $deptId): array
    {
        return $this->scopedActive($deptId)
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.name as label, COALESCE(SUM(employees.salary), 0) as value')
            ->groupBy('departments.name')->orderByDesc('value')
            ->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => round((float) $r->value, 2)])->all();
    }

    private function turnoverCaveat(?int $deptId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->scopedAll($deptId)
            ->where('status', EmployeeStatus::Terminated->value)
            ->whereDoesntHave('offboardingCases', fn (Builder $q) => $q
                ->whereBetween('effective_termination_date', [$from->toDateString(), $to->toDateString()]))
            ->exists();
    }
```

> `turnoverCaveat` uses an `offboardingCases` relation on `Employee`. If `Employee` has no such relation, add it:
> ```php
> public function offboardingCases(): \Illuminate\Database\Eloquent\Relations\HasMany
> {
>     return $this->hasMany(\App\Models\OffboardingCase::class);
> }
> ```
> First check `app/Models/Employee.php` for an existing offboarding relation (it may be named `offboarding` / `offboardingCase`); if one exists, use its name in `whenDoesntHave` instead of adding a duplicate.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php`
Expected: PASS (all cases, including the Task 2 ones).

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkforceAnalyticsService.php app/Models/Employee.php tests/Feature/Analytics/WorkforceAnalyticsServiceTest.php
git commit -m "feat(analytics): workforce series (trend/dept/gender/tenure/age/span/cost) + turnover caveat"
```

---

## Task 4: FormRequest + Controller + Route

**Files:**
- Create: `app/Http/Requests/WorkforceAnalyticsRequest.php`
- Create: `app/Http/Controllers/WorkforceAnalyticsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Analytics/WorkforceAnalyticsPageTest.php` (extend)

**Interfaces:**
- Consumes: `WorkforceAnalyticsService::metrics(...)` (Tasks 2–3); route name `analytics.workforce`.
- Produces: `GET /analytics/workforce` → Inertia component `Analytics/Workforce` with props `metrics`, `filters` (`{department_id, from, to}`), `departments` (`[{id,name}]`), `activeModule`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Analytics/WorkforceAnalyticsPageTest.php`:

```php
use App\Models\Department;
use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the workforce dashboard for a permissioned user', function () {
    $user = User::factory()->create(['role' => 'hr_admin']);
    $dept = Department::factory()->create();
    Employee::factory()->count(3)->create(['department_id' => $dept->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('analytics.workforce'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Analytics/Workforce')
            ->has('metrics.kpis.headcount')
            ->has('metrics.series')
            ->has('departments'));
});

it('403s for a user without workforce.analytics.view', function () {
    $user = User::factory()->create(['role' => 'employee']);

    $this->actingAs($user)->get(route('analytics.workforce'))->assertForbidden();
});

it('narrows headcount to the selected department', function () {
    $user = User::factory()->create(['role' => 'hr_admin']);
    $a = Department::factory()->create();
    $b = Department::factory()->create();
    Employee::factory()->count(2)->create(['department_id' => $a->id, 'status' => 'active']);
    Employee::factory()->count(5)->create(['department_id' => $b->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('analytics.workforce', ['department_id' => $a->id]))
        ->assertInertia(fn (Assert $p) => $p->where('metrics.kpis.headcount', 2));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsPageTest.php`
Expected: FAIL — route `analytics.workforce` is not defined.

- [ ] **Step 3: Create the FormRequest**

Create `app/Http/Requests/WorkforceAnalyticsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkforceAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces permission:workforce.analytics.view
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/WorkforceAnalyticsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WorkforceAnalyticsRequest;
use App\Models\Department;
use App\Services\WorkforceAnalyticsService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class WorkforceAnalyticsController extends Controller
{
    public function __construct(private readonly WorkforceAnalyticsService $analytics)
    {
    }

    public function index(WorkforceAnalyticsRequest $request): Response
    {
        $to   = $request->filled('to') ? CarbonImmutable::parse($request->date('to')) : CarbonImmutable::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->date('from')) : $to->subYear();
        $deptId = $request->filled('department_id') ? (int) $request->integer('department_id') : null;

        return Inertia::render('Analytics/Workforce', [
            'activeModule' => 'workforce-analytics',
            'filters'      => [
                'department_id' => $deptId,
                'from'          => $from->toDateString(),
                'to'            => $to->toDateString(),
            ],
            'departments'  => Department::query()->orderBy('name')->get(['id', 'name']),
            'metrics'      => $this->analytics->metrics($deptId, $from, $to),
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, inside the authenticated middleware group (alongside other feature routes such as the finance analytics route), add:

```php
    Route::get('analytics/workforce', [\App\Http\Controllers\WorkforceAnalyticsController::class, 'index'])
        ->name('analytics.workforce')
        ->middleware('permission:workforce.analytics.view');
```

> Match the file's existing import/use style — if `routes/web.php` imports controllers at the top with `use`, add `use App\Http\Controllers\WorkforceAnalyticsController;` and reference the short name instead of the FQCN.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsPageTest.php`
Expected: PASS (all page tests + the Task 1 RBAC tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/WorkforceAnalyticsRequest.php app/Http/Controllers/WorkforceAnalyticsController.php routes/web.php tests/Feature/Analytics/WorkforceAnalyticsPageTest.php
git commit -m "feat(analytics): workforce dashboard route, request + controller"
```

---

## Task 5: Vue dashboard page

**Files:**
- Create: `resources/js/Pages/Analytics/Workforce.vue`
- Test: build verification + the Inertia component assertion already in Task 4.

**Interfaces:**
- Consumes props from the controller: `metrics` (`{kpis, series, meta}`), `filters` (`{department_id, from, to}`), `departments` (`[{id,name}]`). Reuses `@/Components/StatCard.vue`, `@/Components/charts/ChartCard.vue`, `@/Components/charts/ChartJs/{BarChart,LineChart,DoughnutChart}.vue` (each takes `:data` = Chart.js `{labels, datasets}` and optional `:options`).

- [ ] **Step 1: Create the page**

Create `resources/js/Pages/Analytics/Workforce.vue`:

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import ChartCard from '@/Components/charts/ChartCard.vue';
import BarChart from '@/Components/charts/ChartJs/BarChart.vue';
import LineChart from '@/Components/charts/ChartJs/LineChart.vue';
import DoughnutChart from '@/Components/charts/ChartJs/DoughnutChart.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    metrics:     { type: Object, default: () => ({ kpis: {}, series: {}, meta: {} }) },
    filters:     { type: Object, default: () => ({ department_id: null, from: '', to: '' }) },
    departments: { type: Array,  default: () => [] },
});

const departmentId = ref(props.filters.department_id ?? '');
const from = ref(props.filters.from);
const to   = ref(props.filters.to);

const apply = () => router.get(route('analytics.workforce'), {
    department_id: departmentId.value || undefined,
    from: from.value || undefined,
    to: to.value || undefined,
}, { preserveState: false, preserveScroll: true });

const nf = new Intl.NumberFormat('en-GH');
const money = (v) => 'GHS ' + new Intl.NumberFormat('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v) || 0);

const k = computed(() => props.metrics.kpis ?? {});
const s = computed(() => props.metrics.series ?? {});

const kpiCards = computed(() => [
    { label: 'Headcount',      value: nf.format(k.value.headcount ?? 0),                 icon: 'groups',        color: 'blue',  hint: (k.value.headcount_delta >= 0 ? '+' : '') + (k.value.headcount_delta ?? 0) + ' net in period' },
    { label: 'New Hires',      value: nf.format(k.value.new_hires ?? 0),                 icon: 'person_add',    color: 'green' },
    { label: 'Leavers',        value: nf.format(k.value.leavers ?? 0),                   icon: 'logout',        color: 'amber' },
    { label: 'Turnover Rate',  value: (k.value.turnover_rate ?? 0) + '%',                icon: 'sync_problem',  color: 'red'   },
    { label: 'Avg Tenure',     value: (k.value.avg_tenure ?? 0) + ' yrs',                icon: 'hourglass_top', color: 'violet' },
]);

// palette matching the app's chart usage
const PALETTE = ['#1a237e', '#3949ab', '#00897b', '#f9a825', '#c62828', '#6a1b9a', '#0277bd', '#558b0f'];

const labelsOf  = (arr) => (arr ?? []).map((r) => r.label ?? r.month);
const valuesOf  = (arr) => (arr ?? []).map((r) => r.value);

const trendData = computed(() => ({
    labels: (s.value.headcount_trend ?? []).map((r) => r.month),
    datasets: [
        { type: 'bar',  label: 'Joiners', data: (s.value.headcount_trend ?? []).map((r) => r.joiners), backgroundColor: '#00897b' },
        { type: 'bar',  label: 'Leavers', data: (s.value.headcount_trend ?? []).map((r) => r.leavers), backgroundColor: '#c62828' },
        { type: 'line', label: 'Net',     data: (s.value.headcount_trend ?? []).map((r) => r.net),     borderColor: '#1a237e', tension: 0.3 },
    ],
}));

const barData = (arr, label) => ({
    labels: labelsOf(arr),
    datasets: [{ label, data: valuesOf(arr), backgroundColor: PALETTE }],
});

const genderData = computed(() => ({
    labels: labelsOf(s.value.gender),
    datasets: [{ data: valuesOf(s.value.gender), backgroundColor: PALETTE }],
}));

const baseOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true } } };
const horizontalOptions = { ...baseOptions, indexAxis: 'y' };

const hasData = (arr) => Array.isArray(arr) && arr.some((r) => (r.value ?? r.joiners ?? r.leavers ?? 0) > 0);
</script>

<template>
    <Head title="Workforce Analytics" />

    <div class="space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-primary">Workforce Analytics</h1>
                <p class="text-sm text-on-surface-variant">Headcount, composition and turnover across the organisation.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    Department
                    <select v-model="departmentId" class="mt-1 rounded-xl border-outline-variant/60 bg-surface-container-lowest text-sm">
                        <option value="">All departments</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    From
                    <input v-model="from" type="date" class="mt-1 rounded-xl border-outline-variant/60 bg-surface-container-lowest text-sm" />
                </label>
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    To
                    <input v-model="to" type="date" class="mt-1 rounded-xl border-outline-variant/60 bg-surface-container-lowest text-sm" />
                </label>
                <button @click="apply" class="rounded-xl bg-primary px-4 py-2 text-xs font-black text-white shadow-glow-sm">Apply</button>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
            <StatCard v-for="c in kpiCards" :key="c.label" :value="c.value" :label="c.label" :icon="c.icon" :color="c.color" :hint="c.hint" />
        </section>

        <p v-if="metrics.meta?.turnover_caveat" class="rounded-xl bg-amber-500/10 px-4 py-2 text-xs text-amber-800 dark:text-amber-300">
            Some employees marked terminated have no offboarding record in this period, so turnover may be understated.
        </p>

        <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <ChartCard title="Headcount trend" subtitle="Joiners vs leavers" icon="show_chart">
                <div class="h-72"><LineChart v-if="hasData(s.headcount_trend)" :data="trendData" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data for this period.</p></div>
            </ChartCard>
            <ChartCard title="Headcount by department" icon="apartment">
                <div class="h-72"><BarChart v-if="hasData(s.by_department)" :data="barData(s.by_department, 'Employees')" :options="horizontalOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Gender diversity" icon="diversity_3">
                <div class="h-72"><DoughnutChart v-if="hasData(s.gender)" :data="genderData" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Tenure bands" icon="hourglass_top">
                <div class="h-72"><BarChart v-if="hasData(s.tenure_bands)" :data="barData(s.tenure_bands, 'Employees')" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Age bands" icon="cake">
                <div class="h-72"><BarChart v-if="hasData(s.age_bands)" :data="barData(s.age_bands, 'Employees')" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Span of control" subtitle="Managers by number of direct reports" icon="account_tree">
                <div class="h-72"><BarChart v-if="hasData(s.span_of_control)" :data="barData(s.span_of_control, 'Managers')" :options="baseOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
            <ChartCard title="Cost to company by department" icon="payments" class="xl:col-span-2">
                <div class="h-72"><BarChart v-if="hasData(s.cost_by_department)" :data="barData(s.cost_by_department, 'Payroll cost')" :options="horizontalOptions" /><p v-else class="grid h-full place-items-center text-sm text-on-surface-variant">No data.</p></div>
            </ChartCard>
        </section>
    </div>
</template>
```

> Confirm `StatCard`'s exact prop names (`value`, `label`, `icon`, `color`, `hint`) against `resources/js/Components/StatCard.vue` and adjust if they differ. Confirm the chart components render a `{labels, datasets}` Chart.js object; if the app's `BarChart` expects a different shape, match the shape used in `Finance/Analytics/Dashboard.vue`.

- [ ] **Step 2: Build to verify it compiles**

Run: `npx vite build`
Expected: `✓ built` with no errors referencing `Analytics/Workforce.vue`.

- [ ] **Step 3: Re-run the page feature test (renders the real component)**

Run: `php artisan test tests/Feature/Analytics/WorkforceAnalyticsPageTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Analytics/Workforce.vue
git commit -m "feat(analytics): workforce dashboard Vue page (KPIs + charts)"
```

---

## Task 6: Sidebar navigation entry

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: `can('workforce.analytics.view')` helper (already defined in the layout, line ~88), route `analytics.workforce`, `module` accent key `'workforce-analytics'`.

- [ ] **Step 1: Add the nav item**

In `resources/js/Layouts/AuthenticatedLayout.vue`, locate the nav item array (the same structure holding the Finance/Attendance entries). Add a top-level item near the other analytics/reports entries:

```js
                    { label: 'Workforce Analytics', route: 'analytics.workforce', module: 'workforce-analytics', icon: 'insights', visible: can('workforce.analytics.view') },
```

> Place it consistent with how sibling items are declared (same object keys: `label`, `route`, `module`, `icon`, `visible`). If analytics items are grouped under a parent with `children`, add it as a child there instead; otherwise add it as a top-level item.

- [ ] **Step 2: Build to verify**

Run: `npx vite build`
Expected: `✓ built` with no errors.

- [ ] **Step 3: Full suite regression**

Run: `php artisan test`
Expected: PASS — the previous total plus the new Workforce tests, zero failures.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(analytics): add Workforce Analytics to sidebar navigation"
```

---

## Task 7: Integrate to main

**Files:** none (git only)

- [ ] **Step 1: Merge with a merge commit**

```bash
git checkout main
git merge --no-ff feat/workforce-analytics -m "Merge: workforce analytics dashboard"
```

- [ ] **Step 2: Push**

```bash
git push origin main
```

- [ ] **Step 3: Delete the branch**

```bash
git branch -d feat/workforce-analytics
```

---

## Self-Review Notes

- **Spec coverage:** KPI cards (Task 2), all 7 charts (Task 3), department + date filters (Task 4), RBAC senior-gating (Task 1), zero-state/null handling (Task 3 + Task 5 `hasData`), turnover caveat footnote (Task 3 + Task 5), nav discoverability (Task 6), both test files (Tasks 2–5). No spec requirement left unassigned.
- **Naming consistency:** `metrics()` return keys (`kpis`, `series`, `meta`) and every series key match across service, controller props, and Vue consumption. Permission slug identical in enum, `ROLE_PERMISSIONS`, route middleware, and `can()` call.
- **Known verification points flagged inline** (factory fields, `StatCard` props, chart data shape, `offboardingCases` relation existence, route import style) — the implementer confirms each against the real file rather than assuming.
```
