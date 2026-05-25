# Chapter 36 — Architecture & Stack

> CIHRMS is a Laravel 13 monolith fronted by an Inertia.js + Vue 3 shell, built by Vite and served as a single PHP deployable. SQLite carries development; PostgreSQL is the production target. Auth is Sanctum-backed sessions keyed on a Ghanaian staff identifier rather than email. Side effects (audit writes, analytics fan-out, identity verification, payslip uploads, third-party integrations) leave the request path through a database-backed queue. There is no separate REST surface for the web UI — Inertia keeps routing, RBAC, and validation server-authoritative — and a parallel `/api/v1/*` slice exists for integrators, sharing the same Services underneath. Nothing about this is novel; that is the point.

---

## 36.1  Part II preamble — what changes from here on

Part I (Chapters 1–35) addressed buyers, evaluators, and end users. Each module chapter answered "what does this screen do and why is the button here". From this chapter forward the audience is an engineer joining the team, an auditor walking the code, or a reviewer comparing CIHRMS to another build. The voice tightens accordingly: classes are named, file paths are cited, trade-offs are stated, and marketing adjectives are dropped. Where Part I said "instant audit trail", Part II says `AuditTrail` middleware dispatches `WriteAuditLog` onto the `audit` queue.

The chapters that follow this one — Stack Deep-Dive (37), Database Schema (38), RBAC Internals (39), Audit Chain (40), Queues & Jobs (41), Frontend Internals (42), Testing Strategy (43), Operational Runbook (44) — each pick up one slice of this overview and unfold it.

---

## 36.2  Stack at a glance

| Layer | Choice | Notes |
|---|---|---|
| Runtime | PHP `^8.3` | Targeting 8.3 features (readonly classes, typed constants); CI also exercises 8.4. |
| Framework | `laravel/framework ^13.7` | Latest LTS-track major at time of build (composer.json). |
| HTTP / view bridge | `inertiajs/inertia-laravel ^2.0` | Server-resolved routing, server-side props, client-rendered Vue. |
| SPA layer | Vue 3 (`^3.4.0`) | Composition API throughout; no Vuex/Pinia — Inertia props are state. |
| Build | Vite 8 (`^8.0.0`) | `laravel-vite-plugin ^3.1`, `@vitejs/plugin-vue ^6.0.0`. |
| CSS | Tailwind 3 (`^3.2.1`) | Tailwind 4 evaluated and deferred; the v4 Vite plugin is installed (`@tailwindcss/vite ^4.0.0`) for future migration but the config file remains v3. |
| Auth | `laravel/sanctum ^4.0` | Cookie sessions for the web app; Personal Access Tokens for `/api/v1/*`. |
| Routing helpers (client) | `tightenco/ziggy ^2.0` | Named routes available in Vue via `route()`. |
| PDF (rendering) | `barryvdh/laravel-dompdf ^3.1`, `tecnickcom/tcpdf ^6.7`, `setasign/fpdi ^2.6` | DomPDF for letters and payslips; TCPDF/FPDI for overlay on bank-issued letterhead PDFs. |
| Spreadsheet | `maatwebsite/excel ^3.1` | Used by reports and bulk imports. |
| SSO | `onelogin/php-saml ^4.2` | Drives `App\Services\Sso\SamlSsoAdapter`. OIDC adapter is hand-rolled. |
| LLM | `anthropic-ai/sdk ^0.23.0` | Powers `App\Services\Ai\Providers\AnthropicLlmProvider`; the system also ships a `FakeLlmProvider` for tests. |
| Testing | `pestphp/pest ^4.7` + `pestphp/pest-plugin-laravel ^4.1` | Pest 4 throughout; Mockery and Faker on the side. |
| Tooling | `laravel/pint`, `laravel/pail`, `laravel/breeze`, `laravel/pao`, `nunomaduro/collision` | Pint enforces style; Pail tails logs in dev; Breeze scaffolded the original auth pages. |

Front-end runtime dependencies are intentionally thin: `pdfjs-dist` for the document annotator and `signature_pad` for e-signature capture (`package.json`). Everything else, including the chart primitives and table virtualisation used in payroll, is hand-rolled Vue against the design tokens described in Chapter 33.

Typography (set in `tailwind.config.js`): Open Sans for UI text and JetBrains Mono for monospaced fields (staff numbers, payslip totals, bank statement IDs). The "Sovereign Precision" design system described in Chapter 33 lives one layer above this stack — colours and motion tokens, not framework choices.

---

## 36.3  The shape of the codebase

The numbers below were taken from the working tree at the time of writing. They are a rough proxy for surface area, not for quality.

| Artifact | Count | Source |
|---|---|---|
| Vue page components (Inertia targets) | **128** | `resources/js/Pages/**/*.vue` |
| Eloquent models | **124** | `app/Models/*.php` |
| Service classes | **121** | `app/Services/**/*.php` |
| Form Request classes | **130** | `app/Http/Requests/**/*.php` |
| Migrations | **116** | `database/migrations/*.php` |
| Controllers | **97** | `app/Http/Controllers/**/*.php` (web + `Api\v1`) |
| Enums | **88** | `app/Enums/*.php` |
| API Resources (transformers) | **77** | `app/Http/Resources/**/*.php` |
| Domain events | **62** | `app/Events/**/*.php` |
| Policies | **25** | `app/Policies/*.php` |
| Queued listeners | **16** | `app/Listeners/**/*.php` |
| Application middleware | **10** | `app/Http/Middleware/*.php` |
| Queued jobs | **3** | `app/Jobs/*.php` |
| Feature tests | **182** | `tests/Feature/**/*.php` |
| Unit tests | **10** | `tests/Unit/**/*.php` |
| Routes (`Route::verb(...)` invocations) | **424** | `routes/web.php` (387) + `routes/api.php` (19) + `routes/auth.php` (18) |

A handful of observations fall out of these numbers:

- **Models outnumber Services by only a little.** That ratio (124 / 121) reflects the convention that every domain operation lives in a Service — there are no controllers reaching into Eloquent directly for writes. The codebase deliberately pays the duplication cost so the audit and event surfaces are consistent.
- **Form Requests outnumber Controllers (130 / 97).** A single controller often hosts multiple verbs and each gets its own validator. Controllers stay thin precisely because the authorisation lives in `FormRequest::authorize()`.
- **Events (62) drive Listeners (16), which in turn drive notifications and analytics fan-out.** Many events have no listener — they exist for downstream consumers (webhook subscribers via `FanOutWebhooks`, analytics via `RecordAnalyticsEvent`) rather than for in-process side effects. This is intentional: domain events are a contract, not a function call.
- **Only 3 Jobs.** Most async work is `ShouldQueue` listeners, not raw jobs. The three jobs that exist (`WriteAuditLog`, `VerifyEmployeeIdentity`, `ProcessPaystackWebhook`) are dispatched directly from middleware or webhook handlers where no event would fit.
- **Test ratio leans heavily Feature.** 182 Feature tests to 10 Unit tests is unusual elsewhere but defensible here: most logic that matters is routed through HTTP + permission + transaction + event, and only end-to-end Feature tests catch regressions in that chain. Unit tests cover pure calculators (`PayeCalculator`, `PiiRedactor`, enum methods) where isolation pays.

---

## 36.4  The request flow, station by station

Every authenticated, mutating HTTP request takes the same path. The exceptions — webhooks, SAML callbacks, public DPA submissions — are noted at the end.

```
HTTP request
     │
     ▼
 Laravel HTTP kernel  (bootstrap/app.php)
     │
     ▼
 Web middleware group (in order, appended in bootstrap/app.php):
   • SetUserLocale
   • HandleInertiaRequests
   • AddLinkHeadersForPreloadedAssets
   • ForcePasswordChange
     │
     ▼
 CSRF validation
   (excluded: auth/sso/*/callback — signature-verified instead)
     │
     ▼
 Authenticate (auth)        ← session resolution
     │
     ▼
 Authorise
   • role:<slug>            (EnsureRole)
   • permission:<slug>      (EnsurePermission)
   • 2fa[:fresh]            (RequireTwoFactor)
     │
     ▼
 Route binding
     │
     ▼
 AuditTrail middleware     ← captures route name, method, payload (sanitised)
     │
     ▼
 Controller (thin)
     │
     ▼
 FormRequest               ← validate() + authorize()
     │
     ▼
 Service (transactional)   ← all business logic; emits domain events
     │     │
     │     └─► Event::dispatch(...) → sync registered listeners
     │                                  │
     │                                  ▼
     │                               Queued Listener (audit / analytics /
     │                               integrations / notifications / identity)
     │                                  │
     │                                  ▼
     │                               Queue worker picks up later
     │
     ▼
 Resource (transformer)
     │
     ▼
 Inertia::render('Module/Page', $props)
     │
     ▼
 HTTP response
   • First visit  → full HTML (app.blade.php shell + Vue boot)
   • Subsequent   → JSON payload, Inertia patches the page in place
```

A few notes on the stations that matter:

**`bootstrap/app.php`** is the single source of truth for middleware composition. The web group appends four custom layers (`SetUserLocale`, `HandleInertiaRequests`, the preload link header helper, and `ForcePasswordChange`) and aliases the named middleware used across the routes: `role`, `permission`, `audit`, `webhook.signature`, `paystack.signature`, `2fa`, `api.scope`. The API throttle is bound on the same call: `$middleware->throttleApi('60,1')` — 60 requests per minute per token.

**`HandleInertiaRequests::share()`** is where every Inertia response gets its baseline payload: `auth.user` (with `employee` eager-loaded to avoid an N+1 on the avatar accessor), `auth.role`, plus the lazy-evaluated `auth.roles`, `auth.permissions`, `auth.managedDepartmentIds`. Three properties are deferred via `Inertia::defer()` — `notifications`, `notificationCount`, `announcementTicker` — so the bell badge and announcement strip make a separate, post-paint round trip rather than blocking every navigation on three extra DB queries. The `i18n.lines` payload pre-loads three translation files (`common`, `leave`, `payroll`) so the shell paints in the user's locale without a client-side language fetch.

**`AuditTrail` middleware** is non-blocking by design. It runs *after* the response is generated (`$response = $next($request)` first, then dispatch), only fires on `POST`/`PUT`/`PATCH`/`DELETE`, and only when a user is authenticated. The request payload is `except`-stripped of `password`, `password_confirmation`, `current_password`, `token`, `_token`; uploaded files are replaced with `{name, mime, size}` descriptors because `Symfony\UploadedFile` cannot be serialised onto a queue; strings are truncated to 500 characters. The sanitised payload then becomes a `WriteAuditLog::dispatch(...)` call, which `onQueue('audit')`s itself. The user never waits for the chain hash to be computed.

**`FormRequest::authorize()`** is where most authorisation actually lives. The named middleware (`permission:employees.manage`) handles the coarse gate at the route level; the FormRequest handles the per-row gate that needs the actual entity. `UpdateEmployeeRequest::authorize()`, for example, checks HR > department head > self in one place, returns `false` if none match, and Laravel's exception handler renders the 403 before validation rules are even evaluated.

**Services** wrap their writes in `DB::transaction()` and dispatch events at the end of the success branch. `EmployeeService::create()` is the canonical example: it locks a row for staff-ID generation, inserts the `User`, inserts the `Employee`, attaches benefit enrolments, fires `EmployeeCreated`, and returns. Anything that throws inside the transaction rolls all of it back; the event never fires on a rollback.

**`Resource`** classes do two jobs: shape the JSON and gate sensitive fields. `EmployeeResource` omits the `salary` key entirely when the viewer fails `EmployeePolicy::viewSalary` — the field doesn't exist on the wire, not just hidden in CSS.

**Inertia** sees a Resource (or a plain array) and renders the named Vue page. On a same-app navigation the response is JSON only; on a hard refresh the response is the full `app.blade.php` shell with the JSON inlined as a `data-page` attribute.

Exceptions to this flow:

- **API requests** (`/api/v1/*`) skip Inertia entirely. The same Services run; responses use the `Api\V1` resources. `bootstrap/app.php` registers JSON renderers for `AuthenticationException`, `ValidationException`, and any `HttpException` so the API never returns HTML error pages.
- **Inbound webhooks** (`/webhooks/{provider}`) skip CSRF (`validateCsrfTokens(except: ['auth/sso/*/callback'])` for SAML; webhook routes opt out via route-level config). Signatures are verified by `VerifyWebhookSignature` or `VerifyPaystackSignature` middleware instead.
- **SAML ACS POST** is the documented CSRF exception. The IdP cannot include a Laravel session token, so XML signature verification inside `SamlSsoAdapter` is what protects the route.

---

## 36.5  The Inertia model in this codebase

Inertia.js v2 is not a fashion choice; it is what allows the codebase to keep a single auth, validation, and audit story while still presenting as a Vue SPA. The constraints it imposes are real:

- **Routing is server-resolved.** Every page that exists is in `routes/web.php` and points to a controller. The Vue side has no router. `route()` calls in components use Ziggy to compile named routes back into URLs.
- **Pages live in `resources/js/Pages/`** and are referenced by string from the server: `Inertia::render('Employees/Index', $props)`. The 128 files in that tree are the complete inventory of addressable screens.
- **State is shared via `HandleInertiaRequests::share()`.** Anything every page needs (the signed-in user, roles, permissions, flash messages, locale) is set up there once. Page-specific state arrives as the `$props` array on each `render`.
- **Deferred props** (Laravel-Inertia v2) are the answer to the cost of sharing too much. `notifications`, `notificationCount`, and `announcementTicker` are all `Inertia::defer(fn () => ...)` — the page paints first, then a follow-up Inertia request fetches them. The shell is unaware; the bell just appears with a count a moment after the navigation completes.
- **No GraphQL, no REST for the web app.** The page renders with the data the controller passes in. Subsequent interactions are either Inertia visits (`router.visit`, `useForm().post`) or, for write-then-stay-here flows, `router.reload` partials.

The trade-off is straightforward: anyone who wants to embed CIHRMS in another front-end has to use the `/api/v1/*` surface (separate controllers under `App\Http\Controllers\Api\v1`, separate `Api\V1\*Resource` transformers, separate scope-based auth via Sanctum PATs). The web app and the API are two presentations of the same Services; neither is a thin wrapper over the other.

---

## 36.6  Authentication

The auth model breaks one Laravel convention and adopts most of the rest.

**Identifier.** Users log in with `staff_id` + `name` + `password`, not email. The Ghanaian institutional reality is that staff are issued a Staff ID on hire and identify themselves by it for the rest of their tenure; their email may be a personal address, may change, or may not exist for warehouse and frontline roles. The `staff_id` column on `users` is unique and auto-generated as `SID-NNNNNN` when blank (see `EmployeeIdentifierService`). The `LoginRequest` validator checks the staff ID and name match, then attempts the password. Email is captured for notification routing only.

**Session.** Laravel Sanctum's cookie-based session driver is used for the web app. There is no separate "API token" issued to the browser; the same session cookie covers Inertia visits and any AJAX from the page.

**API tokens.** Integrators use Sanctum Personal Access Tokens against `/api/v1/*`. Tokens carry scopes (`api.scope` middleware), are surfaced in the UI under `/settings/api-tokens`, and are tracked in `api_token_metadata` for last-used and IP attribution.

**ForcePasswordChange.** A user with `password_must_change = true` on their row (set when HR creates the account with a temporary password, or when an admin issues a reset via `php artisan users:issue-password-resets`) is redirected to the change-password page on every navigation until they comply. The middleware is appended to the web group in `bootstrap/app.php` and short-circuits Inertia visits to anywhere else.

**Two-factor.** TOTP-only, enroled at `/two-factor/enroll`, challenged at `/two-factor/challenge`. Sensitive operations (payroll approve/reverse/disburse, loan disburse, off-boarding settlement approval, calibration apply, AG report generation, AI write actions, SSO provider mutations, mass message send) gate behind `2fa:fresh` — a recent TOTP challenge within a configurable window. The implementation is in `App\Services\Auth\TwoFactorService` and `App\Http\Middleware\RequireTwoFactor`. Recovery codes are stored hashed alongside the TOTP secret on the `users` table (added in `2026_05_25_000008_add_two_factor_columns_to_users.php`).

**SSO.** SAML 2.0 (`onelogin/php-saml`) and OIDC (hand-rolled adapter) live behind a common `SsoAdapter` interface. Identity providers are configured per institution under `/admin/sso/providers`; login attempts and identity links are tracked in `sso_login_attempts` and `user_identity_links`. The orchestrator (`App\Services\Sso\SsoOrchestrator`) handles user provisioning on first login and the just-in-time role mapping a particular IdP may declare.

---

## 36.7  Queues

`config/queue.php` defaults to the `database` driver — Postgres in production, SQLite locally — with the `jobs` and `failed_jobs` tables doing the bookkeeping. Drivers for `redis`, `sqs`, and `beanstalkd` are configured but inert; switching to Redis-backed queues (and adding Horizon for visibility) is on the Phase 1 roadmap, scheduled alongside the SQLite-to-Postgres migration.

The application uses **five named queues**. They were assigned per listener, not centrally, so the names reflect what each piece of work is rather than a strict ordering:

| Queue | What runs there | Why it's separated |
|---|---|---|
| `audit` | `WriteAuditLog` job (one per mutating HTTP request) | Highest volume; isolated so a backlog here cannot stall identity verification or webhook fan-out. |
| `analytics` | `RecordAnalyticsEvent`, `GrantSkillsOnCourseCompletion` | Read-heavy aggregation that can lag without user impact. |
| `notifications` | `SendNotifications` | User-facing — owner cares about latency; separated to avoid head-of-line blocking from analytics. |
| `integrations` | `NotifyManagerOfLeaveRequest`, `CreateZohoContactOnHire`, `SendOfferEnvelopeToApplicant`, `UploadPayslipToCloud` | Calls third-party APIs; needs its own retry envelope and may temporarily back up if Zoho is down. |
| `identity` | `VerifyEmployeeIdentity` job | Per-employee NIA verification; rate-limited at the provider, so the queue is the natural throttle. |

A sixth implicit queue, `default`, is used by the few listeners that don't override `$queue` (notably `FanOutWebhooks` and `MaterialiseDisbursements`). The naming is uneven; standardising it is a Phase 1 cleanup task.

In development, `composer dev` boots the worker with `php artisan queue:listen --tries=1 --timeout=0` alongside the dev server, Vite, and Pail. In production, the runbook (planned for Chapter 44) calls for a `supervisord` unit per queue so worker counts can be tuned independently.

There is no Horizon yet. The case for it is straightforward (real-time visibility, retry control, slow-job alerts) and the work is bounded; it will land with the Redis migration.

---

## 36.8  Caching

The cache surface is small and deliberately so.

- **Dashboard stats** (`App\Services\DashboardService::STATS_TTL`): 60 seconds, keyed per `(user_id, role)`. The keys cover both the role-tailored snapshots (`dashboard.finance`, `dashboard.manager.{id}`, `dashboard.deptHead.{id}`) and the per-user `dashboard_stats_{id}_{role}` aggregate. The TTL is short enough that data feels live; the cache absorbs the F5-refresh storm a finance officer might launch.
- **Dashboard time-series** (`dashboard.timeseries.{metric}.{days}`): same 60-second TTL. These power the sparkline strip on the role dashboards and would otherwise re-aggregate `analytics_events` on every paint.
- **Permission checks** (`User::hasPermission()`): 60 seconds per `(user_id, slug)`, invalidated on any change to `users.permissions`, `roles`, `role_permissions`, or `user_roles`. Documented in `docs/SYSTEM_ARCHITECTURE.md §5.4`.
- **Sidebar nav** (`nav.{user_id}`): 5 minutes, invalidated on role change. The sidebar is computed from the user's permissions and contains 30-odd top-level entries; recomputing on every navigation is wasteful.
- **Public holidays** (`holidays.{year}`): 24 hours, manually invalidated on edit. Used by the leave engine and the payroll prorater.

The driver is `file` in development and would become `redis` in production. Cache key invalidation is explicit and lives next to the writers; nothing relies on TTL alone for correctness.

---

## 36.9  What this architecture optimises for

- **Single-tenant per institute.** The data model is single-tenanted by omission. There is no `tenant_id` column on the row tables, no row-level security, no per-tenant schema split. An institute that buys CIHRMS gets its own database, its own queue, and its own file storage. Multi-tenancy was evaluated and rejected for the MVP — the operational simplicity of "one institute, one deploy" is more valuable to the buyers than the cost savings of co-hosting would be, and the security story for sensitive HR data is dramatically easier to write.
- **Predictability over cleverness.** Every module follows the Enum → FormRequest → Service → Event → Listener → Resource shape. The convention is enforced more by the project memory and PR review than by tooling, but it holds across all 30-plus modules. A new engineer who reads one module can read the others.
- **Auditability everywhere.** `AuditTrail` middleware runs on every authenticated mutating route by default; there is no opt-in. The hash chain in `audit_logs` makes silent backfill detectable; the `2fa:fresh` gate on destructive operations means every approval has a fresh identity assertion attached to it. The audit chapter (40) goes deep on the chain semantics.
- **Async on cost-bearing operations.** Anything that touches a third party, a slow file system, or an aggregate over a large table goes onto a queue. The user-facing request stays inside a single transaction, returns, and Inertia paints; the listener does the rest. This keeps the median request well under the framework's own overhead.
- **Server-authoritative everything.** RBAC is checked on the server. Visibility scopes (`Employee::scopeVisibleTo()`) are applied on the server. Resource transformers drop fields the viewer cannot see. The Vue layer trusts what it receives and renders it; it does not gate, scope, or filter.

---

## 36.10  What this architecture does NOT optimise for

- **Horizontal scale across the request path.** A single php-fpm pool against a single Postgres instance is the entire production picture. There is no sharding, no read replicas, no app-level cache invalidation broadcast across nodes. Most CIHRMS deployments will be a single institute of a few hundred to a few thousand employees, and the throughput envelope of one well-provisioned box covers that with margin. The day a multi-thousand-employee institute hits a wall is the day we add a read replica and Redis-cached sessions — not before.
- **Realtime.** There are no WebSockets, no SSE channels, no broadcasting layer. The chat module (Ch 14), the notifications panel (Ch 16), and the announcement ticker all use polling — typically 30 seconds, sometimes 60. This is a known limitation; the trade-off was complexity (a Reverb or Pusher integration, plus a separate process and a different scaling model) against perceived latency. Polling won for the MVP. Phase 4 reopens the question if the chat module's usage justifies it.
- **Multi-tenancy.** As above — there is no path inside the current codebase to host two institutes on one database. The data model would need a `tenant_id` on every row, the scopes would need to apply it everywhere, the cache keys would need to include it, the queues would need to either be tenant-scoped or carry the tenant in the payload. None of that is impossible; it is also not the buyer's priority and was explicitly out of scope.
- **Hot reload of the server.** PHP without OPcache file-watching means schema or service changes need a deploy, not a refresh. This is normal for the framework; it is called out here only because engineers who arrive from Node/Rails sometimes expect otherwise.
- **A separate API for the web app.** The web app is Inertia. Anyone building a separate front-end (mobile app, third-party portal) uses `/api/v1/*`, which is a parallel surface, not a back-end-of-the-back-end. The two surfaces share Services; they do not share controllers. Keeping them parallel rather than collapsing one into the other was a deliberate choice — the Inertia path stays simple precisely because it does not have to serve a generic JSON client.

---

## 36.11  Reading order for the rest of Part II

A reader who wants to understand how this skeleton breathes should take Chapters 37–44 in order. Each lands one layer deeper:

- **Ch 37 — Stack Deep-Dive.** Library-by-library, why each package was picked and what the alternative was.
- **Ch 38 — Database Schema.** The 116 migrations laid out by domain group, with the foreign-key map and the soft-delete strategy.
- **Ch 39 — RBAC Internals.** The three-tier evaluation order (enum, DB roles, per-user JSON), the dept-scoped pivot, the cache invalidation, and the test patterns for granting permissions in Pest.
- **Ch 40 — Audit Chain.** The hash chain construction, the `prev_hash` race-condition fix, and the `audit:verify` walker.
- **Ch 41 — Queues & Jobs.** The five named queues, the listeners that target them, retry semantics, and the failed-job recovery flow.
- **Ch 42 — Frontend Internals.** The shared layouts, the SlidePanel pattern, the design tokens, and the i18n loader.
- **Ch 43 — Testing Strategy.** Why 182/10 in favour of Feature, the Pest base classes, the per-user `permissions` column trick, and the route-binding caveats.
- **Ch 44 — Operational Runbook.** Deploy, migrate, queue, backup, restore. The `supervisord` units and the `php artisan down --secret=` rollout pattern.

A reader who only has time for one of those should pick Chapter 40 — the audit chain is the single most-questioned mechanism in the system and the one that most differentiates CIHRMS from a generic HRIS.
