# P0 + P1 — Foundation & Dashboard Glue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the repository under git with a green CI pipeline, then trim the monolithic `Dashboard.vue` (delete ~1000 LOC of duplicated module sections, replace decorative sparkline literals with real time-series, and rewire the six `comingSoon` buttons whose backends already exist).

**Architecture:**
- **P0:** `git init`, `.gitignore`, single initial commit, then a GitHub Actions workflow that runs Pest + Vite build on PHP 8.4 (sidesteps the local PHP 8.5 / `laravel/pao` blocker).
- **P1:** Add `DashboardService::timeSeries(string $metric, int $days = 30): array` that aggregates `analytics_events` by day, cached 60 s. `DashboardController::index()` passes time-series for every sparkline. `Dashboard.vue` consumes them, drops literals, deletes duplicated module v-if blocks (Assets / Benefits / Learning / Governance / inline forms), and rewires `comingSoon` buttons to `router.visit()` calls.

**Tech Stack:** Laravel 13.7 (PHP 8.3), Vue 3, Inertia v2, Tailwind v3, SQLite (dev), Pest 4, GitHub Actions, Vite 8.

**Reference spec:** [docs/superpowers/specs/2026-05-15-cihrms-end-to-end-wiring-design.md](../specs/2026-05-15-cihrms-end-to-end-wiring-design.md)

---

## File map

### P0 — Created
- `d:/CIHRMS/cihrms-mvp/.gitignore` (replaces any existing — currently no file)
- `d:/CIHRMS/cihrms-mvp/.github/workflows/ci.yml`

### P1 — Created
- `d:/CIHRMS/cihrms-mvp/tests/Feature/DashboardTimeSeriesTest.php`

### P1 — Modified
- `d:/CIHRMS/cihrms-mvp/app/Services/DashboardService.php` — adds `timeSeries()`, `getRecentActivityFeed()`.
- `d:/CIHRMS/cihrms-mvp/app/Http/Controllers/DashboardController.php` — passes new props.
- `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue` — large surgery: delete duplicated module sections, replace literals with reactive props, rewire `comingSoon` buttons.
- `d:/CIHRMS/cihrms-mvp/resources/js/composables/useToast.js` — eventually remove `comingSoon` helper (deferred to end of P5; not in this plan).

---

## P0 — Version control + CI

### Task 1: Verify pre-conditions and prepare baseline

**Files:** none (read-only)

- [ ] **Step 1: Confirm no existing `.git/` directory in the project root**

Run from `d:/CIHRMS/cihrms-mvp`:
```powershell
Test-Path .git
```
Expected: `False`. If `True`, the repo is already initialised — skip to Task 2 after verifying `git log --oneline` shows a clean history.

- [ ] **Step 2: Confirm `composer dev` runs (sanity check before committing the snapshot)**

Run:
```powershell
php artisan migrate:status
```
Expected: a list of migrations all marked `Yes`. If migrations are pending, run `php artisan migrate --seed` first so the initial commit is in a known-working state.

- [ ] **Step 3: Confirm `composer.json` already declares `barryvdh/laravel-dompdf` and `laravel/pao`**

Run:
```powershell
Select-String -Path composer.json -Pattern 'barryvdh/laravel-dompdf|laravel/pao'
```
Expected: both lines present. These are referenced later (`laravel/pao` is the PHP 8.5 blocker; `dompdf` is used in P4 Benefits e-card).

---

### Task 2: Initialize git and write `.gitignore`

**Files:**
- Create: `d:/CIHRMS/cihrms-mvp/.gitignore`

- [ ] **Step 1: Run `git init` on `main`**

```powershell
git init --initial-branch=main
```
Expected output: `Initialized empty Git repository in d:/CIHRMS/cihrms-mvp/.git/`.

- [ ] **Step 2: Write the `.gitignore` file**

Create `d:/CIHRMS/cihrms-mvp/.gitignore` with this exact content:

```gitignore
# Composer
/vendor/
composer.phar

# Node
/node_modules/
npm-debug.log
yarn-error.log

# Vite / built assets
/public/build/
/public/hot
/public/storage

# Environment
.env
.env.backup
.env.production
.env.*.local
.env.testing.local

# Storage runtime
/storage/*.key
/storage/app/public/*
!/storage/app/public/.gitkeep
/storage/framework/cache/*
!/storage/framework/cache/.gitkeep
/storage/framework/sessions/*
!/storage/framework/sessions/.gitkeep
/storage/framework/testing/*
!/storage/framework/testing/.gitkeep
/storage/framework/views/*
!/storage/framework/views/.gitkeep
/storage/logs/*.log

# Local SQLite
/database/database.sqlite
/database/database.sqlite-journal

# IDE / OS
.idea/
.vscode/
*.swp
*.swo
.DS_Store
Thumbs.db

# Auth / Phpunit
auth.json
.phpunit.result.cache
.phpunit.cache/

# Laravel
Homestead.json
Homestead.yaml
```

- [ ] **Step 3: Re-create the `.gitkeep` files referenced by the `.gitignore`**

These directories must exist but their contents must not be tracked. Run:
```powershell
@('storage/app/public', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/testing', 'storage/framework/views') | ForEach-Object {
    if (-not (Test-Path "$_/.gitkeep")) { New-Item -ItemType File -Path "$_/.gitkeep" -Force | Out-Null }
}
```

Expected: silent. Verify with `Test-Path storage/framework/cache/.gitkeep` → `True`.

- [ ] **Step 4: Stage everything and review what will be committed**

```powershell
git add .
git status --short
```
Expected: a long list of `A` (added) lines covering source, configs, migrations, Vue pages — **no** `.env`, no `vendor/`, no `node_modules/`, no `database/database.sqlite`. If any of those appear, the `.gitignore` is wrong — fix before committing.

- [ ] **Step 5: Make the initial commit**

```powershell
git commit -m "chore: initial commit of CIHRMS MVP at 2026-05-19 snapshot"
```
Expected output ends with `[main (root-commit) <sha>] chore: initial commit …` and a file count in the hundreds.

- [ ] **Step 6: Verify the commit**

```powershell
git log --oneline
git status
```
Expected: one commit; status reports `nothing to commit, working tree clean`.

---

### Task 3: Add GitHub Actions CI workflow

**Files:**
- Create: `d:/CIHRMS/cihrms-mvp/.github/workflows/ci.yml`

- [ ] **Step 1: Create the workflow file**

Create `d:/CIHRMS/cihrms-mvp/.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    env:
      DB_CONNECTION: sqlite
      DB_DATABASE: database/database.sqlite

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP 8.4
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: bcmath, mbstring, pdo, pdo_sqlite, sqlite3, gd, intl
          coverage: none
          tools: composer:v2

      - name: Setup Node 22
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'

      - name: Cache composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-

      - name: Install PHP deps
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Install Node deps
        run: npm ci

      - name: Prepare env
        run: |
          cp .env.example .env
          php artisan key:generate
          touch database/database.sqlite

      - name: Run migrations + seed
        run: php artisan migrate --seed --force

      - name: Build frontend
        run: npm run build

      - name: Run Pest
        run: vendor/bin/pest --colors=always
```

- [ ] **Step 2: Verify `.env.example` exists and is sane**

```powershell
Test-Path .env.example
Select-String -Path .env.example -Pattern '^DB_CONNECTION='
```
Expected: `True`, and the line shows the DB connection. If `DB_CONNECTION` is missing or not `sqlite`, add `DB_CONNECTION=sqlite` to `.env.example` before committing — CI will fail otherwise.

- [ ] **Step 3: Commit the CI workflow**

```powershell
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions workflow running Pest on PHP 8.4"
```

- [ ] **Step 4: Verify the commit**

```powershell
git log --oneline
```
Expected: two commits — initial + ci.

---

### Task 4: Push to remote (open question deferred to user)

**Files:** none

- [ ] **Step 1: Ask the user which remote to use**

Prompt the user: *"Which git remote? (GitHub, GitLab, Bitbucket, or self-hosted URL.)"* Once a URL is provided:

```powershell
git remote add origin <url>
git push -u origin main
```
Expected: successful push; CI workflow appears and runs. If CI fails, fix forward — common failures: missing `.env.example` keys, mismatched PHP version, `pao` extension issue (try `composer require --dev laravel/pao:^1.0` to pin the working version).

- [ ] **Step 2: Verify CI is green**

Open the remote URL in browser. Expected: green checkmark on the latest commit. If red, fix before moving on.

---

## P1 — Glue + Dashboard.vue trim

P1 produces three concrete outputs:

1. New `DashboardService::timeSeries()` + `getRecentActivityFeed()` methods with a Pest feature test.
2. `DashboardController` passes the new props.
3. `Dashboard.vue` is surgically trimmed: literals → reactive props, duplicated module sections deleted, `comingSoon` buttons rewired.

### Task 5: Write the failing test for `DashboardService::timeSeries`

**Files:**
- Create: `d:/CIHRMS/cihrms-mvp/tests/Feature/DashboardTimeSeriesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardTimeSeriesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Carbon::setTestNow('2026-05-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns a 30-day time-series with zero-filled missing days', function () {
    $user = User::factory()->create();

    AnalyticsEvent::factory()->create([
        'event_type' => 'employee.created',
        'created_at' => now()->subDays(2),
    ]);
    AnalyticsEvent::factory()->create([
        'event_type' => 'employee.created',
        'created_at' => now()->subDays(2),
    ]);
    AnalyticsEvent::factory()->create([
        'event_type' => 'employee.created',
        'created_at' => now()->subDays(10),
    ]);

    $series = app(DashboardService::class)->timeSeries('employees', 30);

    expect($series)->toBeArray()->toHaveCount(30);

    $byDate = collect($series)->keyBy('date');

    expect($byDate->get(now()->subDays(2)->toDateString())['value'])->toBe(2);
    expect($byDate->get(now()->subDays(10)->toDateString())['value'])->toBe(1);
    expect($byDate->get(now()->subDays(5)->toDateString())['value'])->toBe(0);
});

it('caches the time-series for 60 seconds per metric', function () {
    AnalyticsEvent::factory()->create([
        'event_type' => 'ticket.created',
        'created_at' => now()->subDay(),
    ]);

    $first = app(DashboardService::class)->timeSeries('open_tickets', 7);

    AnalyticsEvent::factory()->create([
        'event_type' => 'ticket.created',
        'created_at' => now()->subDay(),
    ]);

    $second = app(DashboardService::class)->timeSeries('open_tickets', 7);

    expect($second)->toBe($first);
});

it('throws on unsupported metric', function () {
    expect(fn () => app(DashboardService::class)->timeSeries('not_a_metric', 30))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```powershell
vendor/bin/pest --filter=DashboardTimeSeriesTest
```
Expected: 3 failures, all complaining that `DashboardService::timeSeries` is undefined OR returns null. (If PHP 8.5/pao blocks execution locally, this is expected — proceed to Step 3 and rely on CI verification.)

- [ ] **Step 3: Verify `AnalyticsEvent` factory exists**

```powershell
Test-Path database/factories/AnalyticsEventFactory.php
```
Expected: `True`. If `False`, create it before running the test:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnalyticsEventFactory extends Factory
{
    protected $model = AnalyticsEvent::class;

    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'event_type' => 'employee.created',
            'payload'    => [],
        ];
    }
}
```

Re-run Step 2.

---

### Task 6: Implement `DashboardService::timeSeries` (minimum to pass)

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/app/Services/DashboardService.php`

- [ ] **Step 1: Add the metric-to-event-types map and the `timeSeries` method**

Open `app/Services/DashboardService.php` and add the following at the bottom of the class (just before the closing `}`). Also add the `InvalidArgumentException` and `Carbon` imports at the top.

Imports to add at top (after the existing `use` statements):

```php
use Illuminate\Support\Carbon;
use InvalidArgumentException;
```

Add inside the class:

```php
private const METRIC_EVENT_TYPES = [
    'employees'        => ['employee.created'],
    'open_tickets'     => ['ticket.created'],
    'pending_leave'    => ['leave.requested'],
    'pending_payments' => ['payment.created'],
    'payslips_paid'    => ['payment.paid', 'payslip.generated'],
    'applicants'       => ['recruitment.applicant.created'],
];

public function timeSeries(string $metric, int $days = 30): array
{
    if (! isset(self::METRIC_EVENT_TYPES[$metric])) {
        throw new InvalidArgumentException("Unsupported metric: {$metric}");
    }

    return Cache::remember(
        "dashboard.timeseries.{$metric}.{$days}",
        self::STATS_TTL,
        fn () => $this->buildSeries($metric, $days)
    );
}

private function buildSeries(string $metric, int $days): array
{
    $eventTypes = self::METRIC_EVENT_TYPES[$metric];
    $from = Carbon::today()->subDays($days - 1);

    $isSqlite = DB::connection()->getDriverName() === 'sqlite';
    $dateExpr = $isSqlite
        ? "DATE(created_at)"
        : "DATE(created_at)";

    $rows = AnalyticsEvent::query()
        ->selectRaw("{$dateExpr} as event_date, COUNT(*) as total")
        ->whereIn('event_type', $eventTypes)
        ->where('created_at', '>=', $from)
        ->groupBy('event_date')
        ->pluck('total', 'event_date');

    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $date = $from->copy()->addDays($i)->toDateString();
        $series[] = ['date' => $date, 'value' => (int) ($rows[$date] ?? 0)];
    }

    return $series;
}
```

- [ ] **Step 2: Run the test, confirm it passes**

```powershell
vendor/bin/pest --filter=DashboardTimeSeriesTest
```
Expected: 3 passes. (If pao-blocker prevents local execution, push to remote and rely on CI.)

- [ ] **Step 3: Commit**

```powershell
git add app/Services/DashboardService.php tests/Feature/DashboardTimeSeriesTest.php
git commit -m "feat(dashboard): add DashboardService::timeSeries with cached zero-fill"
```

---

### Task 7: Wire the new time-series props into `DashboardController`

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/app/Http/Controllers/DashboardController.php`

- [ ] **Step 1: Read the current controller to find the existing `index` action**

```powershell
Get-Content app/Http/Controllers/DashboardController.php
```

- [ ] **Step 2: Add `timeSeries` props for the five sparkline metrics**

In `DashboardController::index()`, locate the array passed to `Inertia::render('Dashboard', [...])`. Add five new keys alongside the existing `stats`, `recentEvents`, etc.:

```php
'sparkSeries' => [
    'employees'       => $this->dashboard->timeSeries('employees', 30),
    'tickets'         => $this->dashboard->timeSeries('open_tickets', 30),
    'leave'           => $this->dashboard->timeSeries('pending_leave', 30),
    'payroll'         => $this->dashboard->timeSeries('payslips_paid', 30),
    'applicants'      => $this->dashboard->timeSeries('applicants', 30),
],
```

If the controller currently uses `DashboardService` via property `$this->dashboard` keep that. If it uses a different injection name (e.g. `$this->service`), match the existing convention exactly.

- [ ] **Step 3: Verify the route still returns successfully**

```powershell
php artisan route:list --name=dashboard
php artisan inertia:start-ssr 2>$null  # optional warm-up
```

If the project has no SSR, skip the second line. Open the running app at `/dashboard`, view the network response in dev tools, confirm `sparkSeries` is present in the Inertia payload.

- [ ] **Step 4: Commit**

```powershell
git add app/Http/Controllers/DashboardController.php
git commit -m "feat(dashboard): pass real 30-day time-series for sparklines"
```

---

### Task 8: Replace `Dashboard.vue` sparkline literals with reactive props

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue:65-71`

- [ ] **Step 1: Add `sparkSeries` to the component's `defineProps`**

Open `resources/js/Pages/Dashboard.vue`. Find the `defineProps({…})` block (lines 17–26). Add a new key after `ticketTrend`:

```js
const props = defineProps({
    stats:           Object,
    recentEvents:    Array,
    employees:       Array,
    tickets:         Array,
    headcountByDept: { type: Array,  default: () => [] },
    leaveByMonth:    { type: Object, default: () => ({}) },
    ticketTrend:     { type: Object, default: () => ({}) },
    sparkSeries:     { type: Object, default: () => ({}) },   // ← NEW
    activeModule:    String,
});
```

- [ ] **Step 2: Replace the `sparkData` literal with a computed mapper**

Replace lines 65–71 (`const sparkData = ref({ … });`) with a `computed` that maps the new time-series to flat number arrays for the existing sparkline renderer:

```js
const sparkData = computed(() => {
    const toValues = (s) => Array.isArray(s) ? s.map(p => Number(p.value ?? 0)) : [];
    const compliance = [96, 97, 97.5, 98, 97.8, 98.2, 98, 98.4, 98.2, 98.6, 98.4, 98.2]; // no event type yet; kept literal
    return {
        employees:  toValues(props.sparkSeries.employees),
        tickets:    toValues(props.sparkSeries.tickets),
        leave:      toValues(props.sparkSeries.leave),
        compliance,
        payroll:    toValues(props.sparkSeries.payroll),
    };
});
```

- [ ] **Step 3: Smoke-test the dashboard in the browser**

Run the dev server:
```powershell
npm run dev
```
Open `http://localhost:8000/dashboard`. Expected: sparklines render as before (now with real or zero data — they should still draw, not error). If a console error fires (`toValues is not a function` etc.), re-read your edit.

- [ ] **Step 4: Commit**

```powershell
git add resources/js/Pages/Dashboard.vue
git commit -m "feat(dashboard): drive sparklines from real DashboardService time-series"
```

---

### Task 9: Rewire the six `comingSoon` buttons in `Dashboard.vue`

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue`

These buttons currently fire `comingSoon(...)` but the destination pages already exist. Replace each `@click` with a `router.visit(route(...))` call.

- [ ] **Step 1: Replace button at line 438 — "Profile actions menu"**

Find:
```html
<button @click="comingSoon('Profile actions menu')"
```
Replace `@click` with:
```html
<button @click="router.visit(route('profile.edit'))"
```

- [ ] **Step 2: Replace button at line 486 — "Performance review history"**

Find:
```html
<button @click="comingSoon('Performance review history')"
```
Replace with:
```html
<button @click="router.visit(route('performance.reviews.index'))"
```

- [ ] **Step 3: Replace button at line 765 — "Strategic OKR roadmap viewer"**

Find:
```html
<button @click="comingSoon('Strategic OKR roadmap viewer')"
```
Replace with:
```html
<button @click="router.visit(route('performance.goals.index'))"
```

- [ ] **Step 4: Replace button at line 2431 — "Announcements archive"**

Find:
```html
<button @click="comingSoon('Announcements archive')"
```
Replace with:
```html
<button @click="router.visit(route('notifications.index'))"
```

- [ ] **Step 5: Replace button at line 2526 — "Personal task tracker"**

Find:
```html
<button @click="comingSoon('Personal task tracker')"
```
Replace with:
```html
<button @click="router.visit(route('tickets.index', { assignee: 'me' }))"
```

(Note: the `tickets.index` controller already accepts filter params via the request — passing `assignee=me` will be honoured if the controller already supports it. If not, the link will just open the unfiltered ticket list, which is still better than a toast.)

- [ ] **Step 6: Replace button at line 2922 — "AI workforce report"**

Find:
```html
<button @click="comingSoon('AI workforce report')"
```
Replace with:
```html
<button @click="router.visit(route('reports.index'))"
```

- [ ] **Step 7: Smoke-test each replaced button in the browser**

With `npm run dev` running, visit `http://localhost:8000/dashboard` and click each rewired button. Expected: each navigates to the right page (Profile, Performance Reviews, Performance Goals, Notifications, Tickets, Reports). If any 404s or hits an RBAC denial, the route name is wrong — verify with `php artisan route:list --name=<name>`.

- [ ] **Step 8: Commit**

```powershell
git add resources/js/Pages/Dashboard.vue
git commit -m "feat(dashboard): rewire six comingSoon buttons to real module routes"
```

---

### Task 10: Delete the duplicated module sections from `Dashboard.vue`

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue`

This is the destructive part. We are deleting `v-if` sections that duplicate dedicated pages already shipped. The user has approved this in the spec (§7, P1).

- [ ] **Step 1: Find each duplicated section's boundaries**

Open `Dashboard.vue` and find each of these `v-if` blocks. The line numbers below are approximate (per the existing snapshot) — re-locate each by its `v-if="activeModule === '...'"` attribute. Each runs from a top-level wrapping `<div v-if="activeModule === '...">"` through its matching closing `</div>` (the next sibling `v-else-if` / end of template marks the end).

| Block | Marker | Reason for deletion |
|---|---|---|
| Assets | `v-if="activeModule === 'assets'"` (around line ~1290) | Duplicate of `Pages/Assets/Index.vue` |
| Benefits / Insurance / Provident | `v-if="activeModule === 'benefits'"` (around line ~1380) | Duplicate of `Pages/Benefits/Index.vue` |
| Learning | `v-if="activeModule === 'learning'"` (around line ~1450) | Duplicate of `Pages/Learning/Catalog.vue` + `MyLearning.vue` |
| Governance | `v-if="activeModule === 'governance'"` (around line ~1240) | Duplicate of `Pages/Governance/Index.vue` |

- [ ] **Step 2: Delete each block — one at a time, commit between**

For each block:

a. Open the file in an editor, jump to the opening `<div v-if="activeModule === 'assets'">`.
b. Identify the matching closing `</div>` by tracking nesting depth (or by the next sibling `v-else-if`).
c. Delete the entire range — including any leading section-header comment.
d. Save.
e. Run `npm run build` to confirm there are no Vue parser errors. If it errors, the closing tag was wrong — revert and re-identify.
f. Commit before moving to the next block.

Commit messages, one per block:
```powershell
git add resources/js/Pages/Dashboard.vue
git commit -m "refactor(dashboard): remove duplicated assets section (use Pages/Assets/Index)"
# repeat for benefits, learning, governance
```

- [ ] **Step 3: Final smoke test after all four deletions**

```powershell
npm run build
```
Expected: build succeeds, no Vue parser errors. Visit `/dashboard` for each previously affected `activeModule` URL — the sidebar should now route to the dedicated `Pages/<Module>/Index.vue` instead of the dashboard's v-if. (Routing already redirects via `/modules/<name>` from `routes/web.php:73-88`.)

---

### Task 11: Replace the four inline forms with quick-action cards

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue` (lines ~2980–3220)

The four inline forms (department, employee, leave, ticket — and the job form for jobs) duplicate the slide-panel forms on each module's dedicated page. Replace them with link-cards.

- [ ] **Step 1: Locate the forms and the surrounding `<dialog>` / modal wrappers**

Open `Dashboard.vue`. The forms are bound to:
- `departmentForm` (around line ~3045)
- `employeeForm` (around line ~2975)
- `leaveForm` (around line ~3090)
- `ticketForm` (around line ~3145)
- `jobForm` (around line ~3200)

Each is rendered inside a modal triggered by `showAddDeptModal` / `showAddEmployeeModal` / `showLeaveModal` / `showTicketModal` / `showJobModal` (declared lines 38–42).

- [ ] **Step 2: Delete the modal markup blocks**

Delete the entire `<dialog>` / `<div v-if="showAddEmployeeModal">` (etc.) blocks for all five modals.

- [ ] **Step 3: Delete the now-unused script state**

Delete these lines from the `<script setup>`:

```js
const showAddEmployeeModal = ref(false);
const showAddDeptModal = ref(false);
const showLeaveModal = ref(false);
const showTicketModal = ref(false);
const showJobModal = ref(false);

const departmentForm = useForm({ name: '', code: '', description: '' });
const employeeForm = useForm({ department_id: '', employee_no: '', position: '', hire_date: '', phone: '' });
const leaveForm = useForm({ employee_id: '', start_date: '', end_date: '', type: 'annual', reason: '' });
const ticketForm = useForm({ employee_id: '', title: '', description: '', priority: 'medium', due_at: '' });
const jobForm = useForm({ title: '', description: '', closes_at: '' });
```

- [ ] **Step 4: Find the buttons that previously opened these modals and rewire**

Buttons that previously triggered the modals (e.g. `@click="showAddEmployeeModal = true"`) become `router.visit()` calls to the appropriate module's create flow. Use `Ctrl-F` for each `showAddEmployeeModal`, `showAddDeptModal`, etc. and replace as follows:

| Old | New |
|---|---|
| `@click="showAddEmployeeModal = true"` | `@click="router.visit(route('employees.index', { new: 1 }))"` |
| `@click="showAddDeptModal = true"` | `@click="router.visit(route('departments.index'))"` |
| `@click="showLeaveModal = true"` | `@click="router.visit(route('leave.index', { new: 1 }))"` |
| `@click="showTicketModal = true"` | `@click="router.visit(route('tickets.index', { new: 1 }))"` |
| `@click="showJobModal = true"` | `@click="router.visit(route('jobs.index', { new: 1 }))"` |

(The `new: 1` query param is a hint for the destination page to auto-open its slide-panel. Each module's `Index.vue` should pick it up; if it doesn't yet, the user lands on the index — still strictly better than a duplicate form.)

- [ ] **Step 5: Verify**

```powershell
npm run build
```
Expected: build succeeds. Smoke-test in browser: click each quick-action button — each should navigate to its module page.

- [ ] **Step 6: Commit**

```powershell
git add resources/js/Pages/Dashboard.vue
git commit -m "refactor(dashboard): replace inline create forms with quick-action links to module pages"
```

---

### Task 12: Add a `getRecentActivityFeed()` method to replace `activityPool` literal

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/app/Services/DashboardService.php`
- Modify: `d:/CIHRMS/cihrms-mvp/app/Http/Controllers/DashboardController.php`
- Modify: `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue` (lines ~76–86)

- [ ] **Step 1: Write a failing test**

Add to `tests/Feature/DashboardTimeSeriesTest.php`:

```php
it('returns a recent activity feed shaped for the dashboard', function () {
    $user = User::factory()->create();
    AnalyticsEvent::factory()->create([
        'user_id'    => $user->id,
        'event_type' => 'leave.requested',
        'payload'    => ['days' => 5, 'type' => 'annual'],
        'created_at' => now()->subMinutes(5),
    ]);

    $feed = app(DashboardService::class)->getRecentActivityFeed(10);

    expect($feed)->toBeArray()->not->toBeEmpty();
    expect($feed[0])->toHaveKeys(['text', 'icon', 'color', 'time']);
});
```

Run:
```powershell
vendor/bin/pest --filter=DashboardTimeSeriesTest
```
Expected: 1 new failure (the others still pass).

- [ ] **Step 2: Implement `getRecentActivityFeed`**

In `app/Services/DashboardService.php`, add:

```php
private const EVENT_PRESENTATION = [
    'employee.created'           => ['icon' => 'person_add',    'color' => '#316bf3'],
    'leave.requested'            => ['icon' => 'calendar_today','color' => '#d97706'],
    'leave.status_updated'       => ['icon' => 'check_circle',  'color' => '#059669'],
    'ticket.created'             => ['icon' => 'support_agent', 'color' => '#dc2626'],
    'payment.created'            => ['icon' => 'payments',      'color' => '#059669'],
    'payment.paid'               => ['icon' => 'payments',      'color' => '#059669'],
    'payslip.generated'          => ['icon' => 'receipt_long',  'color' => '#0f766e'],
    'recruitment.applicant.created' => ['icon' => 'person_search', 'color' => '#7c3aed'],
];

public function getRecentActivityFeed(int $limit = 12): array
{
    return AnalyticsEvent::with('user:id,name')
        ->latest()
        ->limit($limit)
        ->get()
        ->map(function (AnalyticsEvent $e) {
            $preset = self::EVENT_PRESENTATION[$e->event_type] ?? ['icon' => 'history', 'color' => '#64748b'];
            return [
                'text'  => $this->describeEvent($e),
                'icon'  => $preset['icon'],
                'color' => $preset['color'],
                'time'  => $e->created_at?->diffForHumans() ?? '',
            ];
        })
        ->all();
}

private function describeEvent(AnalyticsEvent $e): string
{
    $who = $e->user?->name ?? 'System';
    return match ($e->event_type) {
        'employee.created'           => "New hire onboarded — {$who}",
        'leave.requested'            => "Leave requested — {$who}",
        'leave.status_updated'       => "Leave decision — {$who}",
        'ticket.created'             => "Service ticket opened — {$who}",
        'payment.created'            => "Payment record created",
        'payment.paid'               => "Payment marked paid",
        'payslip.generated'          => "Payslip generated",
        'recruitment.applicant.created' => "New applicant received",
        default                      => $e->event_type,
    };
}
```

- [ ] **Step 3: Re-run test, confirm pass**

```powershell
vendor/bin/pest --filter=DashboardTimeSeriesTest
```
Expected: 4 passes.

- [ ] **Step 4: Pass it from the controller**

In `DashboardController::index()`, add another key to the Inertia render array (alongside `sparkSeries`):

```php
'activityFeed' => $this->dashboard->getRecentActivityFeed(12),
```

- [ ] **Step 5: Replace `activityPool` literal in `Dashboard.vue`**

Add `activityFeed` to `defineProps`:

```js
activityFeed:    { type: Array, default: () => [] },
```

Replace lines ~76–86 (`const activityPool = [...];`) with:

```js
const activityPool = computed(() => {
    return props.activityFeed.length > 0
        ? props.activityFeed
        : [{ text: 'No recent activity yet.', icon: 'history', color: '#64748b', time: '' }];
});
```

Anywhere later in the template that reads `activityPool[feedIdx.value]` etc. continues to work because `computed` returns an array-like. If any code does `activityPool.push(...)`, those mutations must be removed (the array is read-only now).

- [ ] **Step 6: Verify in browser**

`npm run dev`, open `/dashboard`. The feed shows real recent events, or the empty state.

- [ ] **Step 7: Commit**

```powershell
git add app/Services/DashboardService.php app/Http/Controllers/DashboardController.php resources/js/Pages/Dashboard.vue tests/Feature/DashboardTimeSeriesTest.php
git commit -m "feat(dashboard): real recent-activity feed from analytics_events"
```

---

### Task 13: Final pass — confirm zero `comingSoon` calls remain in `Dashboard.vue`

**Files:**
- Verify: `d:/CIHRMS/cihrms-mvp/resources/js/Pages/Dashboard.vue`

- [ ] **Step 1: Grep for any remaining `comingSoon` in Dashboard.vue**

```powershell
Select-String -Path resources/js/Pages/Dashboard.vue -Pattern 'comingSoon'
```
Expected: **no matches**.

If any matches remain, they fall into one of three categories:
- **Backend exists, routing not done in plan** — add a `router.visit(route('...'))` replacement.
- **Belongs to a new module (Attendance/Assets/Benefits/Governance)** — leave it. P2–P5 will handle it.
- **Genuinely no destination yet** — extremely unlikely after the deletions in Tasks 10–11, but if found, route it to its module's static skeleton page (e.g. `route('modules.attendance')`).

- [ ] **Step 2: Confirm overall LOC drop**

```powershell
(Get-Content resources/js/Pages/Dashboard.vue | Measure-Object -Line).Lines
```
Expected: roughly **2,100–2,300 lines** (down from 3,221). If still >2,800, the deletions in Task 10 were partial — re-audit.

- [ ] **Step 3: Final commit**

If any cleanups happened in this task:
```powershell
git add resources/js/Pages/Dashboard.vue
git commit -m "chore(dashboard): final pass — confirm no comingSoon calls remain"
```

---

### Task 14: Update `PROJECT_STATE.md` to reflect P1 completion

**Files:**
- Modify: `d:/CIHRMS/cihrms-mvp/docs/PROJECT_STATE.md`

- [ ] **Step 1: Update the dashboard row in §2 (layer table)**

Find the row:
```
| Dashboard.vue | ~3,221 LOC | ⚠️ | Headline KPIs wired to `DashboardService` props; sparkline arrays still decorative literals |
```

Replace with:
```
| Dashboard.vue | ~2,200 LOC | ✅ | KPIs + sparklines + activity feed all wired to `DashboardService` real data |
```

- [ ] **Step 2: Update §5 gap list**

Remove item 3 "Dashboard decorative literals" — that's now resolved.

- [ ] **Step 3: Update the headline date**

Change the snapshot date at the top from `2026-05-19` to today's date.

- [ ] **Step 4: Commit**

```powershell
git add docs/PROJECT_STATE.md
git commit -m "docs: PROJECT_STATE — P1 dashboard trim + real time-series complete"
```

---

## Manual smoke checklist (P1 done-criteria)

Before declaring P1 done, walk through this in a browser with `composer dev`:

1. `/dashboard` loads without console errors.
2. Sparklines draw (real or zero data).
3. Recent-activity feed shows real events (or the empty state if the DB is fresh).
4. Each of the six rewired buttons navigates to its target page:
   - Profile actions → `/profile`
   - Performance review history → `/performance/reviews`
   - Strategic OKR → `/performance/goals`
   - Announcements archive → `/notifications`
   - Personal task tracker → `/tickets`
   - AI workforce report → `/reports`
5. Sidebar navigation to Assets / Benefits / Learning / Governance lands on the dedicated `Pages/<Module>/Index.vue` (skeleton for the four new modules — that's expected; replaced in P2–P5).
6. `Select-String -Path resources/js/Pages/Dashboard.vue -Pattern 'comingSoon'` returns zero hits.
7. `git log --oneline` shows the planned commit sequence.
8. CI pipeline is green on the latest push.

---

## Self-review checklist (against spec §6 and §7)

**Spec coverage:**
- ✅ §6 P0 git init — Task 2.
- ✅ §6 P0 `.gitignore` — Task 2 Step 2.
- ✅ §6 P0 initial commit — Task 2 Step 5.
- ✅ §6 P0 GitHub Actions CI on PHP 8.4 — Task 3.
- ✅ §7 P1 rewire six dashboard buttons — Task 9.
- ✅ §7 P1 delete duplicated module sections (Assets/Benefits/Learning/Governance) — Task 10.
- ✅ §7 P1 replace four inline forms — Task 11.
- ✅ §7 P1 `DashboardService::timeSeries` — Task 5–6.
- ✅ §7 P1 sparklines reactive — Task 8.
- ✅ §7 P1 activity feed real data — Task 12.
- ✅ §7 P1 done-when (no `comingSoon` in Dashboard.vue, LOC dropped) — Task 13.
- ✅ §15 docs refresh — Task 14.

**Open question for execution time:** which git remote (GitHub / GitLab / Bitbucket / self-hosted) — answer at Task 4 Step 1.
