# CIHRMS — End-to-End Wiring & Module Completion

> **Spec date:** 2026-05-15
> **Status:** Approved (Parts 1 + 2)
> **Author:** Senior Laravel engineer engagement
> **Repo state at spec time:** Not yet under git; 8 core modules feature-complete; 4 sidebar modules are styled skeletons with no backend.

---

## 1. Executive summary

CIHRMS is a Laravel 13.7 + Inertia v2 + Vue 3 HRMS with 8 core modules already shipped end-to-end (Employees, Leave, Tickets, Payments, Recruitment, Complaints, Performance, Learning). Four further modules (Attendance, Assets, Benefits, Governance) exist only as Vue styled-skeleton pages — no backend, no controllers, no models. Approximately 28 `comingSoon` toast buttons mark the remaining non-functional surface area.

This spec covers a single phased delivery that:

1. Establishes version control and CI.
2. Removes the duplicated module sections from the monolithic `Dashboard.vue` and wires the dashboard's `comingSoon` buttons to their real backends.
3. Ships the four missing modules to **enterprise-deeper** depth following the established `Enum → FormRequest → Service → Event → Listener → Resource` pattern.
4. Hardens the application for production (queue driver, rate limiting, password-must-change, Sentry, backups).

Estimated effort for a single senior engineer at this depth: **6–10 weeks**.

---

## 2. Goals

- All buttons in the application are functional end-to-end — every click either performs a real action, routes to a real page, or surfaces a real error.
- Four new modules (Attendance, Assets, Benefits, Governance) ship at enterprise-deeper depth: full domain models, workflows, RBAC, events, tests.
- `Dashboard.vue` becomes a true home dashboard (KPIs + activity feed + quick actions linking to dedicated pages) rather than a parallel module workspace.
- The whole thing ships under git with a green CI pipeline running Pest tests on PHP 8.4.
- Production-readiness checklist (Phase 6) closed: real queue driver, rate limiting, password-must-change-on-first-login, HTTPS-only cookies, Sentry, backups.

## 3. Non-goals

- Multi-tenancy.
- Native mobile applications.
- Real-time WebSocket presence (defer until concrete in-product need).
- Biometric hardware integration for Attendance — only a `source=biometric` stub.
- Third-party insurer/provider API integration for Benefits — plans/enrolments/claims are local-only.
- DocuSign / external e-signature for Governance policy acks — in-app typed-name signature with IP+UA capture.
- Backfilling AI-Assistant beyond the existing placeholder.

## 4. Phase order

| Phase | Title | Est. effort | Demoable artefact |
|---|---|---|---|
| **P0** | Version control + CI | 1 day | Clean git history, `main` branch, CI workflow on PHP 8.4 |
| **P1** | Glue + Dashboard.vue trim | 1–2 days | `Dashboard.vue` reduced ~1000 LOC, every `comingSoon` button removed or rewired |
| **P2** | Attendance module | 5–9 days | Working clock-in/out, corrections, shifts, manager approval, monthly summary |
| **P3** | Assets module | 5–8 days | Asset register, assignment workflow, maintenance, monthly depreciation |
| **P4** | Benefits module | 5–9 days | Plans, enrolments, dependants, claims workflow, e-card PDF |
| **P5** | Governance module | 5–8 days | Policy versions, acknowledgements, certification reminders |
| **P6** | Production hardening | 2–3 days | Queue driver, rate limit, password-must-change, Sentry, backups |

Each phase is an independently demoable, mergeable unit. Phases are sequential — no overlap.

---

## 5. Cross-cutting decisions

These apply to every phase below. Where a phase deviates, the deviation is explicit in the phase section.

| Area | Decision |
|---|---|
| **Architecture pattern** | Enum → migration → model (SoftDeletes + Enum casts + scopes) → FormRequest (with `authorize()` using `hasPermission()`) → Service → Event → queued analytics listener → JsonResource → thin controller → Inertia page. No deviation. |
| **RBAC** | New permission slugs follow `module.action` form. Registered in `Database\Seeders\RolePermissionSeeder` (already idempotent and re-runnable). No new DB tables for permissions. |
| **Auditing** | `AuditTrail` middleware already covers every authenticated route via `WriteAuditLog` queued job. No per-controller audit code. |
| **Analytics** | All domain events dispatched through `RecordAnalyticsEvent` queued listener (already wired in `AppServiceProvider`). No inline `AnalyticsEvent::create()`. |
| **Notifications** | All outbound notifications dispatched through the existing `SendNotifications` listener (respects per-user channel prefs). |
| **Soft deletes** | Every new domain table has `$table->softDeletes()` and every model has the `SoftDeletes` trait. |
| **Caching** | Aggregates and dashboards cached 60 s per user via existing pattern in `DashboardService`. |
| **Migrations** | SQLite-compatible (no Postgres-only types). Use `json` cast on models, never `jsonb`. Snake_case table & column names. |
| **Inertia pages** | Reuse existing `StatusBadge`, `EmptyState`, `Pagination`, `SlidePanel`, `KanbanBoard`, `DateRangePicker`, `SearchInput`, `InlineEdit` components. Follow Sovereign Precision design tokens (no new design language). |
| **Permissions exposure** | New slugs exposed via `HandleInertiaRequests` (already lazy-loads `auth.permissions`); Vue checks `$page.props.auth.permissions.includes('foo.bar')`. |
| **Test strategy** | Pest 4 feature tests per new controller action: happy path + RBAC deny + one critical side-effect. Tests do not execute locally on PHP 8.5.5 (existing `laravel/pao` blocker) — they run in CI on PHP 8.4. |
| **`comingSoon` helper** | Removed from `useToast.js` at end of P5 when no remaining call sites exist. |
| **Code style** | One blank line between method groups, four-space indent (matching existing files), strict `declare(strict_types=1)` at top of new PHP files, type hints on parameters and return types. |

---

## 6. Phase 0 — Version control + CI

### Tasks
1. `git init` in `d:/CIHRMS/cihrms-mvp/`.
2. Write `.gitignore` covering:
   - `vendor/`, `node_modules/`, `public/build/`, `public/hot`
   - `.env`, `.env.*` except `.env.example`
   - `storage/*.log`, `storage/framework/cache/`, `storage/framework/sessions/`, `storage/framework/views/`
   - `database/database.sqlite`, `database/database.sqlite-journal`
   - IDE: `.idea/`, `.vscode/`, `*.swp`
3. Initial commit on `main` capturing the current state verbatim. Commit message: `chore: initial commit of CIHRMS MVP at 2026-05-19 snapshot`.
4. Add `.github/workflows/ci.yml`:
   - Triggers: `push`, `pull_request` to `main`.
   - Matrix: PHP 8.4 only (avoid the PHP 8.5/pao blocker until P6).
   - Steps: setup PHP, composer install, npm ci, copy `.env.example` → `.env`, `php artisan key:generate`, `php artisan migrate --seed --force`, `npm run build`, `vendor/bin/pest`.
5. Commit CI workflow on its own commit.

### Done when
- `git status` is clean.
- A new collaborator can run `git clone … && composer install && npm ci && cp .env.example .env && php artisan key:generate && php artisan migrate --seed && composer dev` and have a working app.
- CI is green.

### Open question to resolve at P0 start
- Remote: GitHub, GitLab, Bitbucket, or self-hosted? Spec assumes GitHub but is otherwise neutral.

---

## 7. Phase 1 — Glue + Dashboard.vue trim

### Buttons to rewire (backend already exists)

| Current | Action |
|---|---|
| `Dashboard.vue:438` "Profile actions menu" | Replace with `router.visit(route('profile.edit'))` |
| `Dashboard.vue:486` "Performance review history" | Replace with `router.visit(route('performance.reviews.index'))` |
| `Dashboard.vue:765` "Strategic OKR roadmap viewer" | Replace with `router.visit(route('performance.goals.index'))` |
| `Dashboard.vue:2431` "Announcements archive" | Replace with `router.visit(route('notifications.index'))` |
| `Dashboard.vue:2526` "Personal task tracker" | Replace with `router.visit(route('tickets.index'))` (filtered by `assignee=self`) |
| `Dashboard.vue:2922` "AI workforce report" | Replace with `router.visit(route('reports.index'))` |

### Sections to delete from Dashboard.vue (duplicate dedicated pages exist)
- Assets v-if block (lines around 1290–1340 — duplicates `Pages/Assets/Index.vue`).
- Benefits/Insurance/Provident v-if block (lines around 1390–1430 — duplicates `Pages/Benefits/Index.vue`).
- Learning v-if block (lines around 1450–1500 — duplicates `Pages/Learning/Catalog.vue` and `Pages/Learning/MyLearning.vue`).
- Governance v-if block (around 1250–1265 — duplicates `Pages/Governance/Index.vue`).
- Inline forms at lines ~2980+ for employee/department/leave/ticket. **Replace** with quick-action cards that `router.visit()` to the dedicated module pages — these forms are duplicates of slide-panels already present on the dedicated pages, so retaining them is double-maintenance.

### Dashboard.vue final shape
A real home dashboard with:
- Top KPI row (existing — `stats.employees`, `stats.openTickets`, `stats.pendingLeave`, `stats.pendingPayments`) — already wired.
- Time-series sparklines, fed by **new** `DashboardService::timeSeries(string $metric, int $days = 30): array` returning `[ ['date' => '2026-05-15', 'value' => 42], … ]`.
- Recent activity feed (already wired to `analytics_events`).
- Quick-action card grid linking to all 12 modules (one card per module, including the four new modules from P2–P5).

### `DashboardService` additions

```php
public function timeSeries(string $metric, int $days = 30): array
{
    return Cache::remember(
        "dashboard.timeseries.{$this->user->id}.{$metric}.{$days}",
        60,
        fn () => $this->buildSeries($metric, $days)
    );
}
```

Supported metrics: `employees`, `open_tickets`, `pending_leave`, `pending_payments`, `payslips_paid`, `applicants`. Implementation reads `analytics_events` aggregated by day.

### Done when
- No `comingSoon` call site remains in `Pages/Dashboard.vue`. (Remaining call sites in `Assets/Index.vue`, `Benefits/Index.vue`, `Governance/Index.vue`, `Attendance/Index.vue`, `Departments/Show.vue` are addressed in P2–P5.)
- `Dashboard.vue` LOC dropped from ~3,221 to ~2,200.
- All sparkline arrays are reactive props from `DashboardService::timeSeries()`, not literals.
- New `DashboardService::timeSeries` test passes.

---

## 8. Phase 2 — Attendance

### Domain model

**Enums** (`app/Enums/`):
- `AttendanceStatus`: `clocked_in`, `clocked_out`, `absent`, `on_leave`, `holiday`, `pending_correction`
- `ClockMethod`: `web`, `mobile`, `manual`, `biometric`
- `CorrectionStatus`: `pending`, `approved`, `rejected`

**Tables** (`database/migrations/`):

```
attendances
  id, employee_id (FK), work_date (date, indexed), shift_id (FK nullable),
  clock_in_at (datetime nullable), clock_out_at (datetime nullable),
  clock_in_lat (decimal 10,7 nullable), clock_in_lng (decimal 10,7 nullable),
  clock_out_lat (decimal 10,7 nullable), clock_out_lng (decimal 10,7 nullable),
  clock_in_method (enum), clock_out_method (enum nullable),
  source_device (string 255 nullable),
  total_hours (decimal 5,2 nullable), overtime_hours (decimal 5,2 nullable),
  late_minutes (integer nullable),
  status (enum), notes (text nullable),
  approved_by (FK users nullable), approved_at (timestamp nullable),
  softDeletes, timestamps
  UNIQUE (employee_id, work_date)

attendance_corrections
  id, attendance_id (FK), requester_id (FK users),
  requested_clock_in_at, requested_clock_out_at, reason (text),
  status (enum), reviewer_id (FK users nullable), reviewed_at (nullable),
  decision_notes (text nullable),
  softDeletes, timestamps

shifts
  id, name (string 80), code (string 20 unique),
  start_time (time), end_time (time),
  grace_period_minutes (integer default 10),
  overtime_threshold_minutes (integer default 0),
  applies_to_department_id (FK departments nullable),
  is_active (bool default true),
  softDeletes, timestamps

shift_assignments
  id, employee_id (FK), shift_id (FK),
  effective_from (date), effective_to (date nullable),
  softDeletes, timestamps
  INDEX (employee_id, effective_from)

geofence_zones
  id, name (string 80), lat (decimal 10,7), lng (decimal 10,7),
  radius_meters (integer default 200),
  is_active (bool default true),
  timestamps
```

### Service — `App\Services\AttendanceService`

Methods:
- `clockIn(User $user, ?float $lat = null, ?float $lng = null, ClockMethod $method = ClockMethod::Web, ?string $deviceId = null): Attendance` — looks up today's attendance row (creates if absent), validates not already clocked in, runs geofence check (logs warning but doesn't block), stamps `clock_in_at`, emits `ClockedIn`.
- `clockOut(User $user, ?float $lat = null, ?float $lng = null, ClockMethod $method = ClockMethod::Web): Attendance` — validates currently clocked in, stamps `clock_out_at`, computes `total_hours`, `late_minutes` (from shift assignment), `overtime_hours`, sets status=`clocked_out`, emits `ClockedOut`.
- `requestCorrection(User $user, Attendance $attendance, array $data): AttendanceCorrection` — creates correction row with status=`pending`, emits `AttendanceCorrectionRequested`.
- `approveCorrection(User $reviewer, AttendanceCorrection $correction, ?string $notes = null): AttendanceCorrection` — applies requested times to attendance row, stamps reviewer + status, emits `AttendanceCorrectionApproved`.
- `rejectCorrection(User $reviewer, AttendanceCorrection $correction, string $notes): AttendanceCorrection` — emits `AttendanceCorrectionRejected`.
- `markAbsentForDate(\DateTimeInterface $date): int` — invoked by daily scheduled job; returns count of rows created. Skips employees with an approved `LeaveRequest` on that date.
- `monthlySummary(Employee $employee, int $year, int $month): array` — cached aggregate: `['days_worked' => …, 'days_absent' => …, 'late_count' => …, 'total_overtime_hours' => …]`.

### Events

`ClockedIn`, `ClockedOut`, `AttendanceCorrectionRequested`, `AttendanceCorrectionApproved`, `AttendanceCorrectionRejected`, `AttendanceMarkedAbsent`. All listened to by `RecordAnalyticsEvent`.

### Policies — `App\Policies\AttendancePolicy`

- `view(User, Attendance)` — owner OR `attendance.view_all` OR dept-head of employee's department.
- `viewAny(User)` — owner OR `attendance.view_all`.
- `manage(User)` — `attendance.manage`.
- `approveCorrection(User, AttendanceCorrection)` — `attendance.approve` AND (dept-head of requester OR HR/super-admin).

### FormRequests (`app/Http/Requests/Attendance/`)
- `ClockInRequest` — `lat` optional decimal, `lng` optional decimal, `method` in ClockMethod enum, `device_id` optional string max 255.
- `ClockOutRequest` — same.
- `StoreCorrectionRequest` — `requested_clock_in_at` datetime required, `requested_clock_out_at` datetime required after the clock_in, `reason` string max 500 required.
- `ReviewCorrectionRequest` — `decision_notes` string max 500, required when status=rejected.
- `StoreShiftRequest` / `UpdateShiftRequest` — full shift fields + `code` unique.
- `AssignShiftRequest` — `employee_id` exists:employees, `shift_id` exists:shifts, `effective_from` date, `effective_to` date nullable after effective_from.

### Controller — `App\Http\Controllers\AttendanceController`

Actions: `index`, `my`, `clockIn`, `clockOut`, `correctionsIndex`, `storeCorrection`, `approveCorrection`, `rejectCorrection`, `shiftsIndex`, `storeShift`, `updateShift`, `destroyShift`, `assignShift`.

### Routes — `routes/web.php`

```php
Route::prefix('attendance')->name('attendance.')->middleware(['auth', 'audit'])->group(function () {
    Route::get('/',                  [AttendanceController::class, 'index'])
        ->middleware('permission:attendance.view_all')->name('index');
    Route::get('/my',                [AttendanceController::class, 'my'])->name('my');
    Route::post('/clock-in',         [AttendanceController::class, 'clockIn'])->name('clock-in');
    Route::post('/clock-out',        [AttendanceController::class, 'clockOut'])->name('clock-out');

    Route::prefix('corrections')->name('corrections.')->group(function () {
        Route::get('/',                          [AttendanceController::class, 'correctionsIndex'])
            ->middleware('permission:attendance.approve')->name('index');
        Route::post('/',                         [AttendanceController::class, 'storeCorrection'])->name('store');
        Route::patch('/{correction}/approve',    [AttendanceController::class, 'approveCorrection'])
            ->middleware('permission:attendance.approve')->name('approve');
        Route::patch('/{correction}/reject',     [AttendanceController::class, 'rejectCorrection'])
            ->middleware('permission:attendance.approve')->name('reject');
    });

    Route::prefix('shifts')->name('shifts.')->middleware('permission:attendance.manage')->group(function () {
        Route::get('/',              [AttendanceController::class, 'shiftsIndex'])->name('index');
        Route::post('/',             [AttendanceController::class, 'storeShift'])->name('store');
        Route::patch('/{shift}',     [AttendanceController::class, 'updateShift'])->name('update');
        Route::delete('/{shift}',    [AttendanceController::class, 'destroyShift'])->name('destroy');
        Route::post('/assignments',  [AttendanceController::class, 'assignShift'])->name('assign');
    });
});
```

### Inertia pages (`resources/js/Pages/Attendance/`)

Replace existing skeleton `Index.vue` with four real pages:
- `Index.vue` — HR/manager daily attendance table with date-range filter, dept filter, status filter, export-to-XLSX hook.
- `My.vue` — large clock-in/out widget (with current status + last clock event), month calendar showing each day's attendance status with colour code, "Request correction" buttons that open a slide-panel form.
- `Corrections.vue` — kanban-board view (`KanbanBoard` component) of pending corrections grouped by department, with approve/reject inline actions.
- `Shifts.vue` — split view: shifts list (CRUD) on left, assignment editor on right.

### Scheduled jobs (`routes/console.php` or `app/Console/Kernel.php`)

- `App\Console\Commands\MarkAbsentEmployees` — runs daily at 23:55. Calls `AttendanceService::markAbsentForDate(today())`.
- Job class: `App\Jobs\RegenerateMonthlySummaries` — runs first day of each month at 00:30. Pre-warms `AttendanceService::monthlySummary` cache for the previous month for every active employee.

### Permissions added to `RolePermissionSeeder`
- `attendance.view` → employee, manager, dept_head, hr_admin, super_admin
- `attendance.view_all` → manager, dept_head, hr_admin, super_admin
- `attendance.manage` → hr_admin, super_admin
- `attendance.approve` → manager, dept_head, hr_admin, super_admin
- `attendance.correct` → employee, manager, dept_head, hr_admin, super_admin (own correction)

### Tests (`tests/Feature/AttendanceTest.php`)
- `it allows an employee to clock in and stamps clock_in_at`
- `it prevents double clock-in on same day`
- `it computes total_hours and late_minutes on clock-out`
- `it lets an employee request a correction and a manager approve it`
- `it forbids a non-manager from approving a correction (RBAC deny)`
- `it marks an employee absent at end of day when no row exists and no leave is approved`
- `it skips absent-marking when an approved leave covers the date`

---

## 9. Phase 3 — Assets

### Domain model

**Enums:**
- `AssetCategory`: `laptop`, `monitor`, `phone`, `vehicle`, `furniture`, `other`
- `AssetStatus`: `in_stock`, `assigned`, `maintenance`, `retired`, `lost`
- `AssignmentConditionOnReturn`: `good`, `fair`, `poor`, `damaged`
- `MaintenanceType`: `repair`, `service`, `upgrade`
- `MaintenanceStatus`: `open`, `in_progress`, `completed`, `cancelled`

**Tables:**

```
assets
  id, asset_tag (string 40 unique), name (string 120),
  category (enum), serial_number (string 80 nullable),
  brand (string 80 nullable), model (string 80 nullable),
  purchase_date (date nullable), purchase_cost (decimal 12,2 nullable),
  currency (char 3 default 'GHS'), supplier (string 120 nullable),
  warranty_expires_at (date nullable),
  current_status (enum default 'in_stock'),
  current_assignment_id (FK asset_assignments nullable),
  location (string 120 nullable), notes (text nullable),
  softDeletes, timestamps

asset_assignments
  id, asset_id (FK), employee_id (FK),
  assigned_at (datetime), assigned_by (FK users),
  due_back_at (date nullable),
  returned_at (datetime nullable), returned_to (FK users nullable),
  condition_on_return (enum nullable), notes (text nullable),
  signed_handover_path (string 255 nullable),
  softDeletes, timestamps

asset_maintenance
  id, asset_id (FK), type (enum), status (enum default 'open'),
  started_at (datetime), completed_at (datetime nullable),
  cost (decimal 12,2 nullable), vendor (string 120 nullable),
  notes (text nullable), recorded_by (FK users),
  softDeletes, timestamps

asset_depreciation_snapshots
  id, asset_id (FK), as_of_date (date),
  book_value (decimal 12,2), method (string 20 default 'straight_line'),
  useful_life_years (integer), salvage_value (decimal 12,2),
  timestamps
  UNIQUE (asset_id, as_of_date)
```

### Configuration — `config/assets.php`

```php
return [
    'depreciation' => [
        'laptop'    => ['useful_life_years' => 3,  'salvage_pct' => 0.05],
        'monitor'   => ['useful_life_years' => 5,  'salvage_pct' => 0.05],
        'phone'     => ['useful_life_years' => 2,  'salvage_pct' => 0.05],
        'vehicle'   => ['useful_life_years' => 5,  'salvage_pct' => 0.10],
        'furniture' => ['useful_life_years' => 10, 'salvage_pct' => 0.05],
        'other'     => ['useful_life_years' => 5,  'salvage_pct' => 0.05],
    ],
];
```

### Service — `App\Services\AssetService`

- `register(array $data): Asset`
- `assign(Asset $asset, Employee $employee, User $by, ?\DateTimeInterface $dueBackAt = null, ?string $notes = null): AssetAssignment` — atomic transaction, sets `asset.current_status=assigned`, dispatches `AssetAssigned` event.
- `returnAsset(AssetAssignment $assignment, User $to, AssignmentConditionOnReturn $condition, ?string $notes = null): AssetAssignment` — stamps `returned_at`, resets `asset.current_status=in_stock`, if condition=damaged opens an `AssetMaintenance(type=repair, status=open)`.
- `logMaintenance(Asset $asset, MaintenanceType $type, User $recordedBy, array $data): AssetMaintenance`
- `completeMaintenance(AssetMaintenance $maintenance, User $by, ?float $cost = null, ?string $notes = null): AssetMaintenance` — sets `asset.current_status=in_stock`.
- `retire(Asset $asset, User $by, string $reason): Asset`
- `markLost(Asset $asset, User $by, string $reason): Asset`
- `regenerateDepreciationSnapshot(Asset $asset, \DateTimeInterface $asOfDate): AssetDepreciationSnapshot` — straight-line per category config.

### Events

`AssetAssigned`, `AssetReturned`, `AssetMaintenanceLogged`, `AssetMaintenanceCompleted`, `AssetRetired`, `AssetMarkedLost`.

### Policies — `App\Policies\AssetPolicy`

- `viewAny(User)` — `assets.view`.
- `view(User, Asset)` — `assets.view`; assignee may view their assigned asset.
- `manage(User)` — `assets.manage`.
- `assign(User, Asset)` — `assets.manage` OR (`assets.assign` AND user manages the asset's intended dept). HR-side path goes through manage; manager-side path goes through assign.

### FormRequests
- `StoreAssetRequest`, `UpdateAssetRequest`
- `AssignAssetRequest`, `ReturnAssetRequest`
- `StoreMaintenanceRequest`, `CompleteMaintenanceRequest`
- `RetireAssetRequest`, `MarkLostRequest`

### Routes

```php
Route::prefix('assets')->name('assets.')->middleware(['auth', 'audit'])->group(function () {
    Route::get('/',                              [AssetController::class, 'index'])
        ->middleware('permission:assets.view')->name('index');
    Route::post('/',                             [AssetController::class, 'store'])
        ->middleware('permission:assets.manage')->name('store');
    Route::get('/my',                            [AssetController::class, 'myAssets'])->name('my');
    Route::get('/{asset}',                       [AssetController::class, 'show'])
        ->middleware('permission:assets.view')->name('show');
    Route::patch('/{asset}',                     [AssetController::class, 'update'])
        ->middleware('permission:assets.manage')->name('update');
    Route::delete('/{asset}',                    [AssetController::class, 'destroy'])
        ->middleware('permission:assets.manage')->name('destroy');
    Route::post('/{asset}/assign',               [AssetController::class, 'assign'])->name('assign');
    Route::post('/assignments/{assignment}/return',   [AssetController::class, 'returnAsset'])->name('return');
    Route::post('/{asset}/maintenance',          [AssetController::class, 'storeMaintenance'])
        ->middleware('permission:assets.manage')->name('maintenance.store');
    Route::patch('/maintenance/{maintenance}/complete', [AssetController::class, 'completeMaintenance'])
        ->middleware('permission:assets.manage')->name('maintenance.complete');
    Route::patch('/{asset}/retire',              [AssetController::class, 'retire'])
        ->middleware('permission:assets.manage')->name('retire');
    Route::patch('/{asset}/lost',                [AssetController::class, 'markLost'])
        ->middleware('permission:assets.manage')->name('lost');
});
```

### Inertia pages (`resources/js/Pages/Assets/`)

Replace existing skeleton:
- `Index.vue` — filterable table; columns: tag, name, category, status, currently-assigned, location. Filters: category, status, search. Add-asset slide-panel. Bulk actions stub.
- `Show.vue` — asset detail (left column) + tabbed right column: Assignment history · Maintenance log · Depreciation snapshots · Audit trail.
- `My.vue` — employee's currently-assigned assets, with "Return" request affordance (creates an audit-trail entry but the actual return is done by HR/IT confirming the physical receipt).

### Scheduled job

- `App\Jobs\RegenerateAssetDepreciation` — runs first of each month. For every non-retired asset, computes a snapshot.

### Permissions
- `assets.view` → all roles including employee.
- `assets.manage` → hr_admin, it_support, super_admin.
- `assets.assign` → manager, dept_head, hr_admin, it_support, super_admin.

### Tests (`tests/Feature/AssetsTest.php`)
- `it registers an asset and lists it`
- `it assigns an asset and marks status=assigned`
- `it prevents double-assignment of an asset already assigned`
- `it returns an asset and resets status=in_stock`
- `it opens a maintenance row when condition_on_return=damaged`
- `it computes a depreciation snapshot correctly for a laptop`
- `it forbids an employee from registering an asset (RBAC deny)`

---

## 10. Phase 4 — Benefits

### Domain model

**Enums:**
- `BenefitType`: `health_insurance`, `provident_fund`, `life_insurance`, `dental`, `vision`, `wellness`, `other`
- `EnrolmentStatus`: `active`, `suspended`, `terminated`
- `DependantRelationship`: `spouse`, `child`, `parent`, `other`
- `ClaimStatus`: `submitted`, `reviewing`, `approved`, `rejected`, `paid`

**Tables:**

```
benefit_plans
  id, name (string 120), code (string 40 unique),
  type (enum), provider (string 120 nullable),
  description (text nullable),
  monthly_cost (decimal 10,2),
  employee_contribution_percentage (decimal 5,2 default 0),
  is_active (bool default true),
  effective_from (date), effective_to (date nullable),
  max_dependants (integer default 0),
  cover_details (json nullable),
  softDeletes, timestamps

benefit_enrolments
  id, plan_id (FK), employee_id (FK),
  enrolled_at (date), effective_from (date), effective_to (date nullable),
  status (enum default 'active'),
  monthly_premium (decimal 10,2),
  notes (text nullable),
  softDeletes, timestamps
  UNIQUE (plan_id, employee_id, effective_from)

dependants
  id, employee_id (FK), full_name (string 120),
  relationship (enum), date_of_birth (date),
  national_id (string 32 nullable), gender (string 16 nullable),
  is_covered (bool default true),
  softDeletes, timestamps

benefit_claims
  id, enrolment_id (FK), claim_reference (string 20 unique),
  amount (decimal 12,2), currency (char 3 default 'GHS'),
  claim_date (date), description (text),
  status (enum default 'submitted'),
  submitted_at (datetime), decision_at (datetime nullable),
  decision_notes (text nullable),
  decided_by (FK users nullable),
  softDeletes, timestamps
```

### Service — `App\Services\BenefitsService`

- `createPlan(array $data): BenefitPlan`
- `enrol(BenefitPlan $plan, Employee $employee, \DateTimeInterface $effectiveFrom, ?float $premium = null): BenefitEnrolment` — premium defaults to `plan.monthly_cost × (1 - employer share)` or admin-overridable.
- `addDependant(Employee $employee, array $data): Dependant` — validates count against active enrolments' max_dependants.
- `submitClaim(BenefitEnrolment $enrolment, array $data): BenefitClaim` — generates `CLM-XXXXXXXX` reference, emits `BenefitClaimSubmitted`.
- `decideClaim(BenefitClaim $claim, ClaimStatus $status, User $decider, ?string $notes = null): BenefitClaim` — only legal transitions allowed.
- `generateECardPdf(BenefitEnrolment $enrolment): string` — returns binary PDF using `barryvdh/laravel-dompdf` (added to `composer.json` if not present).
- `providentFundView(Employee $employee): array` — derived: for each provident-type active enrolment, `['plan' => …, 'total_contributed' => premium × months_active, 'months_active' => …]`.

### Events

`BenefitPlanCreated`, `BenefitEnroled`, `DependantAdded`, `BenefitClaimSubmitted`, `BenefitClaimDecided`.

### Composer dependency
Add `barryvdh/laravel-dompdf: ^3.0` to `composer.json` `require` if not already present. Publish config: `php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"`.

### Policies — `App\Policies\BenefitsPolicy`

- `view` plan/enrolment/claim — owner OR `benefits.view_all`.
- `manage` plans + claim decisions — `benefits.manage`.
- `enrol` — `benefits.enrol` AND self.
- `submitClaim` — `benefits.claim` AND owner of enrolment.

### FormRequests
- `StorePlanRequest`, `UpdatePlanRequest`
- `EnrolRequest`
- `StoreDependantRequest`, `UpdateDependantRequest`
- `SubmitClaimRequest`, `DecideClaimRequest`

### Routes

```php
Route::prefix('benefits')->name('benefits.')->middleware(['auth', 'audit'])->group(function () {
    Route::get('/',                       [BenefitsController::class, 'index'])->name('index');

    Route::prefix('plans')->name('plans.')->middleware('permission:benefits.manage')->group(function () {
        Route::get('/',                   [BenefitsController::class, 'plansIndex'])->name('index');
        Route::post('/',                  [BenefitsController::class, 'storePlan'])->name('store');
        Route::patch('/{plan}',           [BenefitsController::class, 'updatePlan'])->name('update');
        Route::delete('/{plan}',          [BenefitsController::class, 'destroyPlan'])->name('destroy');
    });

    Route::post('/enrol',                 [BenefitsController::class, 'enrol'])
        ->middleware('permission:benefits.enrol')->name('enrol');

    Route::prefix('dependants')->name('dependants.')->group(function () {
        Route::post('/',                  [BenefitsController::class, 'storeDependant'])->name('store');
        Route::patch('/{dependant}',      [BenefitsController::class, 'updateDependant'])->name('update');
        Route::delete('/{dependant}',     [BenefitsController::class, 'destroyDependant'])->name('destroy');
    });

    Route::prefix('claims')->name('claims.')->group(function () {
        Route::get('/',                   [BenefitsController::class, 'claimsIndex'])->name('index');
        Route::post('/',                  [BenefitsController::class, 'submitClaim'])
            ->middleware('permission:benefits.claim')->name('store');
        Route::patch('/{claim}/decide',   [BenefitsController::class, 'decideClaim'])
            ->middleware('permission:benefits.manage')->name('decide');
    });

    Route::get('/enrolments/{enrolment}/e-card', [BenefitsController::class, 'downloadECard'])->name('e-card');
});
```

### Inertia pages (`resources/js/Pages/Benefits/`)

Replace existing skeleton:
- `Index.vue` — employee dashboard. Sections: My enrolments · My dependants · My claims (with `StatusBadge`). "Enrol in a plan" CTA opens a slide-panel listing available plans.
- `Plans.vue` — HR plan editor: table with inline-edit; slide-panel for new plans.
- `Claims.vue` — HR claims queue; kanban or table with quick-decide actions.
- E-card route is a download action — no dedicated page.

### Permissions
- `benefits.view` → all (own).
- `benefits.view_all` → hr_admin, super_admin.
- `benefits.manage` → hr_admin, super_admin.
- `benefits.enrol` → employee, manager, dept_head, hr_admin, super_admin.
- `benefits.claim` → employee, manager, dept_head, hr_admin, super_admin.

### Tests (`tests/Feature/BenefitsTest.php`)
- `it creates a plan and lists it`
- `it enrols an employee in a plan`
- `it prevents adding more dependants than the plan allows`
- `it auto-generates a CLM- reference on claim submit`
- `it transitions a claim through submitted → reviewing → approved → paid`
- `it rejects an illegal status transition`
- `it generates an e-card PDF for an active enrolment`
- `it forbids a non-HR user from creating a plan`

---

## 11. Phase 5 — Governance

### Domain model

**Enums:**
- `PolicyCategory`: `hr`, `finance`, `it`, `compliance`, `safety`, `conduct`, `other`
- `CertificationStatus` (derived, not stored): `valid`, `expiring_soon`, `expired`

**Tables:**

```
policies
  id, title (string 200), slug (string 200 unique),
  category (enum), summary (text nullable),
  owner_user_id (FK users), is_active (bool default true),
  current_version_id (FK policy_versions nullable),
  softDeletes, timestamps

policy_versions
  id, policy_id (FK), version_number (integer),
  body (longText),
  effective_from (date nullable), effective_to (date nullable),
  changelog (text nullable),
  published_by (FK users nullable), published_at (datetime nullable),
  timestamps
  UNIQUE (policy_id, version_number)

policy_acknowledgements
  id, policy_version_id (FK), user_id (FK),
  acknowledged_at (datetime),
  ip_address (string 45), user_agent (text),
  signed_full_name (string 120),
  timestamps
  UNIQUE (policy_version_id, user_id)

certifications
  id, employee_id (FK), name (string 200),
  issuer (string 200 nullable),
  issued_at (date), expires_at (date),
  certificate_url (string 500 nullable),
  reminder_sent_at (datetime nullable),
  softDeletes, timestamps
  INDEX (expires_at)
```

### Service — `App\Services\GovernanceService`

- `createPolicy(User $owner, array $data): Policy` — also creates first PolicyVersion (draft, not published).
- `addVersion(Policy $policy, User $author, string $body, ?string $changelog = null): PolicyVersion` — increments version_number.
- `publish(PolicyVersion $version, User $publisher, \DateTimeInterface $effectiveFrom): PolicyVersion` — stamps published_at, sets policy.current_version_id, stamps previous version's effective_to, emits `PolicyPublished`.
- `acknowledge(PolicyVersion $version, User $user, string $signedFullName, string $ip, string $userAgent): PolicyAcknowledgement` — idempotent (returns existing if user already acked this version).
- `pendingAcksFor(User $user): Collection` — every active policy whose current_version has no ack from the user.
- `recordCertification(Employee $employee, array $data): Certification`
- `dispatchExpiryReminders(int $daysAhead = 30): int` — invoked by daily scheduled command; for each certification expiring within window with `reminder_sent_at IS NULL`, emit `CertificationExpiring` event; stamps `reminder_sent_at = now()`.

### Events

`PolicyDrafted`, `PolicyVersionAdded`, `PolicyPublished`, `PolicyAcknowledged`, `CertificationExpiring`, `CertificationExpired`. Each routed through `RecordAnalyticsEvent`; `CertificationExpiring` additionally routed to `SendNotifications`.

### Policies — `App\Policies\GovernancePolicy`

- `viewAny / view` — `governance.view` (all employees).
- `manage` — `governance.manage`.
- `acknowledge` — `governance.acknowledge` + self.

### FormRequests
- `StorePolicyRequest`, `UpdatePolicyRequest`
- `StorePolicyVersionRequest`, `PublishPolicyVersionRequest`
- `AcknowledgePolicyRequest` — requires `signed_full_name` to match `auth()->user()->name` (case-insensitive trim).
- `StoreCertificationRequest`, `UpdateCertificationRequest`

### Routes

```php
Route::prefix('governance')->name('governance.')->middleware(['auth', 'audit'])->group(function () {
    Route::get('/',                            [GovernanceController::class, 'index'])
        ->middleware('permission:governance.view')->name('index');
    Route::get('/policies/{policy}',           [GovernanceController::class, 'showPolicy'])
        ->middleware('permission:governance.view')->name('policies.show');
    Route::post('/policies',                   [GovernanceController::class, 'storePolicy'])
        ->middleware('permission:governance.manage')->name('policies.store');
    Route::patch('/policies/{policy}',         [GovernanceController::class, 'updatePolicy'])
        ->middleware('permission:governance.manage')->name('policies.update');
    Route::post('/policies/{policy}/versions', [GovernanceController::class, 'addVersion'])
        ->middleware('permission:governance.manage')->name('policies.versions.store');
    Route::patch('/versions/{version}/publish',[GovernanceController::class, 'publishVersion'])
        ->middleware('permission:governance.manage')->name('versions.publish');
    Route::post('/versions/{version}/ack',     [GovernanceController::class, 'acknowledge'])
        ->middleware('permission:governance.acknowledge')->name('versions.ack');

    Route::prefix('certifications')->name('certifications.')->group(function () {
        Route::get('/',                        [GovernanceController::class, 'certificationsIndex'])->name('index');
        Route::post('/',                       [GovernanceController::class, 'storeCertification'])
            ->middleware('permission:governance.cert_manage')->name('store');
        Route::patch('/{certification}',       [GovernanceController::class, 'updateCertification'])
            ->middleware('permission:governance.cert_manage')->name('update');
        Route::delete('/{certification}',      [GovernanceController::class, 'destroyCertification'])
            ->middleware('permission:governance.cert_manage')->name('destroy');
        Route::post('/dispatch-reminders',     [GovernanceController::class, 'dispatchReminders'])
            ->middleware('permission:governance.cert_manage')->name('dispatch-reminders');
    });
});
```

### Inertia pages (`resources/js/Pages/Governance/`)

Replace existing skeleton:
- `Index.vue` — Policy directory. Cards per policy with `[ACK-required]` badge if user hasn't acked current version.
- `Show.vue` — Policy detail (markdown rendered with `marked` or `dompurify` + a markdown library), versions sidebar, big "Acknowledge" CTA with typed-name input.
- `Manage.vue` — HR editor: list of policies, version timeline, "Add new version" affordance, "Publish" affordance (with effective-from date picker).
- `Certifications.vue` — Tracker: table of all certifications (or employee-scoped for non-HR), filter by expiry window, bulk "Send reminders now" action.

### Scheduled command

- `App\Console\Commands\DispatchCertificationReminders` — daily at 08:00. Calls `GovernanceService::dispatchExpiryReminders(30)`.

### Permissions
- `governance.view` → all employees.
- `governance.manage` → hr_admin, super_admin.
- `governance.acknowledge` → all employees (self-only).
- `governance.cert_manage` → hr_admin, super_admin.

### Tests (`tests/Feature/GovernanceTest.php`)
- `it creates a policy with a v1 draft version`
- `it publishes a version and stamps current_version_id`
- `it requires re-acknowledgement when a new version is published`
- `it validates signed_full_name matches the user's name`
- `it stamps IP and user agent on acknowledgement`
- `it dispatches expiring-certification reminders and stamps reminder_sent_at`
- `it forbids a non-HR user from publishing a version (RBAC deny)`

---

## 12. Phase 6 — Production hardening

### Tasks

| Area | Action |
|---|---|
| **PHP 8.5 / pao unblock** | Try in order: (a) bump `laravel/pao` to ≥ a version that handles `stream_filter_remove()` cleanly; (b) if no such version exists, pin to a working older version; (c) last resort, add a `@` suppression patch via `cweagans/composer-patches`. Documented in `docs/PROJECT_STATE.md`. |
| **Queue driver** | Switch `QUEUE_CONNECTION` from `sync` to `database`. Run `php artisan queue:table && php artisan migrate`. Document supervisor unit files in `deploy/supervisor/` for the `audit` and `analytics` queues separately. |
| **Rate limiting** | Apply `throttle:5,1` to `POST /careers/{job}/apply` and `POST /complaints` (public submission). Apply `throttle:10,1` to `POST /attendance/clock-in` and `clock-out` to defeat clock-spam. |
| **Password must change** | New column `users.password_must_change` (bool default false). Seeder sets it to true for all seeded users. New middleware `ForcePasswordChange` redirects to `/profile#security` until the user submits a new password via `profile.password`. |
| **Cookies** | `config/session.php`: `secure => true`, `same_site => 'lax'`, `http_only => true`. `APP_URL` must be HTTPS in production `.env`. |
| **Sentry** | Add `sentry/sentry-laravel`. Configure a `sentry` channel in `config/logging.php`. Default error reporter wraps it. |
| **Backups** | Add `spatie/laravel-backup`. Config in `config/backup.php`: db + `storage/app/`. Scheduled command runs daily at 02:00. Document S3 destination in `.env.example`. |
| **Public docs** | Update `README.md` setup section; update `docs/PROJECT_STATE.md` to reflect new modules. |

### Tests (`tests/Feature/HardeningTest.php`)
- `it throttles careers apply after 5 requests in a minute`
- `it redirects an authenticated user with password_must_change to profile.security`
- `it allows the user past the gate after a successful password update`

---

## 13. Permission catalog — final additions

Summary of every new permission slug added across P2–P5 to `RolePermissionSeeder`:

```
attendance.view             — employee, manager, dept_head, hr_admin, super_admin
attendance.view_all         — manager, dept_head, hr_admin, super_admin
attendance.manage           — hr_admin, super_admin
attendance.approve          — manager, dept_head, hr_admin, super_admin
attendance.correct          — all (own)

assets.view                 — all
assets.manage               — hr_admin, it_support, super_admin
assets.assign               — manager, dept_head, hr_admin, it_support, super_admin

benefits.view               — all (own)
benefits.view_all           — hr_admin, super_admin
benefits.manage             — hr_admin, super_admin
benefits.enrol              — all (own)
benefits.claim              — all (own)

governance.view             — all
governance.manage           — hr_admin, super_admin
governance.acknowledge      — all (self)
governance.cert_manage      — hr_admin, super_admin
```

super_admin's legacy `*` wildcard still covers everything; only the explicit DB pivots are added per the seeder pattern.

---

## 14. Testing strategy

| Layer | What's tested |
|---|---|
| **Pest feature tests per module** | Happy path + RBAC deny + critical side-effect (event, status transition, scheduled-command outcome). |
| **Policy tests** | Extend existing `PoliciesTest.php` with cases for AttendancePolicy, AssetPolicy, BenefitsPolicy, GovernancePolicy deny paths. |
| **Scheduled commands** | Tested by directly invoking the command class with `Carbon::setTestNow()` and asserting DB side-effects. |
| **CI** | PHP 8.4 only (sidesteps the pao blocker until P6 resolves it). Pest + Vite build. |
| **Manual smoke** | Each phase ends with a written manual-smoke checklist: every new button clicked, every new RBAC gate exercised, in `docs/qa/<phase>.md`. |

---

## 15. Risks & open items

1. **PHP 8.5/pao blocker** — if no clean fix exists, P6 falls back to a `@`-suppression patch. Spec assumes a fix is found.
2. **Composer dependency additions** — Benefits adds `barryvdh/laravel-dompdf`; Hardening adds `sentry/sentry-laravel` and `spatie/laravel-backup`. All three are well-known, stable, MIT-licensed.
3. **Dashboard.vue trim is destructive** — duplicated module sections are deleted, not feature-flagged. Recoverable from git history once P0 is done. Must not begin P1 before P0.
4. **Test execution gap** — until P6 unblocks PHP 8.5, tests are syntactically valid but only run in CI on PHP 8.4. Local devs on Windows must downgrade PHP or accept CI as authoritative.
5. **Scheduled commands need a scheduler** — for the attendance "mark absent" and "depreciation snapshot" and "cert reminders" crons to actually run, `php artisan schedule:work` (dev) or a cron entry `* * * * * php artisan schedule:run` (prod) is required. Documented in P6 README updates.

---

## 16. Out of scope (revisit later)

- Real biometric hardware adapter (only the `source=biometric` enum slot is reserved).
- Real insurer API for Benefits.
- DocuSign / Adobe Sign for policy acknowledgement.
- Asset depreciation methods beyond straight-line (reducing-balance, double-declining).
- Multi-currency for Assets/Benefits (currency column exists but UI assumes GHS).
- Mobile-specific clock-in app (web endpoint is mobile-ready).
- WebSocket-driven live attendance dashboard.

---

## 17. Acceptance criteria

The spec is delivered when, in the running application:

1. Repo is under git with a green CI run on `main`.
2. No `comingSoon` toast call site exists anywhere in `resources/js/`.
3. Every sidebar link routes to a page that performs real CRUD against a real backend.
4. RBAC deny paths are enforced server-side (policy tests pass).
5. All four new modules expose:
   - At least one list page (Index) with filters.
   - At least one detail page (Show) where applicable.
   - At least one workflow action that emits a domain event.
   - At least one scheduled command tied to a real cron.
6. Production hardening checklist (queue, rate limit, password-must-change, cookies, Sentry, backups) is in place and documented.
7. `docs/PROJECT_STATE.md` is updated to reflect the new state.
