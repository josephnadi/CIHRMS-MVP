# Workforce Analytics Dashboard — Design

**Date:** 2026-07-10
**Status:** Approved for planning
**Module:** People Analytics (new, under the Analytics/Insights area)

## Goal

Give HR leadership a read-only people-analytics dashboard: point-in-time
workforce composition plus trailing-period flow (joiners/leavers/turnover),
with department and date-range drill-down. Closes the HR-side analytics gap
(a Finance Analytics dashboard already exists; the people side has none).

## Non-Goals (YAGNI / phase 2)

- Predictive attrition / flight-risk scoring
- CSV / PDF export, saved views, custom cohorts
- Drill-through to individual employee lists
- A separately-permissioned salary sub-view (page is senior-only as a whole)

## Architecture

Read-only, aggregation-only. No new tables, no writes. Follows the app's
Enum→FormRequest→Service→Resource convention and reuses the existing
`Finance/Analytics/Dashboard.vue` Chart.js pattern as the visual/structural
template.

```
Route  GET /analytics/workforce  (auth + permission:workforce.analytics.view)
  -> WorkforceAnalyticsController@index
       validates WorkforceAnalyticsRequest (department_id?, from?, to?)
       -> WorkforceAnalyticsService::metrics(deptId, from, to): array
       -> Inertia::render('Analytics/Workforce', ['metrics'=>..., 'filters'=>..., 'departments'=>..., 'activeModule'=>'...'])
```

### Components

**`app/Services/WorkforceAnalyticsService.php`** — the only place aggregation
lives. Public entry:

```php
public function metrics(?int $departmentId, CarbonImmutable $from, CarbonImmutable $to): array
```

Returns a structured array:

```php
[
  'kpis' => [
    'headcount'      => int,   // active employees now (dept-filtered)
    'new_hires'      => int,   // hire_date within [from,to]
    'leavers'        => int,   // OffboardingCase.effective_termination_date within [from,to]
    'turnover_rate'  => float, // leavers / avg_headcount, annualised %, rounded 1dp
    'avg_tenure'     => float, // mean tenure (years) of active staff, rounded 1dp
    'headcount_delta'=> int,   // headcount now - headcount at `from` (trend arrow)
  ],
  'series' => [
    'headcount_trend'   => [ ['month'=>'2026-01','joiners'=>int,'leavers'=>int,'net'=>int], ... ],
    'by_department'     => [ ['label'=>string,'value'=>int], ... ],
    'gender'            => [ ['label'=>'Female|Male|Unspecified','value'=>int], ... ],
    'tenure_bands'      => [ ['label'=>'<1y|1-3y|3-5y|5y+','value'=>int], ... ],
    'age_bands'         => [ ['label'=>'<25|25-34|35-44|45-54|55+','value'=>int], ... ],
    'span_of_control'   => [ ['label'=>'1|2-3|4-6|7+','value'=>int], ... ], // managers bucketed by #direct reports
    'cost_by_department'=> [ ['label'=>string,'value'=>float], ... ],       // sum(salary) per dept
  ],
  'meta' => [
    'turnover_caveat' => bool, // true if any terminated employees lack an OffboardingCase in range
  ],
]
```

Each metric is a small private method (`headcountByDepartment()`,
`turnover()`, `tenureBands()`, `ageBands()`, `spanOfControl()`,
`costByDepartment()`, `headcountTrend()`), grouped/aggregated in SQL
(`selectRaw` + `groupBy`), never N+1 — mirroring `DashboardService`. The
department filter, when set, scopes every metric to that department
(cost/headcount/etc.); when null, covers the whole org.

**`app/Http/Requests/WorkforceAnalyticsRequest.php`** — validates:
- `department_id` — nullable, integer, `exists:departments,id`
- `from` — nullable, date
- `to` — nullable, date, `after_or_equal:from`
Controller applies defaults when absent: `to = today`, `from = today->subYear()`.

**`app/Http/Controllers/AnalyticsController.php` (extend) or new
`WorkforceAnalyticsController.php`** — `index()` validates, resolves
defaults, calls the service, passes `metrics`, `filters` (echoed back),
`departments` (id+name list for the filter select), and `activeModule` for
the module accent. Decision: **new `WorkforceAnalyticsController`** — the
existing `AnalyticsController` is an unrelated event-telemetry endpoint;
keep concerns separate.

**`resources/js/Pages/Analytics/Workforce.vue`** — filter bar (department
`<select>` + `from`/`to` date inputs) posting via `router.get` with
`preserveState`/`preserveScroll`; a StatCard row (reusing
`Components/StatCard.vue`); a responsive chart grid reusing the
`.chart-card` styling and theme-aware colour approach from
`Finance/Analytics/Dashboard.vue`. Charts via the same Chart.js integration
already in the app. Wide charts wrapped in `overflow-x-auto`.

**Route + nav + RBAC:**
- `Route::get('analytics/workforce', [WorkforceAnalyticsController::class,'index'])->name('analytics.workforce')->middleware('permission:workforce.analytics.view')` in the authenticated group.
- Sidebar nav entry under an Analytics/Insights group, rendered only when the user has `workforce.analytics.view`.
- New permission `workforce.analytics.view`, seeded to roles `hr_admin`, `ceo`, `super_admin`, and mirrored into the legacy `User::ROLE_PERMISSIONS` map (established pattern).

## Data Sources (existing)

- `employees`: `status` (EmployeeStatus enum: active/inactive/terminated/on_leave), `department_id`, `hire_date`, `gender`, `date_of_birth`, `salary`, `manager_id`, `tenure` accessor.
- `offboarding_cases`: `effective_termination_date`, `last_working_day` (leaver flow).
- `departments`: id, name (filter list + labels).

## Error / Empty Handling

- Empty range or a department with no staff → zero-state KPI cards and
  "No data for this period" chart placeholders; never a broken chart.
- Null dimensions bucket into "Unspecified" (gender) or are omitted from the
  band chart (age with no DOB) — never crash.
- Turnover: where `terminated` employees have no `OffboardingCase` in range,
  they are not counted as leavers; `meta.turnover_caveat` drives a small UI
  footnote so the number is never silently misleading.
- Division-by-zero guarded (avg headcount 0 → turnover 0).

## Testing (Pest)

**Feature — `tests/Feature/Analytics/WorkforceAnalyticsTest.php`:**
- Renders `Analytics/Workforce` for a user with `workforce.analytics.view`
  (assert Inertia component + presence of `metrics.kpis`).
- 403s for a user without the permission.
- Department filter narrows results (seed two departments, filter to one,
  assert headcount reflects only that department).

**Unit/service — `tests/Unit/WorkforceAnalyticsServiceTest.php` (or Feature
if DB-backed):**
- Seed a known population: N active, M hires within range, K offboarding
  cases within range, plus out-of-range hires/leavers that must be excluded.
- Assert exact `headcount`, `new_hires`, `leavers`, `turnover_rate`,
  `avg_tenure`, and each band bucket count.
- Date-boundary cases: hire exactly on `from` and on `to` are included;
  one day outside is excluded.
- `meta.turnover_caveat` true when a `terminated` employee has no
  `OffboardingCase` in range.

## Deliverables

1 service, 1 controller, 1 FormRequest, 1 Vue page, route + nav + permission
wiring, 2 test files. Single cohesive implementation plan.
