# CIHRMS — Technical Requirements Document (TRD)

> **Product:** CIHRMS — CIHRM Ghana HRMS
> **Audience:** Engineering, DevOps, Security, QA, Compliance
> **Version:** 1.0 — first formal TRD
> **Last revised:** 2026-05-20
> **Companion docs:** [PRD.md](PRD.md), [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md), [SYSTEM_DESIGN_DIAGRAMS.md](SYSTEM_DESIGN_DIAGRAMS.md)

---

## 1. Purpose & Scope

This TRD specifies the **technical requirements** that implement the product requirements in the PRD. It is binding on:

- Backend engineers (Laravel)
- Frontend engineers (Vue + Inertia)
- DevOps / SRE (deploy, observability, hosting)
- Security & compliance (DPA / Cybersecurity Act / Auditor-General)
- QA (Pest 4)

Where the PRD says *what*, the TRD says *how*, *to what tolerance*, and *with which library*.

---

## 2. Technical Stack (frozen for v2)

| Layer | Choice | Version | Rationale |
|---|---|---|---|
| **Language (server)** | PHP | `^8.3` (CI on 8.4) | Laravel 13 baseline; 8.5 deferred (`laravel/pao` incompat) |
| **Framework** | Laravel | `^13.7` | LTS-ish, Inertia + queues + Sanctum first-class |
| **API auth tokens** | `laravel/sanctum` | `^4.0` | Personal access tokens, scoped per integration |
| **PDF rendering** | `barryvdh/laravel-dompdf` + `tcpdf` + `setasign/fpdi` | as locked | Payslip + report packs; FPDI overlays existing letterhead |
| **Spreadsheets** | `maatwebsite/excel` | `^3.1` | XLSX export of reports + statutory returns |
| **SAML SSO** | `onelogin/php-saml` | `^4.2` | IdP-initiated and SP-initiated; SAML 2.0 only |
| **Frontend framework** | Vue | `^3.4` | Composition API + `<script setup>` |
| **Frontend transport** | Inertia.js | `^2.0` | SSR-flavoured SPA; no separate API for the web app |
| **Bundler** | Vite | `^8.0` | + `laravel-vite-plugin` + `@vitejs/plugin-vue` |
| **CSS** | Tailwind CSS | `^3.2` | + `@tailwindcss/forms` (NOTE: `@tailwindcss/vite ^4` is installed; we stay on Tailwind 3 to keep `tailwind.config.js` semantics) |
| **Route generation** | `tightenco/ziggy` | `^2.0` | Vue-side `route()` parity |
| **PDF preview** | `pdfjs-dist` | `^4.10` | In-browser preview of payslips, contracts, documents |
| **Signature capture** | `signature_pad` | `^4.2` | Performance contract dual signature, offer envelope |
| **Testing** | Pest | `^4.7` (+ `pestphp/pest-plugin-laravel`) | Feature + policy tests |
| **Static analysis / format** | Pint | `^1.27` | PSR-12 |
| **DB (dev)** | SQLite | bundled | Zero-setup local |
| **DB (prod target)** | PostgreSQL | 15+ | Required for `gen_random_uuid()`, partial indexes, JSONB, RLS-ready |
| **Queue (prod target)** | Redis + Horizon | Redis 7+, Horizon current | `audit`, `analytics`, `notifications`, `integrations`, `payroll` queues |
| **Cache (prod target)** | Redis | 7+ | Permission cache, dashboard cache |
| **Mail (prod target)** | SMTP / Mailgun | n/a | Driver-agnostic |
| **Storage (prod target)** | S3-compatible (or `storage/public` for kiosk) | n/a | Payslip PDFs, document versions, avatars |
| **Process supervision** | Supervisor | 4+ | Units provided under [deploy/supervisor/](../deploy/supervisor/) |
| **Error tracking** | Sentry | optional package, wired in `config/logging.php` `sentry` channel |
| **Backups** | `spatie/laravel-backup` | optional, configured via `config/backup.php` |
| **Hosting target** | NITA gov cloud (gov tenants) / managed VPS (commercial) | n/a | Compliance with NITA hosting policy for MDAs |

---

## 3. Code Architecture Requirements

### 3.1 Layering convention (mandatory for every module)

```
Enum → Migration → Model → FormRequest → Service → Event → Listener → Resource → Controller → Inertia Page
```

Rules:

1. **Controllers are thin.** No business logic. Constructor-inject the service, validate via FormRequest, return `back()->with('success', …)` or `Inertia::render(…)`.
2. **Services own business logic.** Side effects (DB writes, file uploads, integration calls) are confined here.
3. **Events are dispatched from services** (never from controllers).
4. **Listeners are queued** (`ShouldQueue`) on a named queue (`audit`, `analytics`, `notifications`, `integrations`).
5. **FormRequests authorize.** `authorize()` MUST call `hasPermission()` or a Policy, never a raw role check.
6. **Resources transform.** Never return raw Eloquent models from controllers — wrap in a Resource.
7. **Enums for finite domains.** No string literals for statuses/types.
8. **SoftDeletes everywhere** for core entities.

### 3.2 Naming & file layout

| Concern | Path |
|---|---|
| Enums | `app/Enums/*.php` |
| Models | `app/Models/*.php` |
| Form Requests | `app/Http/Requests/<Module>/<Verb><Entity>Request.php` |
| Services | `app/Services/<Module>/<Entity>Service.php` or top-level for cross-cutting |
| Events | `app/Events/<Entity><Verb>.php` |
| Listeners | `app/Listeners/<Action><Entity>.php` |
| Jobs | `app/Jobs/<Verb><Entity>.php` |
| Resources | `app/Http/Resources/<Entity>Resource.php` |
| Policies | `app/Policies/<Entity>Policy.php` |
| Middleware | `app/Http/Middleware/<Name>.php` |
| Inertia pages | `resources/js/Pages/<Module>/<Page>.vue` |
| Shared components | `resources/js/Components/<Component>.vue` |
| Layouts | `resources/js/Layouts/<Layout>.vue` |

### 3.3 Strict mode

- `Model::shouldBeStrict(!app()->isProduction())` is **always on** in non-production. Lazy loading is a fatal error.
- `Model::preventSilentlyDiscardingAttributes()` is implied by strict mode — every mass-assigned attribute must be `$fillable`.

### 3.4 Frontend requirements

- All pages live under `resources/js/Pages/` and are rendered via `Inertia::render('Module/Index', $resource)`.
- Layouts: `AuthenticatedLayout.vue` (sidebar shell) for authed routes; `GuestLayout.vue` for auth screens.
- Shared primitives: `StatusBadge`, `EmptyState`, `Pagination`, `SlidePanel`, `KanbanBoard`, `StatCard`, `ProgressRing`.
- Design tokens follow **"Sovereign Precision"** — see [`tailwind.config.js`](../tailwind.config.js).
- No `console.log` in production builds (`vite build` strips by default).
- All form posts use `useForm()` from `@inertiajs/vue3`.

---

## 4. Data Layer Requirements

### 4.1 Database

- **Primary store:** PostgreSQL 15+ in production.
- **All FKs declared and indexed.** `onDelete('cascade')` for owned children; `onDelete('set null')` for soft refs.
- **Soft delete columns** (`deleted_at`) on every core entity. Hard delete reserved for DPA 2012 erasure (recorded in `data_subject_requests`).
- **UUIDs** for entities exposed in URLs to anonymous users (documents, complaints, whistleblower reports).
- **JSONB columns** for: `users.permissions`, `audit_logs.changes`, `notifications.payload`, `integration_events.payload`, webhook delivery payloads.
- **Hash chain columns** on `audit_logs`: `hash` (this entry) + `prev_hash` (chain link) per `2026_05_25_000007_add_tamper_evident_audit_columns`.

### 4.2 Naming

- snake_case table and column names.
- Plural table names (Laravel convention).
- Pivot tables: `<a>_<b>` alphabetically (`role_permissions`, `user_roles`).
- Timestamps: `created_at`, `updated_at`, `deleted_at`, plus domain-specific: `approved_at`, `resolved_at`, `paid_at`, `acknowledged_at`.

### 4.3 Migrations

- One concern per migration; reversible (`down()` implemented).
- Filename pattern: `YYYY_MM_DD_NNNNNN_<verb>_<table_or_change>.php`.
- Never `dropColumn` data without a backfill — write a data-only migration first.

### 4.4 Seeders

- `DatabaseSeeder` calls `RolePermissionSeeder` **twice** (idempotent) — once before factory users, once after — so factory users get pivots.
- Demo data seeders use `--demo` flag (not run in production).

---

## 5. Authentication & Authorization

### 5.1 Authentication

- **Primary mode:** Staff ID + Full Name + password (custom — see `LoginRequest`).
- **Alternative mode:** SSO via SAML 2.0 or OIDC for enterprise / government tenants (`auth/sso/{slug}` → `SsoController`).
- **2FA:** TOTP via `TwoFactorController`; required by the `2fa:fresh` middleware on any destructive operation (payroll approve/reverse, loan decide/disburse, settlement approve/complete, calibration apply, whistleblower triage/assign, SSO provider mutate, privacy fulfil, AG report generate).
- **Password rotation:** `password_must_change` flag on `users`; `ForcePasswordChange` middleware blocks all routes until reset.

### 5.2 Authorization (RBAC)

Three layers; **all three are consulted** by `User::hasPermission()`:

1. **Legacy enum** — `User::ROLE_PERMISSIONS` keyed by `users.role`. `super_admin` gets wildcard `*`.
2. **DB-backed** — `roles` / `permissions` / `role_permissions` / `user_roles` (with `department_id` for scoped roles).
3. **Per-user JSON** — `users.permissions` (a JSON array on the user row), highest priority.

Plus per-model **Policies** (`EmployeePolicy`, `LeaveRequestPolicy`, `TicketPolicy`, `PaymentPolicy`, `DepartmentPolicy`); a `Gate::before` hook routes `$user->can('perm.slug')` through `hasPermission()`.

**Department scoping** is enforced via `Employee::scopeVisibleTo($user)` and `User::managesDepartment($id)`.

**Cache:** `hasPermission()` result cached 60 s per user.

---

## 6. Security Requirements

| Control | Requirement |
|---|---|
| **TLS** | TLS 1.2+ end-to-end. `SESSION_SECURE_COOKIE=true`, `APP_TRUSTED_PROXIES` configured for reverse proxy. |
| **CSRF** | Laravel default (cookie + header). All POST/PATCH/DELETE require token. |
| **CSP** | (P7) Content-Security-Policy header restricting `script-src`, `style-src`, `img-src`. |
| **2FA** | TOTP, RFC 6238; 30-second window; 6-digit codes; encrypted secret column; 8 recovery codes (one-time). |
| **Session** | HttpOnly, SameSite=Lax, secure, 2 h idle timeout. Re-authentication for sensitive routes via `2fa:fresh`. |
| **Password** | bcrypt cost 12 (Argon2id was evaluated and rejected for per-login PHP-FPM cost variance — see `docs/delivery_dossier/chapters/40_security.md`). Minimum 10 chars, 1 number, 1 symbol, blocked against the 1k common-password list. |
| **Webhook signatures** | Each provider verified by `VerifyWebhookSignature` middleware: WhatsApp HMAC-SHA256, Zoho shared-secret, MS Graph clientState, Google channel-token, Slack v0-signature with 5-min replay window, biometric per-device HMAC, Hubtel SMS/USSD HMAC. |
| **Audit log** | Every authenticated mutating request → `AuditTrail` middleware → `WriteAuditLog` job (`audit` queue) → tamper-evident `audit_logs` row chained by `prev_hash`. |
| **Rate limits** | Public: careers `5/min`, kiosk `60/min`, whistleblower `6/min`, SSO `30/min`. Authed: self-clock `10/min`. |
| **Signed URLs** | All downloads (payslip, document, settlement, AG report) use `URL::temporarySignedRoute()` with 5-min expiry. |
| **Field-level crypto** | (P7) Bank account, Ghana Card PIN, SSNIT # encrypted at rest via `Crypt::encryptString` or Postgres `pgcrypto`. |
| **Secrets** | Only in `.env` (or vault); never committed; rotated on staff departure. |
| **Dependency scanning** | (P7) `composer audit` + `npm audit` in CI; auto-PR on critical CVE. |
| **Pen-test** | (P6 / P7) annual third-party pen-test; remediation tracked as `incident_reports`. |

---

## 7. API & Integration Requirements

### 7.1 Internal API (Inertia)

- All authenticated web routes are Inertia (server-side route resolution, JSON over XHR).
- Error envelope for JSON requests is normalized by handlers in [`bootstrap/app.php`](../bootstrap/app.php).

### 7.2 Public REST API (v1)

- Versioned under `/api/v1/*`.
- OpenAPI 3.x spec served at `/api/v1/openapi.yaml`.
- Interactive docs at `/api/docs` (Stoplight Elements).
- Auth: Sanctum personal access tokens, **scoped** per integration (`api.token_manage` to mint).
- Rate limited per token.
- Errors follow Problem Details for HTTP APIs (RFC 7807).
- Pagination: cursor-based for high-volume endpoints; offset for the rest.

### 7.3 Outbound webhooks

- Customers register `webhook_subscriptions` (URL, secret, event filter).
- Deliveries recorded in `webhook_deliveries` with retry policy (exponential backoff, max 8 attempts).
- Outbound payload signed with `X-CIHRMS-Signature: sha256=<hmac>`.

### 7.4 Inbound webhooks

- Each provider has a controller in `app/Http/Controllers/Webhooks/`.
- Signature verified by `VerifyWebhookSignature` middleware (provider name passed as param).
- Idempotency: `integration_events` table records every inbound event with provider+external_id unique index.

### 7.5 Identity providers (SSO)

- SAML 2.0 (`onelogin/php-saml`) and OIDC.
- Configured per tenant via `sso_identity_providers`.
- Each successful or failed login writes `sso_login_attempts`.
- Identity links stored in `user_identity_links` (one user can link multiple IdPs).

### 7.6 Messaging (SMS / USSD)

- Hubtel inbound on `/webhooks/sms` and `/webhooks/ussd`.
- Outbound through `MessagingService::send($to, $body, $channel)`.
- USSD sessions tracked in `ussd_sessions` (per provider session id).
- Per-user phone PINs in `staff_phone_pins` for non-web auth on USSD.

### 7.7 Statutory & Financial integrations

- **GhIPSS / MoMo disbursement:** `DisbursementService::dispatchRun()` constructs the payment file, transmits to provider, parses response, writes `disbursements` rows.
- **SSNIT / GRA-PAYE / NPRA Tier-2 / NHIA returns:** Generated by `PayrollCalculator` and exposed under `payroll-runs.{run}.returns.{returnId}`; format = provider-spec (CSV / XLSX).
- **GIFMIS export:** (P7) Standardised journal CSV for upload to GIFMIS — driven from approved `payroll_runs` and `disbursements`.
- **NIA / Ghana Card:** `IdentityVerificationController::store` → `IdentityVerification` row with verification proof.

---

## 8. Domain Events & Async Processing

### 8.1 Event catalogue (current)

| Event | Emitter | Listeners |
|---|---|---|
| `EmployeeCreated` | `EmployeeService` | `RecordAnalyticsEvent`, `SyncZohoContact` |
| `LeaveRequested` | `LeaveService::submit()` | `NotifyManagerOfLeave`, `RecordAnalyticsEvent` |
| `LeaveStatusUpdated` | `LeaveService::decide()` | `RecordAnalyticsEvent`, `NotifyEmployeeOfLeaveDecision` |
| `TicketCreated` | `TicketService` | `RecordAnalyticsEvent`, `NotifyAssigneeOfTicket` |
| `PaymentCreated` / `PaymentMarkedPaid` | `PaymentService` | `UploadPayslipToCloud`, `RecordAnalyticsEvent` |
| `ApplicantCreated` | `RecruitmentService::apply()` | `RecordAnalyticsEvent`, `SyncZohoContact` |
| `OfferEnvelopeSent` | `RecruitmentService::sendOffer()` | `DispatchOfferToESign` |
| `PayrollApproved` | `PayrollRunController::approve` | `RecordAnalyticsEvent`, `LogTamperEvidentAudit` |
| `WhistleblowerReceived` | `WhistleblowerPublicController::submit` | `NotifyInvestigators`, `RecordAnalyticsEvent` |

### 8.2 Queue topology

| Queue | Purpose | Worker target |
|---|---|---|
| `audit` | `WriteAuditLog` | 1 worker; rarely fails |
| `analytics` | `RecordAnalyticsEvent` | 2 workers |
| `notifications` | Email / SMS / push | 2 workers; retries 3x |
| `integrations` | Zoho / WhatsApp / e-sign | 2 workers; retries 5x; circuit breaker |
| `payroll` | Statutory engine (heavy) | 1 worker; long timeout |
| `default` | Everything else | 2 workers |

All queues are managed by **Laravel Horizon** in production.

---

## 9. Observability

| Signal | Tool | Requirement |
|---|---|---|
| **Errors** | Sentry | All uncaught exceptions; sample 100% |
| **Audit** | `audit_logs` | Tamper-evident, hash-chained |
| **Queues** | Horizon | Failed-job retention 7 days |
| **Performance** | Sentry traces or Pail dev tail | p95 per route |
| **Uptime** | Provider-side healthcheck | `/health` returns 200 + JSON status |
| **Backups** | `spatie/laravel-backup` | Daily DB + storage snapshot |
| **Accessibility** | `php artisan accessibility:audit` | CI-gated WCAG AA pass |

---

## 10. Deployment Requirements

### 10.1 Environments

- **Local dev** — SQLite, `composer dev` (artisan serve + queue:listen + pail + vite).
- **CI** — GitHub Actions on PHP 8.4, runs Pint + Pest.
- **Staging** — Postgres 15, Redis, full integrations sandbox.
- **Production** — Postgres 15, Redis, Horizon, Supervisor, Sentry, S3 storage, NITA / managed VPS.

### 10.2 Configuration

- All environment-sensitive values in `.env`; documented in [`.env.example`](../.env.example).
- `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `DB_*`, `REDIS_*`, `MAIL_*`, `SESSION_SECURE_COOKIE`, `APP_TRUSTED_PROXIES`.
- Sentry key, Hubtel credentials, NIA credentials, GhIPSS credentials, MoMo credentials per tenant in encrypted config or vault.

### 10.3 Process supervision

Provided units under [deploy/supervisor/](../deploy/supervisor/):

- `cihrms-web.conf` — `php-fpm` workers
- `cihrms-horizon.conf` — Horizon supervisor
- `cihrms-scheduler.conf` — `php artisan schedule:work`

### 10.4 Deploy steps

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link
npm ci
npm run build
sudo supervisorctl restart all
```

See [docs/deployment_production.md](deployment_production.md) for the full runbook.

---

## 11. Testing Requirements

### 11.1 Coverage targets

| Layer | Target |
|---|---|
| Services | ≥ 80% line coverage (Pest unit) |
| Controllers (happy paths) | All shipped routes (Pest feature) |
| Policies | Deny paths for every policy (Pest feature) |
| Webhook signatures | All providers (already shipped — `WebhookSignatureTest`) |
| Frontend smoke | Cypress / Playwright (P6+) covering each module entrypoint |

### 11.2 Test patterns

- `tests/Pest.php` auto-applies `TestCase + RefreshDatabase` to `tests/Feature/**`.
- Permission grants use the per-user JSON: `User::factory()->create(['permissions' => ['payroll.run']])`.
- Route bindings: pass the bound key directly (e.g. `route('documents.show', $doc->uuid)`).
- Run: `composer test` (clears config, runs Pest).

### 11.3 CI gate

- Pint (PSR-12).
- Pest suite (PHP 8.4 in CI).
- Composer audit + npm audit.
- `php artisan accessibility:audit` (when present).
- Build check: `npm run build`.

---

## 12. Compliance & Statutory

| Regime | Requirement | Where enforced |
|---|---|---|
| **DPA 2012 (Act 843)** | Data subject access / erasure / portability fulfilled ≤ 30 days | `DataSubjectRequest` + `PrivacyController` |
| **Cybersecurity Act 2020** | CSA registration; incident reporting | `IncidentReport` + ops runbook |
| **Whistleblower Act 720** | Anonymous channel; reporter protection; investigator workflow | `WhistleblowerReport` + public + admin controllers |
| **Labour Act 651** | Overtime computation; leave entitlements; public holidays | `AttendanceService`, `LeaveService`, `PublicHoliday` |
| **NPRA Act 766** | Tier-2/3 mandatory contributions; trustee selection | `PayrollCalculator`, `PensionTrustee` |
| **NHIA** | 2.5% routed from Tier-1 employer contribution | `PayrollCalculator` |
| **GRA-PAYE** | 2026 bracket schedule | `TaxBracket` (seeded) |
| **WCAG 2.1 AA** | Public + authed pages | Audit command; checklist at [wcag_aa_checklist.md](wcag_aa_checklist.md) |

---

## 13. Performance Targets

| Surface | Target |
|---|---|
| Page TTFB (warm) | p95 < 400 ms |
| Page TTFB (cold) | p95 < 900 ms |
| Payroll calculate (5k employees) | ≤ 90 s on a single queue worker |
| Statutory return export (5k employees) | ≤ 30 s |
| Document upload (≤ 20 MB) | ≤ 5 s |
| Dashboard cold load | ≤ 1.2 s |
| USSD response time | ≤ 2 s per step (provider hard limit) |
| Mobile PWA install | Lighthouse PWA score ≥ 90 |

---

## 14. Open Technical Items

| ID | Item | Phase |
|---|---|---|
| T1 | Migrate dev DB to Postgres via `DB_CONNECTION=pgsql` (currently SQLite) | P6 |
| T2 | Resolve `laravel/pao` PHP 8.5 incompat | P6 |
| T3 | Wire `PaymentCreated` / `PaymentMarkedPaid` / `ApplicantCreated` so sparkline metrics are non-zero | P6 |
| T4 | Sentry + `spatie/laravel-backup` `composer require` at deploy time | P6 |
| T5 | GIFMIS journal export format finalised | P7 |
| T6 | LLM vendor + grounding pipeline for AI assistant | P7 |
| T7 | Per-tenant CSP + signed-CSP report endpoint | P7 |
| T8 | Field-level encryption for bank account / Ghana Card / SSNIT # | P7 |

---

## 15. References

- Laravel 13 docs: <https://laravel.com/docs/13.x>
- Inertia.js v2: <https://inertiajs.com/>
- Pest 4: <https://pestphp.com/>
- DPA 2012 (Ghana): Act 843
- Cybersecurity Act 2020 (Ghana): Act 1038
- Whistleblower Act (Ghana): Act 720
- Labour Act (Ghana): Act 651
- National Pensions Act (Ghana): Act 766
- WCAG 2.1: <https://www.w3.org/TR/WCAG21/>
