# CIHRMS — Project State

> **Snapshot date:** 2026-06-20 (post Finance Universal Posting + Fiscal Close + Financial Statements + Budgeting + lifecycle/talent modules + functional-QA pass)
> **Stack:** Laravel 13.8 (PHP 8.3 → CI 8.4 → running on 8.5.5 locally) · Vue 3 · Inertia.js v2 · Tailwind CSS v3 · Chart.js + vue-chartjs (first charting library) · PostgreSQL (production) / SQLite (dev) · Pest 4
> **Repo:** Under git on `main`; remote `origin → https://github.com/josephnadi/CIHRMS-MVP.git`; CI on PHP 8.4 via GitHub Actions.
> **Architecture:** Enum → FormRequest → Service → Event → Listener → Resource → Inertia Page
> **Design system:** "Sovereign Precision" — obsidian sidebar, cobalt gradients, RGB-triplet stat cards, card-lift / btn-shimmer / animate-reveal-up motion. Reference pages: Employees/Index (651 LOC), Tickets/Index (472 LOC).

---

## 1. Headline

The platform has grown substantially since the 2026-05-23 snapshot: a full Finance/GL accounting backbone (Universal Posting, Fiscal Close, Financial Statements, Budgeting — the GL is now the single source of all monetary throughput), new lifecycle + talent modules (Onboarding, LMS compliance enforcement, course prerequisites), Tier-3 voluntary pension + statutory remittance tracking, and a functional-QA hardening pass that fixed 25 real CRUD/form defects. The application is feature-complete end-to-end across backend, RBAC, and Vue frontend for all core modules plus these post-MVP build-outs. As of this snapshot (2026-06-20):

- **Backend:** services, FormRequests, resources, controllers, signature-verified webhooks (Paystack + WhatsApp + Zoho + e-sign + MS Graph + Google + Slack), DB-backed RBAC with org-scope asset policies (stamps, letterheads, watermarks), and a GL posting engine that funnels payroll, loans, disbursements, AP, AR, bank adjustments, and final settlements through balanced double-entry journals — all written and wired.
- **Frontend:** every module page that the controllers reference exists in [resources/js/Pages/](../resources/js/Pages/), including the new Settings asset libraries (Stamps, Letterheads, Watermarks), the Finance sub-pages (AR/AP, Customers/Vendors, Journal Explorer, Statements, Reconciliation, PaymentIntents), and the new Finance pages (Posting Rules, Fiscal Calendar, Financial Statements, Budgets, Analytics Dashboard with Chart.js charts) plus Onboarding and LMS compliance UIs.
- **Tests:** **1,414 Pest tests / ~4,925 assertions passing** on SQLite + the PostgreSQL CI matrix. Coverage spans every module — auth, employees, leave, tickets, complaints, recruitment, performance, payments, payroll, documents (annotations + stamps + letterheads + watermarks + shares), finance (F1–F5 + Universal Posting + fiscal close + financial statements + budgeting + settlement→GL + analytics), onboarding, LMS compliance + prerequisites, statutory remittance + Tier-3 pension, policies, audit, and webhook signature verification for all six integration providers.

### Build-outs shipped since 2026-05-23 (June 2026)

All items below are merged to `main`.

**Finance — "the GL as the single source of all monetary throughput" (4 complete phases):**

- **Universal Posting** — `PostingService` is the single GL choke point (`PostingDocument` / `PostingLine` value objects, `AccountResolver`, a `posting_accounts` map table with an admin UI at **Finance → Posting Rules**, idempotency on `source_type` + `source_id` + `source_purpose`). Payroll, loans, disbursements, AP, AR, and bank adjustments now all post balanced double-entry journal entries through it. Permission `finance.posting_rules.manage`.
- **Fiscal Periods & Close** — `fiscal_years` / `fiscal_periods` + `FiscalCalendarService` (12 months + a period-13 Adjustment), closed-period posting guard (`ClosedPeriodException`), journal immutability, `PeriodCloseService` (close / reopen / lock), and `SubledgerReconciliationService` (AP 2100 / AR 1200 / loans 1300 vs GL with a pre-close variance gate). Page **Finance → Fiscal Calendar**. Permissions `finance.period.view` / `close` / `reopen` / `lock`.
- **Financial Statements** — `LedgerBalanceService` engine + Trial Balance, Statement of Financial Activities (P&L), Statement of Financial Position (Balance Sheet), and Statement of Cash Flows (direct + indirect, both reconcile), with GL drill-down, prior-period comparatives, and CSV / PDF export (`barryvdh/dompdf`). Permission `finance.reports.view`.
- **Budgeting** — annual budgets per GL account (`budgets` / `budget_lines`, draft → approved, even monthly spread), Budget-vs-Actuals (YTD variance, favourable-by-type), and soft non-blocking controls + over-budget alerts. Permission `finance.budget.manage`.
- **Settlement → GL (off-boarding)** — final settlement posts a balanced accrual JE on approval (termination-benefit expenses 5130–5134; CR PAYE / loan principal + interest / deductions / net pay), a payment JE on mark-paid, reversal / un-posting, and additive disbursement tracking. New `JournalSourceType::FinalSettlement`.
- **Finance Analytics Dashboard** — `FinanceAnalyticsService` (KPIs: cash position, YTD income / expenditure / surplus, AP / AR outstanding, budget variance, latest payroll cost; monthly trend series + top expenses + AR/AP aging + budget-by-type) → `Finance/Analytics/Dashboard.vue` with Chart.js charts, fiscal-year / date filters, and CSV / PDF + per-chart PNG export. Dedicated permission `finance.analytics.view`.

**Payroll / statutory:**

- **Statutory remittance tracking** — a mark-a-return-filed write path (permission `statutory.remit`), due date = payroll period-end + 14 days, and an overdue posture surfaced on the dashboard + the payroll-run returns tab.
- **Tier-3 voluntary pension** — a per-employee percentage election (`employees.tier3_rate` + `tier3_trustee_id`), `Tier3Calculator` (relief up to the 16.5% Tier-2 + Tier-3 combined cap reduces PAYE chargeable; excess taxed), credited to GL 2230, with a per-trustee Tier-3 statutory schedule.

**Lifecycle / talent:**

- **Onboarding lifecycle** — a new module mirroring off-boarding: `OnboardingCase` / `OnboardingTask` templated checklist, auto-initiate on `EmployeeCreated` (`hire_date`), auto-enrol into published Onboarding courses, `onboarding.*` perms, Index / Show pages + nav.
- **LMS compliance enforcement** — `ComplianceRequirement` (course mandatory for all-staff / role / department + `due_in_days`), `ComplianceAssignmentService` (idempotent auto-assign + due dates), an on-hire listener + `compliance:sync` + `compliance:remind` commands, and an overdue dashboard + My-Learning badges. Permission `learning.compliance.manage`.
- **Course prerequisites** — self-referential `course_prerequisites`; self-enrol is blocked until prerequisites are completed (admin / automated assignment bypasses), with a catalog lock + admin authoring.

**Quality:**

- **Functional QA pass (2026-06-20)** — a static wiring audit (all frontend `route()` refs vs registered routes; all Inertia pages exist) + 4 parallel module audits fixed 25 form / CRUD defects, incl. broken leave apply (missing `employee_id`), employee-document upload / delete, governance acknowledge no-op, the loan-disburse date trap, the Chart-of-Accounts archive 500, the leave balance engine (per-type statutory entitlements + working-days basis, maternity in calendar days), the disbursement dispatch / reconcile UI (payroll money can now actually be sent to providers), the shift edit / delete UI, and leave decision-comment + attachment persistence.

### Post-MVP build-outs shipped in the 2026-05-23 snapshot

- **Documents v2** (PR #15, merged 2026-05-23) — manipulable annotations (drag/resize/rotate signatures and stamps with a Completed-route lock), stamp asset library, letterhead templates (replacing the hardcoded `public/img/letterhead.png`), watermark templates (per-document `watermark_id` + `none|on_burn|always` mode). New tables: `stamp_assets`, `letterhead_templates`, `watermark_templates`. New permission: `document_assets.manage`. New routes: `PATCH /documents/{document}/annotations/{annotation}`, `/settings/{stamps,letterheads,watermarks}` CRUD.
- **Finance F1–F5** — Chart of Accounts + Org Bank Accounts (F1), Accounts Payable + Journal Engine (F2 — PR #10), Accounts Receivable + customer statements (F3 — PR #12), **Paystack hosted-checkout gateway** (F4 — PR #14: payment intents, webhook signature verification, refunds, idempotency keys), Bank Reconciliation (F5: CSV/OFX/MT940 import + 3-tier matching + bank-adjustment journal entries).
- **Finance C1–C3 hardening** (PRs #20 + #21, merged 2026-05-23) — 2FA on AP payments + journal.store + AR receive/write-off; `lockForUpdate` on credit-balance reads; `SequenceService::next()` replacing the count()+1 race in finance reference generation.
- **Internal Chat** — 1:1 messaging with optimistic send, 4-second polling, day separators, post-send dedupe, and a single-column scrollable directory (PR #24 open).
- **Sound packs** — pluggable `musical | cinematic | gamified` with file-override architecture (PR #25 open). Drop MP3s at `public/sounds/<pack>/<key>.mp3` and `useSound` prefers the real audio over the synth.

### Open pre-launch PRs (mergeable, CI green)

| PR  | Title |
|-----|-------|
| #22 | feat(learning): wire SkillsMatrix Add Skill to real catalog endpoint (I3) |
| #23 | docs: pre-launch operational notes — kiosk face-scan + MT940 (C4 + I5) |
| #24 | Chat: single-column list + post-send dedupe + new-thread sort |
| #25 | Sound: add gamified arcade pack + file-override for production audio |
| #26 | feat(finance): printable bank-reconciliation report + close P1 (P1+P2) |
| #27 | feat(finance): bulk operator actions — bulk refund + reconciliation re-match (P3) |

**(P6 complete — production hardening shipped 2026-05-16):** rate-limits on public endpoints (careers `5/min`, self-clock `10/min`), `password_must_change` gate via `ForcePasswordChange` middleware, `SESSION_SECURE_COOKIE` + `APP_TRUSTED_PROXIES` documented in `.env.example`, `sentry` log channel + `config/backup.php` skeleton + `deploy/supervisor/*.conf` units, and `laravel/pao` excluded from auto-discovery to unblock PHP 8.5 boot. Optional packages (`sentry/sentry-laravel`, `spatie/laravel-backup`) are deferred to deploy-time install; see [deploy/README.md](../deploy/README.md).

**(UI redesign campaign complete — shipped 2026-05-16):** 18 module pages elevated from baseline (84–215 LOC stubs) to gold-standard "Sovereign Precision" treatment (~440–740 LOC each), matching the Employees/Tickets reference implementations. Build clean (Vite 19.93s, 0 warnings). Two waves of parallel design agents:
- **Wave 1** (`677e3a2`, 10 files, +4,630/−920): Attendance × 3, Loans × 2, Off-boarding × 2, Performance group 2 (Contracts / Calibration / PIPs).
- **Wave 2** (`f3cdb54`, 8 files, +2,896/−1,159): Learning × 3 (Catalog / MyLearning / SkillsMatrix), Performance group 1 (Index / Goals / Reviews / NineBox), plus a PIPs follow-up tweak.

**Bonus modules integrated alongside the five planned phases:** Loans & Advances (P3-side), Off-boarding & Clearance (P3-side), Whistleblower & Auditor-General Reporting (P4-side), Performance Contracts + Calibration + PIPs (P5-side), Disbursement payment-channel (P5-side), Announcements + Privacy/GDPR + Webhooks + Versioned API v1 (P6-side). Every module discovered in the working tree was committed with proper attribution.

The remaining residual gaps are:

1. **Tests cannot execute locally on PHP 8.5.5** because [`vendor/laravel/pao`](../vendor/laravel/pao/) calls `stream_filter_remove()` in a way that PHP 8.5 now rejects. Auto-discovery is disabled in `composer.json` so artisan boots cleanly, but Pest's own runtime still loads `pao`. Tests are syntactically valid Pest 4 / Laravel 13 code and pass on PHP 8.3 / 8.4 in CI. See §5.
2. Three sparkline metrics (`pending_payments`, `payslips_paid`, `applicants`) emit zeros until `PaymentCreated` / `PaymentMarkedPaid` / `ApplicantCreated` events are wired in their services — small follow-up.
3. Sentry and `spatie/laravel-backup` are wired at the config level but the packages themselves are deferred to deploy-time install (`composer require sentry/sentry-laravel spatie/laravel-backup`).

---

## 2. Layer-by-layer status

| Layer | Count | Status | Notes |
|---|---|---|---|
| Migrations | 66+ | ✅ | Through `2026_05_31_000001` — adds policies/policy_versions/policy_acknowledgements, certifications.reminder_sent_at, performance_contracts, calibration_sessions, performance_improvement_plans, disbursements |
| Enums | 15 | ✅ | Adds GoalCadence/GoalStatus/ReviewCycleStatus/ReviewStatus/ReviewType on top of base 10 |
| Models | 24 | ✅ | SoftDeletes, casts, scopes, relationships |
| FormRequests | 9 dirs | ✅ | Grouped by module under `app/Http/Requests/<Module>/` |
| Services | 9 | ✅ | Includes PerformanceService + PayrollCalculator |
| Events | 10 | ✅ | Includes performance, payroll, recruitment envelope events |
| Listeners | 6 | ✅ | Analytics, notifications, Zoho contact sync, payslip upload, offer envelope, leave-manager notify |
| Jobs | 1 | ✅ | `WriteAuditLog` (queued via `audit` queue) |
| Middleware | 5 | ✅ | `AuditTrail`, `EnsurePermission`, `EnsureRole`, `HandleInertiaRequests`, `VerifyWebhookSignature` |
| Policies | 5 | ✅ | Department, Employee, LeaveRequest, Payment, Ticket |
| Resources | 13 | ✅ | All major modules covered |
| Controllers | 17 | ✅ | 57 actions in total |
| Routes | 93 named | ✅ | Public careers + webhooks + module routes + admin/integrations |
| Vue Pages | 17 module dirs | ✅ | All Index/Show pages substantial; Performance now has Index + Goals + Reviews + NineBox |
| Dashboard.vue | ~2,725 LOC | ✅ | KPIs + sparklines + activity feed all wired to real `DashboardService` data; duplicated module sections deleted (Assets/Benefits/Learning/Governance); inline forms replaced with quick-action links; zero `comingSoon` calls |
| Tests | — | ✅ | **1,414 Pest tests / ~4,925 assertions passing** on SQLite + the PostgreSQL CI matrix |

---

## 3. Modules — final state

### Backend (all eight modules complete)
- **Employees** — `EmployeeController` (8 actions), policies, expanded profile fields, documents, skills
- **Leave** — requests, balances, status workflow, manager-notification listener
- **Tickets** — service desk with priority + policy
- **Payments / Payroll** — `PaymentService` + `PayrollCalculator`, payslip upload listener, analytics endpoint
- **Recruitment** — public careers portal, applicants, offer envelope flow, Zoho contact sync
- **Complaints** — governance/complaint log + public tracking
- **Performance** — review cycles, goals, goal check-ins, reviews, 9-box matrix
- **Audit Logs** — queued write, controller, RBAC-gated viewing
- **Notifications** — channels + delivery + multi-listener fan-out
- **Integrations** — OAuth tokens, event log, inbound webhook signature verification for WhatsApp / Zoho / e-sign / MS Graph / Google / Slack

### Frontend (all module pages present and substantial)

Pages marked **★** were redesigned in the 2026-05-16 UI campaign to the "Sovereign Precision" gold-standard.

| Module | Pages | Lines | Notes |
|---|---|---|---|
| Employees | Index, Show | 651, 789 | Reference implementation (gold-standard source) |
| Leave | Index, Show | 1,181, 354 | |
| Tickets | Index, Show | 472, 242 | Reference implementation (gold-standard source) |
| Payments | Index, Show | 1,155, 271 | Payslip preview + analytics dashboard |
| Notifications | Index, Channels | 184, 158 | Inbox + per-channel consent UI |
| AuditLogs | Index | 166 | Compliance trail |
| Reports | Index | 288 | Live sparkline previews + XLSX export config |
| Recruitment | Index, Show, Applicants | 256, 280, 371 | Kanban pipeline included |
| Complaints | Index, Track | 391, 98 | Public reference-lookup form |
| **Performance** ★ | Index, Goals, Reviews, NineBox | 451, 733, 698, 444 | Group 1 redesigned in Wave 2 |
| **Performance / Contracts** ★ | Index | 511 | Dual-signature checkpoint cards (Wave 1) |
| **Performance / Calibration** ★ | Index | 382 | Distribution bar + facilitator cards (Wave 1) |
| **Performance / PIPs** ★ | Index | 426 | Severity-tinted left border, mentor avatar (Wave 1 + W2 tweak) |
| **Attendance** ★ | MyAttendance, Shifts, Corrections | 526, 650, 473 | Live clock hero, GPS clock button, kanban corrections (Wave 1) |
| **Learning** ★ | Catalog, MyLearning, SkillsMatrix | 676, 627, 519 | Discovery hero, learner dashboard, role-vs-staff heatmap (Wave 2) |
| **Loans** ★ | Index, Show | 525, 494 | Repayment progress + 4-tab detail + 2FA-gated disbursement (Wave 1) |
| **Offboarding** ★ | Index, Show | 479, 834 | LWD countdown, multi-area clearance, final settlement (Wave 1) |
| Careers | Show | — | Public unauthenticated job posting view |
| Profile | Edit + tabs | — | Self-service employee portal |
| Departments | Index | 217 | |
| Admin/Integrations | Index | — | Super-admin only |

---

## 4. Test suite

Ten Pest 4 feature-test files under [tests/Feature/](../tests/Feature/):

| File | Coverage |
|---|---|
| `Auth/` (4 files, pre-existing) | Login / register / password / verification |
| `ProfileTest.php` (pre-existing) | Profile CRUD + account deletion |
| `TicketsTest.php` | Create, list, assign, resolve (stamps `resolved_at`), delete, permission denial |
| `LeaveTest.php` | Submit, approve (stamps approver + increments `LeaveBalance.used_days`), reject (no balance touched) |
| `ComplaintsTest.php` | Auto-generated `CMP-` reference, status update, public tracking |
| `RecruitmentTest.php` | Public apply, HR job posting, applicant pipeline transitions |
| `EmployeesTest.php` | List, show, create-with-inline-user, update, soft-delete, deny-path |
| `PaymentsTest.php` | Record, mark paid (stamps `paid_at`), payslip preview, payslip generation w/ items |
| `PerformanceTest.php` | Cycle create + close, goal create, check-in **amber auto-flips to AtRisk**, self-review draft → submit → ack, empty 9-box returns 9 cells |
| `PoliciesTest.php` | Deny paths for Employee, Ticket, LeaveRequest, Payment policies |
| `WebhookSignatureTest.php` | All six providers — WhatsApp HMAC + verify-token, Zoho shared-secret header, MS Graph clientState + validationToken, Google channel-token, Slack v0-signature + replay-window rejection |

---

## 5. Gaps and risks

1. **Test execution blocked on PHP 8.5.5.** `vendor/laravel/pao/src/Execution.php:80` calls `stream_filter_remove()`, which PHP 8.5 now treats as a fatal warning. The tests run on PHP 8.3 / 8.4 (composer requires `^8.3`). Three fixes:
   - Downgrade local PHP to 8.4.
   - Pin or remove `laravel/pao` until it ships 8.5 support.
   - Run tests in CI / Docker on 8.4 (PHPUnit Docker image).
2. **(Resolved 2026-05-16)** `cihrms-mvp/` is now under git on `main`, remote `origin → github.com/josephnadi/CIHRMS-MVP.git`, with CI on PHP 8.4.
3. **(Resolved 2026-05-15)** Dashboard decorative literals replaced with real `DashboardService::timeSeries()` data + cached `getRecentActivityFeed()`. The duplicated module sections (Assets/Benefits/Learning/Governance) and 5 inline create forms were deleted; sidebar nav routes to dedicated `Pages/<Module>/Index.vue`.
4. **Notification real-provider delivery untested.** Signature verification is now under test; actual delivery to WhatsApp / Slack / MS Graph / Google for an outbound message is still unverified.
5. **In-flight uncommitted platform workstreams** in the working tree (not yet reviewed or committed in this session): SSO (identity providers + login attempts + identity links), I18n (locale switching + lang files), Messaging (SMS + USSD inbound webhooks + outbound channels), API v1 (versioned REST + OpenAPI YAML + scoped tokens), PWA (service worker + manifest + offline view), Accessibility (skip link + ARIA live announcer + audit command + WCAG checklist), plus ~79 modified files and 116 untracked files. These are coherent feature bundles but need curated commits before they can be landed.

---

## 6. Recommended next steps

In leverage order:

1. **Push redesign commits** (`677e3a2`, `f3cdb54`) to `origin/main` so the UI campaign is durable.
2. **Curate the in-flight platform workstreams** (see gap #5) into themed commits. Suggested grouping: (a) SSO bundle, (b) Messaging / SMS / USSD bundle, (c) API v1 + OpenAPI bundle, (d) I18n + locale, (e) PWA + Accessibility. Each should land with a build check and any tests that ship alongside.
3. **Resolve the PHP 8.5 / pao incompatibility** so tests can run locally (downgrade to 8.4, pin/remove pao, or run via Docker).
4. **Real-provider integration tests** (outbound) for the messaging listeners — sandbox tokens or Mock HTTP fakes.
5. **Replace the residual sparkline literals** in Dashboard.vue (`pending_payments`, `payslips_paid`, `applicants`) with real time-series from `DashboardService` once the event wiring lands.

---

## 7. File-system landmarks

- Controllers: [app/Http/Controllers/](../app/Http/Controllers/)
- Services: [app/Services/](../app/Services/)
- Models: [app/Models/](../app/Models/)
- Enums: [app/Enums/](../app/Enums/)
- Events: [app/Events/](../app/Events/) · Listeners: [app/Listeners/](../app/Listeners/)
- Policies: [app/Policies/](../app/Policies/) · Middleware: [app/Http/Middleware/](../app/Http/Middleware/)
- Routes: [routes/web.php](../routes/web.php) (265 LOC, 93 named routes)
- Migrations: [database/migrations/](../database/migrations/) (32 files)
- Inertia pages: [resources/js/Pages/](../resources/js/Pages/)
- Performance sub-pages: [Goals.vue](../resources/js/Pages/Performance/Goals.vue), [Reviews.vue](../resources/js/Pages/Performance/Reviews.vue), [NineBox.vue](../resources/js/Pages/Performance/NineBox.vue)
- Tests: [tests/Feature/](../tests/Feature/)
- Existing plan: [docs/implementation_plan.md](implementation_plan.md)
- PRD: [docs/Cihrm Hrms Product Requirements Document Prd.pdf](Cihrm%20Hrms%20Product%20Requirements%20Document%20Prd.pdf)
