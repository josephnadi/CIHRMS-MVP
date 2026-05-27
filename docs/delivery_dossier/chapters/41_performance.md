# Chapter 41 — Performance, Caching, Queues, Jobs

> CIHRMS is not a high-throughput system. A single institute moves a few thousand authenticated requests per hour on a busy day, peaks during payroll runs, and is otherwise quiet. The performance story is therefore not "how do we shard?" — it is "where do we hide latency that the request path cannot tolerate, and what do we cache so the dashboard does not melt under an F5-happy finance officer". This chapter lays out the four mechanisms that do that work: six named queues backed by the `database` driver, a small set of 60-second caches in `DashboardService` and around RBAC, deliberate eager-loading inside services, and `Inertia::defer()` for props that would otherwise block first paint. It also names what is not done — there is no Horizon, no Redis in production yet, and no automated N+1 *enforcement* outside of dev — so the gap list is on the record.

---

## 41.1  The performance budget

The request path has one job: validate, transact, dispatch events, render, return. Everything that can be deferred onto a worker is deferred onto a worker. The five things that the request path is allowed to do synchronously are:

1. Resolve the session and run the middleware stack.
2. Validate and authorise (`FormRequest::authorize()` + named middleware).
3. Run the Service inside a single `DB::transaction()`.
4. Dispatch domain events (which queue listeners — they do not run inline).
5. Transform via `Resource` and hand off to `Inertia::render()`.

Everything outside that list — writing the audit log row, computing the hash chain, calling Zoho on new-hire, pushing a payslip to S3, calling NIA to verify a Ghana Card, posting a webhook to a subscriber, materialising disbursement rows from a payroll run, building a GIFMIS journal voucher — runs on a worker. The user gets a 200 (or a redirect, or an Inertia partial) and never waits for the side effect.

The budget that falls out of this is not a millisecond number. It is a *shape*: the median web request runs one transaction, hits the cache for shared payload bits, dispatches between zero and three events, and returns. Anything that would deviate from that shape — a synchronous third-party call, an aggregate over `analytics_events`, a per-page recomputation of the sidebar — was moved to a queue, a cache, or a deferred prop before it shipped.

---

## 41.2  Queues — what actually exists

`config/queue.php` defaults to `database` (line 16: `'default' => env('QUEUE_CONNECTION', 'database')`) and the `connections.database` block (lines 38–45) reads `DB_QUEUE` (default `'default'`) and `DB_QUEUE_RETRY_AFTER` (default 90 seconds). The `redis`, `sqs`, `beanstalkd`, `deferred`, `background`, and `failover` connections are configured but inert — the default is the only one with rows in production. Failed jobs go to the `failed_jobs` table via the `database-uuids` driver (lines 123–127). Job batches use the application DB (lines 105–108) — not used yet but wired for future bulk operations.

Chapter 36 stated five named queues. The current count is **six** named queues plus an implicit `default`. The discrepancy is `payroll`, which was added in the GIFMIS export wave and is targeted by three listeners that override `viaQueue()` instead of declaring a static `$queue` property. The full inventory, taken from `grep -rE "public string \\\$queue|->onQueue\\(|viaQueue\\(\\)" app/`:

| Queue | What runs there | How it declares the name | Why it is isolated |
|---|---|---|---|
| `audit` | `WriteAuditLog` (Job) | `$this->onQueue('audit')` in `__construct` of `app/Jobs/WriteAuditLog.php` (line 28) | Highest volume — one row per mutating HTTP request. Isolated so a backlog here cannot stall identity verification or webhook fan-out. The chain hash work happens inside its own `DB::transaction()` with a `lockForUpdate()` on the latest row; serialising those locks on a separate worker pool keeps contention off the rest. |
| `identity` | `VerifyEmployeeIdentity` (Job) | `$this->onQueue('identity')` in `app/Jobs/VerifyEmployeeIdentity.php` (line 20) | Per-employee NIA verification. The provider rate-limits hard; the queue is the natural throttle and one worker is enough. |
| `integrations` | `CreateZohoContactOnHire`, `NotifyManagerOfLeaveRequest`, `SendOfferEnvelopeToApplicant`, `UploadPayslipToCloud` | `public string $queue = 'integrations'` property on each listener | Calls third-party APIs (Zoho CRM, Twilio, DocuSign/SignWell, S3). Needs its own retry envelope and may temporarily back up if a provider is down without blocking anything that the user is staring at. |
| `notifications` | `SendNotifications`, `FanOutWebhooks` | `SendNotifications` uses `public string $queue`; `FanOutWebhooks` overrides `viaQueue()` | User-facing — the owner of the request cares about latency. Separated from `analytics` to avoid head-of-line blocking from aggregation jobs. `FanOutWebhooks` sits here because outbound webhook delivery feels notification-shaped to subscribers. |
| `analytics` | `RecordAnalyticsEvent`, `GrantSkillsOnCourseCompletion` | `public string $queue = 'analytics'` | Read-heavy aggregation that can lag without user impact. Most events the system emits land here, often through `RecordAnalyticsEvent`'s `match(true) { … }` dispatch over the event type. |
| `payroll` | `GenerateStatutoryReturns`, `MaterialiseDisbursements`, `MintGifmisJournal` | `viaQueue(): string { return 'payroll'; }` on each | Heavy, transactional side effects of a payroll approval/payment. Pinning them to one queue means a payroll run cannot be partially processed by competing workers and the operator can pause the queue cleanly during a finance freeze. |
| `default` (implicit) | `ProcessPaystackWebhook`, incident listeners that did not opt in to `ShouldQueue` | No `onQueue`, no `$queue`, no `viaQueue()` | Catch-all. `ProcessPaystackWebhook` should arguably move to `integrations`; the five incident listeners under `app/Listeners/Incident/` (`NotifyAssignee`, `NotifyUnassigned`, `NotifyCircleOnReopen`, `NotifyMessageRecipients`, `NotifySubmitterOnClose`) do not implement `ShouldQueue` at all — they run *synchronously* inside the request. That is not a queue assignment; it is a missing one. |

The unevenness is real and will be cleaned up. There are three concrete inconsistencies worth flagging:

- **Mixed declaration style.** Some listeners use the `public string $queue = '…'` property; the three payroll listeners and `FanOutWebhooks` use the `viaQueue(): string` method. Both work. The property is preferable because IDE jump-to-definition and static analysis can see it directly; the method is only needed when the queue name depends on runtime state, which none of these cases require. Standardising on the property is a one-PR cleanup on the Phase 1 punch list.
- **`ProcessPaystackWebhook` lands on `default`.** It should be `integrations` — it is, by definition, processing an inbound third-party HTTP payload. Easy fix; not done yet because the webhook handler dispatches it from a controller, not a listener, and the original PR predated the convention being written down.
- **Incident listeners are synchronous.** All five `app/Listeners/Incident/*` listeners write a `Notification` row inline. They are cheap (one INSERT each) and the volume is low (incident reports are submitted in the tens per month at most), so this has not been a real problem. But it does mean a slow `notifications` table — say, during a database lock from an unrelated long-running query — would block the incident-assign HTTP response. They should adopt `ShouldQueue` and land on `notifications`; this is recorded as a Phase 1 cleanup.

### 41.2.1  How a job actually moves through the system

Take the canonical example, `WriteAuditLog`. The middleware `AuditTrail` runs after the response is sent, calls `WriteAuditLog::dispatch($payload)`, and returns. Laravel's bus serialises the job to JSON, opens a row in `jobs` with `queue='audit'`, `available_at=now()`, and `attempts=0`, and commits. The HTTP request is now done as far as the user is concerned.

A worker started with `php artisan queue:work --queue=audit` (or `queue:listen` in dev) polls the `jobs` table, picks the oldest available row whose `queue='audit'`, marks it reserved with `reserved_at=now()`, increments `attempts`, deserialises, and calls `handle()`. The handler opens its own transaction, takes a `lockForUpdate()` on the latest row of `audit_logs` to serialise concurrent appenders, inserts the new row with `previous_hash` pointing at the predecessor, computes `row_hash` after the id and `created_at` are settled, and `saveQuietly()`s the hash back. On success the worker deletes the `jobs` row. On exception the row stays reserved until `retry_after` (90s) elapses, then becomes available again; after `$tries=3` it moves to `failed_jobs` with the exception payload.

The four levers the operator has are: which queues are running (`--queue=audit,identity` runs both, in that priority order; `--queue=audit` runs only audit), how many workers (more workers = more parallel handlers, bounded by the `lockForUpdate()` serialisation on `audit_logs`), how long the worker runs before recycling (`--max-time=3600` or `--max-jobs=1000` are the typical guards against accumulated memory), and `retry_after` (the staleness window for reserved jobs). None of these are tuned in code; they all live in the `supervisord` units described in the operational runbook (Chapter 44, planned).

### 41.2.2  Retry policy

Every job and listener defines `public int $tries`:

- `WriteAuditLog`: `tries = 3`. A failed audit row is not the same as a missing audit row — the job will retry — but three attempts is the limit before the row moves to `failed_jobs` and a `super_admin` is expected to look at it.
- `VerifyEmployeeIdentity`: `tries = 3`. NIA outages are common and short; three tries with the default 90s `retry_after` covers most of them.
- `ProcessPaystackWebhook`: `tries = 3, backoff = 30`. Backoff overrides the connection's `retry_after` for this job specifically — 30s is short enough to catch a transient gateway hiccup, long enough that we don't hammer Paystack's idempotency layer.
- `RecordAnalyticsEvent`, `SendNotifications`, `CreateZohoContactOnHire`, `SendOfferEnvelopeToApplicant`, `UploadPayslipToCloud`, `NotifyManagerOfLeaveRequest`, `GrantSkillsOnCourseCompletion`, `GenerateStatutoryReturns`, `MaterialiseDisbursements`, `MintGifmisJournal`: most default to `tries = 3` where declared; `FanOutWebhooks` is `tries = 1` deliberately — the dispatcher itself retries per-subscriber delivery with its own exponential backoff, and job-level retries on top would amplify the fan-out into duplicate POSTs.

The choice of `tries = 3` is uniform less because three is a magic number and more because Laravel's default is `1` and nobody wanted to think hard about it for every job. The failed-job recovery flow is: `php artisan queue:failed` to list, `php artisan queue:retry {uuid}` to retry one, `php artisan queue:retry all --queue=identity` to retry every failure on a queue, and `php artisan queue:flush` to nuke the table (rarely used; we keep the failures for forensics).

### 41.2.3  Three jobs vs sixteen listeners — why

The codebase has exactly three direct `Job` classes (`WriteAuditLog`, `VerifyEmployeeIdentity`, `ProcessPaystackWebhook`) and sixteen queued `Listener` classes. The rule that emerged from review is simple: if the trigger is an HTTP middleware or a webhook handler that *has no domain event to attach to*, write a Job; if there is a domain event in the system already, write a queued Listener. `WriteAuditLog` is dispatched from middleware that sits outside any service; `VerifyEmployeeIdentity` is dispatched from the identity service after a manual "verify now" click; `ProcessPaystackWebhook` is dispatched from the webhook controller. None of these have an obvious `*Created` or `*Updated` event to listen for — they are themselves the trigger.

Everything else is event-driven. `EmployeeCreated` fires once at the end of `EmployeeService::create()` and three listeners pick it up (`RecordAnalyticsEvent`, `SendNotifications`, `CreateZohoContactOnHire`), each on its own queue. The service does not know who is listening; the listener does not know who fired it. The mapping lives in `AppServiceProvider::boot()` (the `Event::listen(...)` lines starting at line 277).

### 41.2.4  Worker layout (dev vs production)

In development the worker is one process: `composer dev` boots `npm run dev`, `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, and `php artisan pail` together. `queue:listen` (not `queue:work`) restarts the worker between every job so code changes are picked up without a manual restart. `--tries=1` means an exception fails the job immediately — you want to see the failure in the Pail tail, not have it re-queued silently.

In production the worker is one `supervisord` unit per queue. The conservative starting point is:

```
[program:queue-audit]
command=php artisan queue:work --queue=audit --tries=3 --max-time=3600 --sleep=1
numprocs=2

[program:queue-identity]
command=php artisan queue:work --queue=identity --tries=3 --max-time=3600 --sleep=3
numprocs=1

[program:queue-integrations]
command=php artisan queue:work --queue=integrations --tries=3 --max-time=3600
numprocs=2

[program:queue-notifications]
command=php artisan queue:work --queue=notifications --tries=3 --max-time=3600
numprocs=2

[program:queue-analytics]
command=php artisan queue:work --queue=analytics --tries=3 --max-time=3600
numprocs=1

[program:queue-payroll]
command=php artisan queue:work --queue=payroll --tries=3 --max-time=7200
numprocs=1

[program:queue-default]
command=php artisan queue:work --queue=default --tries=3 --max-time=3600
numprocs=1
```

`numprocs` per queue is the only number worth tuning per institute. `audit` and `notifications` are the most likely to back up under load; `identity` is rate-limited externally and one worker is plenty; `payroll` is single-threaded by intent — a payroll run is a non-replayable transactional unit and we do not want two workers picking up sibling listeners for the same run.

There is no Horizon and there is no Redis. Both are on the Phase 1 roadmap, scheduled alongside the SQLite-to-Postgres migration; both are bounded work but neither blocks an MVP buyer and neither was prioritised over the module surface. The case for them is the visibility, not the throughput — `php artisan queue:failed` is a fine recovery interface but a poor monitoring one.

---

## 41.3  Caching — DashboardService is the only one that matters

The cache surface is small. `config/cache.php` defaults to the `database` driver (line 18: `'default' => env('CACHE_STORE', 'database')`) — the same Postgres instance, a different table. In development it is often switched to `file`; in production it will move to `redis` when Redis lands. The application cache prefix (line 115) keeps multi-app installs on the same Redis from colliding.

There are exactly five cache families in the running code:

1. **`dashboard_stats_{user_id}_{role}`** — the per-user role-tailored stats block.
2. **`dashboard.timeseries.{metric}.{days}`** — sparkline series for the role dashboards.
3. **`dashboard.finance` / `dashboard.manager.{user_id}` / `dashboard.deptHead.{user_id}`** — role-tailored snapshots.
4. **`user_perms_{user_id}_{updated_at}`** — the per-user permission union (covered in Chapter 39).
5. **`nav.{user_id}`** and **`holidays.{year}`** — sidebar nav (5 min) and public holidays (24 h).

### 41.3.1  DashboardService — `STATS_TTL = 60`

`app/Services/DashboardService.php` (line 30) defines:

```php
private const STATS_TTL = 60;
```

Every cache key the service writes uses that constant. The reasoning is one of taste and one of arithmetic. Taste: 60 seconds is short enough that data feels live to a human staring at the screen (the gap between a payroll approval and the dashboard reflecting it is, in the worst case, a one-minute lag). Arithmetic: a finance officer hitting F5 four times in ten seconds is a real pattern, especially around month-end; without the cache that is four `SUM(net_total) GROUP BY status` aggregates against `payroll_runs`, four `COUNT(*)` aggregates against `disbursements`, four against `payments`, four against `statutory_returns`. With the cache it is one set of aggregates plus three reads of a cached array. The TTL absorbs the F5 storm without making the snapshot stale enough to mislead.

The five families inside `DashboardService` are:

- **`dashboard_stats_{user_id}_{role}`** — top-of-page counters (`employees`, `pendingLeave`, `openTickets`, `openComplaints`, `openJobs`, `pendingPayments`) used on the admin-style dashboards. Keyed per user *and* per role because a user who is acting in a different role during a session sees different counters.
- **`dashboard.timeseries.{metric}.{days}`** — the 30-day sparkline series for the headline metrics. Implemented in `timeSeries()` (line 111). Each metric is an array of `['date' => …, 'value' => …]` pairs computed from `analytics_events`. `DATE(created_at)` is portable across SQLite, MySQL, and Postgres — there is a comment on line 129 specifically calling out that no driver split was needed, because someone reading the query in three years will wonder.
- **`dashboard.finance`** — the finance officer's hero snapshot (`getFinanceSnapshot()`, line 181). Three `GROUP BY status` aggregates over `payroll_runs`, `disbursements`, `payments`; one derived statutory-return posture; the five most recent runs. The cached payload is 5 KB-ish; without the cache it is 11 SQL round trips on every paint of the finance dashboard.
- **`dashboard.manager.{user_id}`** — the line manager's "what does my team need from me" snapshot (`getManagerSnapshot()`, line 288). Scoped to direct reports (`Employee.manager_id = manager.employee.id`). Eight pending leave rows + eight open tickets + the four team-size counters.
- **`dashboard.deptHead.{user_id}`** — the department head's wider scope (`getDeptHeadSnapshot()`, line 365). Whole-department headcount + leave-today + open tickets + recent leave decisions.

All five share the same 60-second TTL and all five are written through `Cache::remember()`, which only computes the closure on miss. There is no per-record invalidation — a payroll approval at 14:00:00 is visible to all finance users by 14:01:00 at the latest, and that latency was an explicit design choice. The mutating endpoints (payroll approve, disbursement send, etc.) do *not* invalidate `dashboard.finance` deliberately; we'd rather pay the one-minute lag than wire seven services to know about a cache key.

### 41.3.2  Permission cache — `user_perms_{id}_{updated_at}`

`User::hasPermission()` (covered in Chapter 39) caches the union of the three permission layers per `(user_id, users.updated_at)`. The `updated_at` in the key is what makes invalidation free: every write to `users.permissions`, every role assignment, and every direct edit bumps `updated_at`, which produces a new cache key, which misses, which recomputes. The previous key ages out under its 60-second TTL with nobody reading it. There is no explicit `Cache::forget()` anywhere in the auth path.

### 41.3.3  Sidebar nav and holidays

`nav.{user_id}` caches the resolved sidebar tree (about 30 top-level items, each with a permission gate) for 5 minutes. Invalidated on role change by the role-assignment service. `holidays.{year}` caches the list of public holidays used by the leave engine and the payroll prorater for 24 hours; invalidated manually on edit by the holiday admin endpoint.

These two are the only caches where invalidation lives outside the cache key. Both are low-traffic enough that getting it wrong has a small blast radius.

### 41.3.4  What is not cached

A lot, deliberately. Employee lists, leave request lists, payroll line detail, the audit log viewer, the documents tree — none of it is cached. The pattern is: caching is for *aggregates that touch many rows and are read by many people*; per-record reads do not benefit and the invalidation cost is too high to justify. Inertia partials and `router.reload({ only: [...] })` cover the "fast incremental update" need without involving the cache.

---

## 41.4  Soft deletes and the bloat-at-read story

Twenty-nine models use the `SoftDeletes` trait (`grep -lE "use SoftDeletes" app/Models/ | wc -l` returns 29). The full list includes `PayrollRun`, `LoanAccount`, `Disbursement`, `IncidentReport`, `OffboardingCase`, `IdentityVerification`, `WhistleblowerReport`, `PerformanceContract`, `CalibrationSession`, `DataSubjectRequest`, `Document`-adjacent models, `Conversation`, and the cluster of HR-domain models (`Grade`, `Position`, `Allowance`, `Deduction`, `Certification`, `Course`, `Enrolment`, `Goal`, `Review`, `ReviewCycle`, `BiometricDevice`, `Integration`, `LoanProduct`, `PensionTrustee`, `SsoIdentityProvider`, `WebhookSubscription`, `FinalSettlement`, `PerformanceImprovementPlan`).

The performance story for soft deletes is that the global scope (`SoftDeletingScope`) appends `WHERE deleted_at IS NULL` to every query — which is exactly what we want for reads but means the table keeps growing in physical row count even when the logical row count is flat. Two mitigations are in place and one is missing:

1. **Indexes on `deleted_at`** — every `SoftDeletes`-using table has an index on `deleted_at` (Laravel's `softDeletes()` schema helper adds it automatically). Queries hit the index, not the row data, for the scope predicate.
2. **Read scopes work against the index** — `Employee::active()`, `LeaveRequest::pending()`, `Ticket::open()`, etc. all narrow to a small subset before the soft-delete predicate matters, and all of those subsets are themselves indexed.
3. **Missing — a `models:prune` policy.** Laravel 13 ships `Prunable` and `MassPrunable` for tombstoning soft-deleted rows after a retention window. No model in the codebase opts in. Phase 1 will add `Prunable` to the high-churn audit-adjacent tables (`analytics_events`, `audit_logs` *after* the chain is verified and exported), and to the soft-deleted side of fast-moving domain tables (`Disbursement`, `LoanAccount` payment schedules). It is on the punch list, not in production.

The trade-off is the usual one: deleting a row from `audit_logs` invalidates the chain hash, and deleting a row from `payroll_runs` makes regulatory reconstruction impossible. The conservative move is to keep everything soft-deleted forever and tolerate the storage growth; storage is cheap, audit defensibility is not.

---

## 41.5  Eager loading inside services

`DashboardService` is the place to look for how eager loading is supposed to be done in this codebase. The pattern is consistent: every `with(...)` lists *columns* on the related table, not the whole row.

```php
// app/Services/DashboardService.php — line 50
return AnalyticsEvent::with('user:id,name')
    ->latest()
    ->limit($limit)
    ->get();
```

```php
// line 58
return Employee::with(['department:id,name', 'user:id,name,email'])
    ->active()
    ->latest()
    ->limit($limit)
    ->get();
```

```php
// line 67
return Ticket::with('employee:id,employee_no,position')
    ->latest()
    ->limit($limit)
    ->get();
```

```php
// line 307
->with('employee:id,employee_no,position,user_id', 'employee.user:id,name')
```

The `relation:col1,col2` shorthand tells Eloquent to `SELECT` only the named columns from the related table, plus the foreign key Eloquent needs to stitch the result. The payoff is two-fold: the wire transfer between PHP and Postgres is smaller, and the resulting model is smaller in memory (the Eloquent attribute bag holds only what was selected). For a 12-row recent-events list with eager-loaded users, the saving is small in absolute terms; for the manager snapshot that joins `LeaveRequest -> Employee -> User`, projecting the columns avoids materialising every employee's full row just to display a name.

`HandleInertiaRequests::share()` line 26 is the other canonical example: `$user = $request->user()?->loadMissing('employee')`. The Inertia shell needs `user.employee.avatar_url` for the header; without `loadMissing` the avatar accessor would trip a lazy query on every Inertia response and `Model::preventLazyLoading()` (enabled in dev) would throw. The `loadMissing` is a no-op if the relation is already loaded, so calling it unconditionally is the cheap-and-safe form.

The convention that has emerged is: any service method that returns a Collection eager-loads everything the consumer is going to display, and projects columns on the related tables. The PR review for any new service method is expected to check this. There is no automated linter — `larastan` is in `composer.lock` (`larastan/larastan: ^3.9.6`) but not wired into CI as a hard gate yet.

---

## 41.6  Inertia deferred props

Three properties on every Inertia response are wrapped in `Inertia::defer()`, defined in `app/Http/Middleware/HandleInertiaRequests.php` lines 40–56:

```php
'notifications' => Inertia::defer(fn () => $request->user()
    ?->unreadNotifications()
    ->latest()
    ->limit(10)
    ->get()
    ->map(fn ($n) => [
        'id'      => $n->id,
        'message' => $n->data['message'] ?? null,
        'kind'    => $n->data['kind']    ?? null,
        'time'    => $n->created_at->diffForHumans(),
    ]) ?? []),
'notificationCount' => Inertia::defer(
    fn () => $request->user()?->unreadNotifications()->count() ?? 0
),
'announcementTicker' => Inertia::defer(fn () => $request->user()
    ? app(AnnouncementService::class)->ticker($request->user())->values()->all()
    : []),
```

`Inertia::defer()` is a v2 feature. The closure is *not* evaluated on the initial response — Inertia ships the page with those props absent, the client paints, and then Inertia issues a follow-up GET (a partial reload) to fetch just those keys. The page-paint round trip stays small; the bell badge and ticker update a moment later without anyone watching it noticed.

The cost of *not* deferring would have been three extra DB queries on *every* Inertia visit: an `unreadNotifications()->limit(10)->get()`, an `unreadNotifications()->count()`, and an announcement scan. The first two hit the `notifications` table on the user's `notifiable_id`; the third hits `announcements` + a recipient join. None individually is slow; together on every navigation they add ~30ms on Postgres and ~5ms on SQLite — enough to feel.

The pattern would generalise. Any property that is "show eventually" rather than "render in the first paint" is a candidate for `Inertia::defer()`. So far the three above are the only uses, but the leave calendar's prefetch of next-month requests and the documents tree's recently-modified strip are both being considered for the same treatment.

---

## 41.7  N+1 — what is enforced, what is not

Chapter 36's quick treatment said "no N+1 enforcement story yet". That was true at first draft; the current state is more nuanced.

`app/Providers/AppServiceProvider.php` lines 232–234 do enable strictness in dev/test:

```php
$strict = ! $this->app->isProduction();
Model::preventLazyLoading($strict);
Model::preventSilentlyDiscardingAttributes($strict);
```

In a non-production environment, any code path that triggers a lazy load throws `Illuminate\Database\LazyLoadingViolationException`. Feature tests run in this mode, so an N+1 introduced in a service method that the tests exercise will fail CI loudly. `preventAccessingMissingAttributes` is *not* enabled (see the comment in lines 225–231): middleware reads optional `User` columns that aren't always present on factory or partially-hydrated instances and strict mode would 500 those flows. The trade-off is documented in `docs/QA_REPORT.md §102`.

What is missing is the same enforcement in production. `preventLazyLoading(false)` means production silently degrades — a lazy load works, runs the extra query, and ships the bytes. The defensible position is that every code path the tests cover is N+1-free, and the 182 feature tests cover most of what matters; the gap is the handful of paths reachable only by production data shapes (a user with 4,000 audit log rows attached, a payroll run with 3,500 lines). Those are the paths that would benefit from production-mode strictness on a sampling basis, and that is the unbuilt work item.

Three concrete pieces of unbuilt work, scoped:

1. **Wire `larastan` into CI.** The package is already in `composer.lock`; the missing pieces are a `phpstan.neon` baseline and a GitHub Actions step that runs `vendor/bin/phpstan analyse`. Bounded; rejected so far only because the existing test coverage is the higher-leverage gate.
2. **Sampled production N+1 detection.** Enable `Model::preventLazyLoading()` on 1% of production requests and route the violations to the error tracker. The framework supports this trivially via a per-request `Model::preventLazyLoading(rand(1, 100) === 1)` in `AppServiceProvider::boot()`. Not yet done.
3. **Telescope or Pulse, gated to staging.** Laravel Pulse is the modern equivalent of Telescope's slow-query panel. Useful, not essential. Phase 1 candidate; not deployed yet.

The honest version is: the request path is *probably* N+1-free because every service method has a feature test and those tests would fail under `preventLazyLoading`, but "probably" is not "verified", and the verification work is on the list.

---

## 41.8  Scheduled tasks

`routes/console.php` is the canonical place. The schedule today (May 2026):

```php
// Auto-reject stale pending leave whose end_date has passed
Schedule::call(fn () => LeaveRequest::where('status', LeaveStatus::Pending->value)
    ->where('end_date', '<', today())
    ->update(['status' => LeaveStatus::Rejected->value])
)->dailyAt('00:05');
```

```php
// Manager reminders for leave requests pending > 3 days
Schedule::call(fn () => LeaveRequest::pending()
    ->where('created_at', '<', now()->subDays(3))
    ->with('employee.user')
    ->get()
    ->each(fn ($lr) => $lr->employee?->user?->notify(new LeaveApprovalReminder($lr)))
)->dailyAt('08:00');
```

The named artisan commands:

| When | Command | What it does |
|---|---|---|
| Every 30 min | `integrations:refresh-tokens --minutes=10` | Refreshes any integration OAuth token that expires within 10 minutes. `withoutOverlapping()` so a long-running refresh against a flaky provider does not double-fire. |
| Daily 23:55 | `attendance:mark-absent` | Materialises an `absent` attendance summary for any employee with no clock-in/clock-out events for the day. Without this, the absence report would be a derived calculation on every read; the schedule turns it into a write. |
| Daily 03:00 | `audit:verify-chain --notify` | Walks `audit_logs` end-to-end, re-hashing each row, comparing against `row_hash`. Notifies every `super_admin` on any mismatch. Runs at 03:00 so it lands after the day's writes but well before office hours; a broken chain is a security incident and a human alert needs to be waiting by the time HR walks in. Covered in detail in Chapter 40. |
| Daily 07:30 | `identity:expiring --window=30` | Flags employees whose 12-month NIA validity expires within 30 days. One notification per expiring row; HR sees the upcoming queue, the employee gets the inbox nudge. |
| Daily 08:00 | `governance:certification-reminders` | Iterates `certifications` for rows whose `expires_at` is approaching and fires the `CertificationExpiring` event (which routes through `RecordAnalyticsEvent` on the `analytics` queue and creates a notification on the holder). |
| Daily 02:15 | `payment-intents:expire-stale` (anonymous via `Schedule::call`) | F4-R follow-up: flips Paystack `pending` payment intents whose `expires_at` has passed into `expired` state. Without it, `pending` intents accumulate forever and the finance hub's pending-intents counter drifts. `->name('payment-intents:expire-stale')->onOneServer()` because in a multi-worker future only one box should run it. |
| Monthly, 1st at 02:00 | `assets:regenerate-depreciation` | Recomputes depreciation snapshots for fixed assets. Idempotent — runs `withoutOverlapping()` on the off chance a previous month is still wrapping up. |

There is no scheduled `payroll auto-mint` — the brief asked about one, but the actual implementation is event-driven, not scheduled. `MintGifmisJournal` listens for `PayrollRunPaid` and, gated on `config('payroll.gifmis.auto_mint_on_paid')` (default `false`, env `GIFMIS_AUTO_MINT`), builds the journal voucher CSV. There is nothing on the cron telling it to fire — the trigger is the payment action in the UI. This is intentional: a scheduled monthly mint would risk minting against a run that has been amended in the interim; binding it to the `PayrollRunPaid` event guarantees the journal reflects the run at the moment it was paid.

The scheduler itself is driven by the standard Laravel cron entry, one line in `crontab`:

```
* * * * * cd /var/www/cihrms && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` is the dispatcher; it checks once a minute which commands are due and runs them. Locking is per-command via `withoutOverlapping()`; multi-server coordination is per-command via `onOneServer()` and uses the same cache driver to coordinate the lock. With one production box this is moot; once a read replica or HA pair is in place it matters, which is why `payment-intents:expire-stale` already opts in.

---

## 41.9  Forward — Horizon, Redis, the production posture

Two pieces of infrastructure are named in the Phase 1 roadmap and would land together:

**Redis (Phase 1)**. `config/cache.php` line 75 and `config/queue.php` line 67 are already wired for it; the change is `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` in the production `.env`, plus the Redis instance itself. The cache prefix (`config/cache.php` line 115) already handles multi-app safety. The migration is essentially a config flip plus a one-time `php artisan queue:work --once --stop-when-empty` against the database driver to drain the existing `jobs` table before the switch.

The two wins from Redis are: (a) atomic operations on the cache (`Cache::increment`, `Cache::add` for distributed locks) that the database driver implements but slowly; (b) `BLPOP` semantics on the queue, so workers wake the instant a job arrives instead of polling every `--sleep=N` seconds. For an HRMS, neither is a throughput unlock — they are a latency floor change from ~3 seconds (sleep interval) to ~milliseconds (wake on push), which mostly matters for the notification queue.

**Horizon (Phase 4)**. Horizon is a dashboard + supervisor on top of Redis queues. It does three things `php artisan queue:work` alone does not: it autoscales worker counts per queue based on backlog, it provides a real-time UI for jobs/failed/throughput, and it sends alerts on slow jobs and on failures. The pre-requisite is Redis; the work itself is a `composer require laravel/horizon`, a `config/horizon.php` that defines the queue → worker-count map, a `supervisord` unit that runs `php artisan horizon` (single process — Horizon itself spawns and reaps the workers), and an authorisation gate on `/horizon` so only operators can reach it.

The gating dependency is Phase 1 (Redis). Once that lands, Horizon is a 1-day job. The reason both are deferred is the same: neither is a buyer-visible feature for an institute of a few hundred people, and the existing tooling (`php artisan queue:work` under `supervisord`, `php artisan queue:failed`, occasional `php artisan queue:retry`) covers the operator's actual needs at MVP scale.

**Other deferred work, listed for completeness:**

- **Postgres read replica.** Not a queue or cache concern but the same shape of decision — bounded work (a second connection in `config/database.php`, `->on('read')` annotations on the cached-aggregate queries, replica lag monitoring), justified when the single Postgres instance starts to feel it. No institute on the current roadmap is close.
- **OPcache JIT.** Off by default in our deploy template, on for `php-fpm` workers only. Bounded; worth measuring once Phase 1 lands and the queue-driver migration is settled. Probable single-digit-percent throughput win, not a game changer.
- **HTTP cache headers on Inertia assets.** Already handled by `AddLinkHeadersForPreloadedAssets` middleware for the preload hints; the asset URLs themselves are content-hashed by Vite so an aggressive `Cache-Control: public, max-age=31536000, immutable` is safe and already in the nginx template.

---

## 41.10  The honest summary

The performance story is small on purpose. Six queues separate the work that the user does not need to wait for; five cache families absorb the read pressure on the dashboards; eager loading is enforced by convention and by `preventLazyLoading()` in dev; deferred Inertia props keep the first paint under one round trip; soft deletes keep tables append-only with the help of `deleted_at` indexes. None of it is novel. All of it is documented because the next engineer to touch a queue assignment, a cache key, or an eager-loaded relation needs to know what the existing pattern is and why.

What is unbuilt and on the record:

- **Standardise queue declaration style** (`public string $queue` everywhere, retire `viaQueue()` for static names).
- **Move `ProcessPaystackWebhook` from `default` to `integrations`.**
- **Add `ShouldQueue` to the five incident-notification listeners** under `app/Listeners/Incident/`.
- **Wire `larastan` into CI** as a hard gate.
- **Sampled `preventLazyLoading()` in production** routed to the error tracker.
- **Adopt `Prunable` on `analytics_events`** and on the verified-and-exported tail of `audit_logs`.
- **Redis migration (Phase 1)** with Horizon on top (Phase 4).
- **Telescope or Pulse**, staging-only, for slow-query visibility.

Everything on that list is bounded. Most of it is one PR. The reason none of it has shipped is that the existing posture meets the throughput and latency envelope of the current buyers, and the module surface has consistently outranked the infrastructure punch list in review.

The next chapter (42 — Frontend Internals) picks up where this one leaves off: the shared layouts, the SlidePanel pattern, and how `Inertia::defer()` and Vue's reactivity stitch together to keep the perceived performance steady even when a payload arrives in two round trips.
