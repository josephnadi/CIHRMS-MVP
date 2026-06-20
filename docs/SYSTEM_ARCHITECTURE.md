# CIHRMS — System Architecture Document

> **Product:** CIHRMS — CIHRM Ghana HRMS
> **Audience:** Engineering, DevOps, Security, Architecture reviewers
> **Version:** 1.0
> **Last revised:** 2026-06-20
> **Companion docs:** [PRD.md](PRD.md), [TRD.md](TRD.md), [SYSTEM_DESIGN_DIAGRAMS.md](SYSTEM_DESIGN_DIAGRAMS.md)

---

## 1. Architectural Overview

CIHRMS is a **modular monolith** built on Laravel 13 with an **Inertia.js + Vue 3** SPA shell. The platform deliberately remains a single deployable to keep the cost-of-ownership low for public-sector adopters; the modularity is enforced inside the codebase through a strict layered convention rather than network boundaries.

### 1.1 Architectural style

| Style choice | Justification |
|---|---|
| **Modular monolith** (not microservices) | Single deploy, single DB, single auth/audit story. Government tenants need minimal infrastructure surface and clear data residency. |
| **Inertia SSR-flavoured SPA** | Server-resolved routing keeps RBAC + audit on the server; Vue gives a modern UX with no separate API to govern. |
| **Domain events + queued listeners** | Side effects (notifications, analytics, integrations) are decoupled from the request path; failures don't break user-facing flows. |
| **CQRS-lite** | Reads use Eloquent + Resource transformers; writes go through Services. Heavy aggregations (Dashboard, Reports) are cached. |
| **Outbox / inbox pattern (lite)** | `integration_events` table serves as both inbox (idempotent inbound webhooks) and outbox (for outbound webhook subscriptions delivery). |
| **API v1 as a parallel surface** | The web app uses Inertia; integrators use REST under `/api/v1/*` — same services underneath. |

### 1.2 Bounded contexts (domain map)

```
                 ┌──────────────── Identity & Access ────────────────┐
                 │ Users · Roles · Permissions · SSO · 2FA · MFA      │
                 └────────────────────────────────────────────────────┘
                                       │
   ┌───────────────────────────────────┼──────────────────────────────────┐
   ▼                                   ▼                                  ▼
People & Org                       Lifecycle                          Compensation
─────────────                      ─────────                          ────────────
Departments                        Recruitment                        Payroll Runs
Employees                          Onboarding (lifecycle)             Payslips
Positions/Grades/Steps             Off-boarding                       Allowances / Deductions
Identity Verification              Settlement→GL posting              Loans & Advances
                                   Disbursements                      Statutory Returns
                                   Final Settlement                   Remittance Tracking
                                                                      Pension Trustees (Tier-1/2/3)

   ┌──────────────────────────┬──────────────────────────┬──────────────────────────┐
   ▼                          ▼                          ▼                          ▼
Time                       Performance                  Service & Engagement       Learning & Skills
────                       ───────────                  ────────────────────       ─────────────────
Shifts                     Cycles / Reviews             Tickets                    Courses
Attendance                 Goals / Check-ins            Complaints (public ref)    Enrolments
Corrections                Contracts (dual-sign)        Whistleblower (anon)       Certifications
Public Holidays            Calibration / 9-box          Incident Reports           Skills Matrix
Biometric                  PIPs                         Announcements              Compliance enforcement
                                                                                   Course prerequisites

   ┌──────────────────────────┬──────────────────────────┬──────────────────────────┐
   ▼                          ▼                          ▼                          ▼
Assets & Benefits          Documents                  Governance & Compliance     Communications
─────────────────          ─────────                  ───────────────────────     ──────────────
Asset Register             DMS                        Policies + Acknowledgements Notifications
Assignments                Versions / Routing         Audit Logs (hash-chained)   Messaging (SMS/USSD)
Maintenance                Annotations                Auditor-General Pack        Webhooks (in/out)
Benefit Plans              Composer (PDF)             Data Subject Requests       Integrations (OAuth)
Claims / E-cards

   ┌──────────────────────────────────────────────────────────────────────────────┐
   ▼
Finance & Ledger
────────────────
Chart of Accounts                  Universal Posting (single PostingService choke point)
Posting Accounts map               Fiscal Periods & Month-end Close
Ledger Balances                    Subledger Reconciliation (AP / AR / payroll)
Financial Statements (P&L,         Budgeting
  Balance Sheet, Cash Flow,        Finance Analytics Dashboard
  Trial Balance)
```

---

## 2. Logical Architecture

### 2.1 Layered structure (per module)

```
┌──────────────────────────────────────────────────────────────────┐
│                       Vue 3 (Inertia Pages)                      │
│   resources/js/Pages/<Module>/Index.vue · Show.vue · Edit.vue    │
└──────────────────────────────────────────────────────────────────┘
                              │ Inertia visit
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│                  HTTP layer (Laravel router)                     │
│   middleware: auth · verified · audit · permission · 2fa:fresh   │
└──────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│        Controllers (thin)  ←  Form Requests (validate+authz)     │
└──────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│                 Services (all business logic)                    │
│      transactional DB writes · domain event dispatch             │
└──────────────────────────────────────────────────────────────────┘
                  │                        │
                  ▼                        ▼
   ┌──────────────────────────┐   ┌──────────────────────────┐
   │  Eloquent Models         │   │  Events (sync dispatch)  │
   │  + Policies + Scopes     │   └──────────────────────────┘
   │  + SoftDeletes + Casts   │                │
   └──────────────────────────┘                ▼
                  │                  ┌──────────────────────────┐
                  ▼                  │  Queued Listeners        │
   ┌──────────────────────────┐      │  audit · analytics ·     │
   │  PostgreSQL              │      │  notifications ·         │
   └──────────────────────────┘      │  integrations · payroll  │
                                     └──────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│         Resources (transformers) → JSON for Inertia/API          │
└──────────────────────────────────────────────────────────────────┘
```

### 2.2 Cross-cutting layers

| Cross-cutting | Implementation |
|---|---|
| **AuthN** | Custom Staff ID + Name + password (`LoginRequest`), SAML/OIDC SSO (`SsoController`), Sanctum personal access tokens for API v1 |
| **AuthZ** | Three-tier RBAC (`User::hasPermission()`) — enum + DB roles + per-user JSON. Policies for per-model checks. `EnsurePermission` and `EnsureRole` middleware on routes. |
| **Audit** | `AuditTrail` middleware on all authed routes → dispatches `WriteAuditLog` job → `audit_logs` table with hash chain (`hash` + `prev_hash`). |
| **Webhook signature** | `VerifyWebhookSignature` middleware accepts a provider key; verifies HMAC / shared secret / signature header per provider. |
| **2FA gate** | `2fa:fresh` middleware verifies a recent TOTP challenge before destructive ops. |
| **Inertia share** | `HandleInertiaRequests` middleware shares `auth.user`, `auth.roles`, `auth.permissions` (lazy), `auth.managedDepartmentIds` (lazy), `flash`, `csrf_token`. |
| **Rate limit** | Built-in `throttle:N,M` per route. |
| **Localisation** | `LocaleController::update`, `users.locale` column; `App::setLocale()` in middleware. |
| **PWA** | Service worker + manifest in `public/`; `/offline` Blade view as fallback shell. |

---

## 3. Physical / Deployment Architecture

### 3.1 Reference topology (commercial tenant)

```
                        ┌─────────────────────────┐
   Browser / PWA  ────► │   Reverse Proxy + TLS   │  (Nginx / Caddy)
                        └────────────┬────────────┘
                                     │
                  ┌──────────────────┼───────────────────┐
                  ▼                  ▼                   ▼
         ┌────────────────┐  ┌────────────────┐  ┌────────────────┐
         │  php-fpm pool  │  │ Horizon queue  │  │   Scheduler    │
         │  (Laravel app) │  │   workers      │  │  schedule:work │
         └───────┬────────┘  └───────┬────────┘  └────────────────┘
                 │                   │
       ┌─────────┼───────────────────┼─────────┐
       ▼         ▼                   ▼         ▼
  ┌────────┐ ┌───────┐         ┌────────┐ ┌────────────┐
  │Postgres│ │ Redis │         │  S3    │ │  Sentry    │
  │  15    │ │   7   │         │ storage│ │ (errors)   │
  └────────┘ └───────┘         └────────┘ └────────────┘
       ▲         ▲                   ▲
       │         │                   │
       └───── Backups ───────────────┘  (spatie/laravel-backup → S3)
```

### 3.2 Reference topology (government / MDA tenant)

Identical to the commercial topology, with two substitutions:

1. **Hosting:** NITA gov cloud (mandated by NITA hosting policy for MDA workloads).
2. **Storage:** Government-approved object store / on-prem MinIO behind the NITA network.

### 3.3 Process supervision

`supervisord` units (in [deploy/supervisor/](../deploy/supervisor/)):

- `cihrms-web.conf` — php-fpm pool
- `cihrms-horizon.conf` — `php artisan horizon`
- `cihrms-scheduler.conf` — `php artisan schedule:work`

---

## 4. Module Architecture (high-leverage examples)

### 4.1 Payroll module (the heaviest)

**Components:**
- `PayrollRunController` — thin
- `Services/Payroll/PayrollRunService` — orchestration
- `PayrollCalculator` — pure-PHP statutory engine (PAYE, SSNIT, Tier-2/3, NHIA)
- Models: `PayrollRun`, `PayrollLine`, `Allowance`, `Deduction`, `TaxBracket`, `StatutoryRate`, `StatutoryReturn`, `PensionTrustee`
- Events: `PayrollApproved`, `PayrollReversed`
- Listeners: tamper-evident audit, analytics, downstream disbursement enqueue
- Routes: gated by `payroll.run`, `payroll.approve`, `payroll.reverse`, plus `2fa:fresh` on approve/reverse/disburse

**State machine for a `PayrollRun`:**

```
draft  → calculating → calculated → approved → paid
                                  ↘ rejected
              ↑ reverse (from approved/paid; 2FA)
```

Every transition writes an `audit_logs` row in the tamper-evident chain.

**Statutory coverage:** all four pension tiers are live — PAYE (effective-dated brackets), SSNIT Tier-1 (13%/5.5% + NHIA split), Tier-2 (5% trustee), and **Tier-3 voluntary pension** (percentage election, 16.5% combined relief cap, GL `2230`, per-trustee schedule). Return generation is paired with **remittance submission tracking** (mark-filed write path, period-end + 14-day deadline, overdue posture).

### 4.1a Finance / GL accounting backbone

The general ledger is the single source of truth for all monetary state.

**Components:**
- `Services/Finance/PostingService` — the **single posting choke point**: every monetary event (payroll, AP, AR, disbursement, off-boarding settlement, bank adjustment) posts a balanced, double-entry journal through this one service. Enforces idempotency and a closed-period guard.
- `posting_accounts` map — resolves domain events to debit/credit GL accounts (admin-editable).
- `LedgerBalanceService` — derives balances and the four financial statements (Profit & Loss, Balance Sheet, Cash Flow, Trial Balance).
- Fiscal periods & **month-end close**, with subledger reconciliation (AP / AR / payroll control accounts vs GL).
- Budgeting and a **finance analytics dashboard** (Chart.js / vue-chartjs).
- **Settlement→GL posting:** off-boarding final settlement posts through the same `PostingService` choke point.

**Invariant:** ALL monetary events post balanced journal entries through the single `PostingService` choke point; no module writes ledger rows directly. The choke point provides idempotency (replayed events do not double-post) and a closed-period guard (no posting into a closed fiscal period).

### 4.2 Whistleblower module (anonymity-preserving)

**Components:**
- `WhistleblowerPublicController` (public, rate-limited)
- `WhistleblowerAdminController` (auth, `whistleblower.investigate`)
- Models: `WhistleblowerReport`, `WhistleblowerEvidence`, `WhistleblowerAction`, `WhistleblowerMessage`, `WhistleblowerSubject`
- Reference assigned at submission (`WB-XXXX`); reporter's identity is **never** required.
- Tracking uses reference + optional PIN.
- Investigator messages do not see reporter's IP or session.
- Triage / assignment require `2fa:fresh`.

### 4.3 Documents module (DMS)

**Components:**
- `DocumentController`, `DocumentService`, `DocumentComposerService`, `DocumentConversionService`, `DocumentRenderService`, `DocumentRoutingService`
- Models: `Document`, `DocumentVersion`, `DocumentRoute` (approval chain), `DocumentAnnotation`, `DocumentEvent`
- Routing supports sequential approvers with act-on-route endpoint.
- Composer renders HTML → PDF on institutional letterhead via FPDI overlay.
- Downloads require signed URL (5-min expiry).
- Models use UUID route key — links shareable without ID guessing.

### 4.4 RBAC module

Three-tier evaluation order in `User::hasPermission(string $slug)`:

```
1. legacy ROLE_PERMISSIONS[$user->role->value]  → wildcard '*' for super_admin
2. DB roles via $user->roles → permissions, including dept-scoped pivots
3. per-user JSON $user->permissions array
```

Cache: 60 s per (user_id, slug) tuple. Invalidated on any change to `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `users.permissions`.

### 4.5 Audit module (tamper-evident)

- `AuditTrail` middleware captures: user_id, route, method, params (PII-masked), result code.
- Dispatches `WriteAuditLog` job on the `audit` queue.
- Job computes `hash = sha256(prev_hash || canonicalised_row)` and inserts; the `prev_hash` is fetched within a serializable transaction to prevent chain forks.
- Verification: `php artisan audit:verify` (planned) walks the chain and reports any breakage.

---

## 5. Data Architecture

### 5.1 Storage strategy

| Data class | Store | Notes |
|---|---|---|
| Relational state | PostgreSQL 15 (prod) / SQLite (dev) | All core entities |
| Sessions | DB by default; Redis recommended in prod | `SESSION_DRIVER=redis` |
| Cache | Redis (prod) / file (dev) | `CACHE_STORE=redis` |
| Queues | Redis (prod) / database (dev) | Horizon dashboard in prod |
| Files (payslip PDFs, documents, avatars) | `storage/app/public` (dev) → S3-compatible (prod) | Signed-URL access |
| Search (future) | Postgres `pg_trgm` / Meilisearch | Documents + employees |
| Analytics | `analytics_events` table | OLAP export to Metabase/BI (P7) |

### 5.2 Schema landmarks

- **80+ migrations** through `2026_06_05_000001` covering everything from `users` through `sso_login_attempts`.
- **24+ first-class entities** in `app/Models/` (incl. Employee, Department, Position, Grade, Step, EstablishmentCeiling, PayrollRun/Line, LoanAccount/Product/Repayment, OffboardingCase, ClearanceItem, FinalSettlement, ReviewCycle, Goal, Review, PerformanceContract, CalibrationSession, PIP, Course, Enrolment, Certification, Document, Asset, BenefitPlan, Policy, WhistleblowerReport, Disbursement, ApiTokenMetadata, WebhookSubscription, SsoIdentityProvider, …).

### 5.3 Data flow patterns

- **Read path:** Controller → Service (light) → Model query → Resource → Inertia/JSON.
- **Write path:** Controller → FormRequest → Service (transactional) → Event → Queue → Listener.
- **External inbound:** Webhook → signature middleware → Controller → Service → DB + event.
- **External outbound:** Service emits event → queued Listener → integration call → `integration_events` row.

### 5.4 Caching strategy

| Cache | Key | TTL | Invalidator |
|---|---|---|---|
| Dashboard stats | `dashboard.stats.{user_id}` | 60 s | Time-only |
| Permission check | `perms.{user_id}.{slug}` | 60 s | On RBAC mutation |
| Sidebar nav | `nav.{user_id}` | 5 min | On role change |
| Public holidays | `holidays.{year}` | 24 h | Manual on edit |

---

## 6. Security Architecture

### 6.1 Layered controls

```
                          ┌─────────────────────────┐
                          │  Reverse proxy / TLS    │ Layer 0  network/TLS
                          └────────────┬────────────┘
                                       │
                          ┌────────────▼────────────┐
                          │  Trusted proxies        │ Layer 1  identity of caller
                          │  CSRF tokens            │
                          └────────────┬────────────┘
                                       │
                          ┌────────────▼────────────┐
                          │  Auth middleware        │ Layer 2  authentication
                          │  (session / SSO / token)│
                          │  2FA gate (2fa:fresh)   │
                          └────────────┬────────────┘
                                       │
                          ┌────────────▼────────────┐
                          │  Permission middleware  │ Layer 3  authorisation
                          │  Policies               │
                          │  Dept scoping           │
                          └────────────┬────────────┘
                                       │
                          ┌────────────▼────────────┐
                          │  Audit middleware       │ Layer 4  observability
                          │  (queued, hash-chained) │
                          └────────────┬────────────┘
                                       │
                          ┌────────────▼────────────┐
                          │  Application logic      │ Layer 5  domain
                          └─────────────────────────┘
```

### 6.2 Sensitive operation pattern

All destructive operations follow the same pattern:

```
Route::post('...', [Ctrl::class, 'doIt'])
    ->middleware(['permission:slug', '2fa:fresh']);
```

Examples: payroll approve/reverse/disburse, loan decide/disburse, off-boarding settlement approve/complete, calibration apply, whistleblower triage/assign, AG report generate, SSO provider mutate, privacy fulfil, AI assistant write, mass message send.

### 6.3 Anti-fraud

- **Identity verification** (Ghana Card) on hire and on off-boarding.
- **Establishment ceilings** prevent more position assignments than the approved establishment allows.
- **Tamper-evident audit chain** catches retroactive edits.
- **Disbursement reconciliation** matches payout response against the approved run.
- **Whistleblower channel** with 6/min rate limit and reporter-PIN-based tracking.

---

## 7. Integration Architecture

### 7.1 Integration providers

| Direction | Provider | Purpose | Auth |
|---|---|---|---|
| Inbound webhook | WhatsApp Business | Inbound messages | HMAC-SHA256 + verify-token |
| Inbound webhook | Zoho | Contact sync | shared-secret header |
| Inbound webhook | E-sign provider | Envelope completion | provider-spec |
| Inbound webhook | MS Graph | Calendar / Teams | clientState + validationToken |
| Inbound webhook | Google | Calendar / Drive | channel-token |
| Inbound webhook | Slack | Slash commands | v0-signature + 5-min replay window |
| Inbound webhook | Biometric devices | Clock-in/out | per-device HMAC |
| Inbound webhook | Hubtel SMS | Delivery receipts + inbound | HMAC |
| Inbound webhook | Hubtel USSD | Menu callbacks | HMAC |
| Outbound | NIA / Ghana Card | Identity verification | provider-spec API key |
| Outbound | GhIPSS | Bulk credit disbursement | provider-spec, file-based |
| Outbound | MoMo (MTN/Vodafone/AirtelTigo) | Mobile money disbursement | OAuth client credentials |
| Outbound | SSNIT / GRA / NPRA / NHIA | Statutory remittance | provider-spec / portal upload |
| Outbound | Tenant webhooks | Event push | HMAC signature, retry w/ exponential backoff |
| SSO | SAML 2.0 IdPs | Enterprise sign-in | XML signature |
| SSO | OIDC IdPs | Enterprise sign-in | id_token / PKCE |

### 7.2 Inbox / outbox

- **Inbox:** `integration_events` table with `(provider, external_id)` unique index — guarantees idempotency for inbound webhooks.
- **Outbox:** `webhook_subscriptions` + `webhook_deliveries` — outbound deliveries with retries and signature.

---

## 8. Frontend Architecture

### 8.1 Component model

- **Pages** (route targets): `resources/js/Pages/<Module>/<Page>.vue`.
- **Layouts**: `AuthenticatedLayout.vue` (sidebar shell), `GuestLayout.vue` (auth shell).
- **Shared primitives**: `StatusBadge`, `EmptyState`, `Pagination`, `SlidePanel`, `KanbanBoard`, `StatCard`, `ProgressRing`, `Modal`, `Tabs`.
- **Inertia link** for client-side nav; **`useForm`** for state.
- **Ziggy** for route generation on the client.
- **Chart.js + vue-chartjs** for analytics charting (finance analytics dashboard, reports).

### 8.2 Design system

"Sovereign Precision":

- Palette: `brand-navy #0a2647` (primary), `brand-blue #205295` (action), `brand-gold #ffd700` (≤5% accent), `brand-cyan #12d9e3` + `brand-magenta #d912e3` (chart sparks only).
- Typography: Plus Jakarta Sans (sans), Instrument Serif (display), Material Symbols Outlined (icons).
- Motion: `animate-reveal-up`, `animate-scale-in`, `animate-slide-up-fade`, `animate-shimmer`; staggered `animation-delay`.
- Atmosphere: `bg-gradient-deep-mesh` for hero / login left column.

### 8.3 Accessibility

- Skip link, ARIA live region, labelled controls, focus-visible rings.
- WCAG 2.1 AA target; checklist at [wcag_aa_checklist.md](wcag_aa_checklist.md).
- `php artisan accessibility:audit` (in repo) flags critical issues.

### 8.4 Internationalisation

- `users.locale` column drives initial locale.
- `lang/en/*.php` + planned `lang/tw|ee|ga|dag/*.php`.
- `LocaleController::update` flips per-user preference.
- Date / currency rendered as `GHS`, `Africa/Accra`.

### 8.5 PWA + offline

- Service worker pre-caches the shell + last seen payslip.
- `/offline` Blade view served when the network fails on a page that wasn't cached.

---

## 9. Operational Architecture

### 9.1 Environments

| Env | DB | Cache/Queue | Purpose |
|---|---|---|---|
| Local | SQLite (file) | sync / db | Developer machine |
| CI | SQLite | sync | GitHub Actions (PHP 8.4) |
| Staging | Postgres 15 | Redis 7 | Sandbox + integration tests |
| Production | Postgres 15 | Redis 7 + Horizon | Live |

### 9.2 Observability

- **Errors:** Sentry (`logging.channels.sentry`).
- **Queues:** Horizon dashboard at `/horizon`.
- **Audit:** `audit_logs` (queryable via Audit Logs UI; export to compliance tooling).
- **Health:** `/health` endpoint returns 200 + JSON of DB / Redis / disk.
- **Performance:** Sentry traces in prod; Pail tail in dev.

### 9.3 Backups & DR

- `spatie/laravel-backup` → daily Postgres dump + `storage/app` snapshot → S3 with KMS encryption.
- RPO 24 h, RTO 4 h.
- Quarterly restore drill (`php artisan backup:restore`).

### 9.4 Release engineering

- Trunk-based on `main`; feature branches; PRs gated on Pest + Pint + Vite build.
- Tagged releases (`v2.0.0`, `v2.1.0` …) deployed via the runbook in `deploy/`.
- Database migrations always reversible; rollouts use `php artisan down --secret=<token>` for any breaking change.

---

## 10. Architectural Decision Records (ADRs — abbreviated)

| ADR | Decision | Rationale |
|---|---|---|
| **ADR-001** | Inertia.js over a separate SPA + REST | Single auth/audit story, less network surface, server stays authoritative |
| **ADR-002** | Three-tier RBAC over Spatie | We added DB roles on top of an existing enum; full migration would break seeders. `User::hasPermission()` unifies it. |
| **ADR-003** | Staff ID + Name login over email | CIHRM Ghana operational reality — staff identify by Staff ID, not email |
| **ADR-004** | Tamper-evident audit chain over signed-log shipment | Self-contained verifier; no external dependency for compliance |
| **ADR-005** | Modular monolith over microservices | One deployable; lower TCO for public-sector adopters |
| **ADR-006** | Queue-everything for side effects | Notifications/audit/integrations must not block user response |
| **ADR-007** | Pest 4 over PHPUnit | Better DSL, parallel runner, Laravel-first plugin |
| **ADR-008** | Postgres in production (not MySQL) | JSONB, partial indexes, `gen_random_uuid()`, RLS-ready for multi-tenant |
| **ADR-009** | Tailwind 3 (not 4) | Stable config-file semantics; Tailwind 4 vite plugin held back |
| **ADR-010** | DomPDF + TCPDF + FPDI for PDFs | DomPDF for payslips, TCPDF/FPDI for letterhead overlays |
| **ADR-011** | Sanctum personal access tokens for API v1 | First-class Laravel integration; per-token scopes |
| **ADR-012** | `2fa:fresh` middleware over per-action checks | One implementation, applied consistently to every destructive op |
| **ADR-013** | USSD + SMS via Hubtel | Local provider, GHc-priced, NITA-friendly |
| **ADR-014** | SAML 2.0 + OIDC SSO | Coverage of both gov (SAML-prevalent) and enterprise (OIDC) IdPs |
| **ADR-015** | OpenAPI YAML in repo + Stoplight Elements | Spec-as-source-of-truth, rendered docs, no separate doc pipeline |

---

## 11. Cross-References

- [PRD.md](PRD.md) — Product requirements
- [TRD.md](TRD.md) — Technical requirements (libraries, NFRs, constraints)
- [SYSTEM_DESIGN_DIAGRAMS.md](SYSTEM_DESIGN_DIAGRAMS.md) — Mermaid diagrams (context, container, sequence, ERD)
- [PROJECT_STATE.md](PROJECT_STATE.md) — Live build status
- [implementation_plan.md](implementation_plan.md) · [implementation_plan_2.md](implementation_plan_2.md) — Phase plans
- [deployment_production.md](deployment_production.md) — Production runbook
- [wcag_aa_checklist.md](wcag_aa_checklist.md) — Accessibility verification
- [credentials.md](credentials.md) — Seeded login accounts
