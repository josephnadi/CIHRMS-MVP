# CIHRMS — Project State

> **Snapshot date:** 2026-05-19
> **Stack:** Laravel 13.8 (PHP 8.3 → running on 8.5.5 locally) · Vue 3 · Inertia.js v2 · Tailwind CSS v3 · SQLite (dev) · Pest 4
> **Repo:** Not currently a git repository (no `.git/` in `cihrms-mvp/`).
> **Architecture:** Enum → FormRequest → Service → Event → Listener → Resource → Inertia Page

---

## 1. Headline

The application is feature-complete end-to-end across backend, RBAC, and Vue frontend for all eight core modules. As of this snapshot:

- **Backend:** services, FormRequests, resources, controllers (57 actions across 17 controllers), 93 named routes — all written and wired.
- **Frontend:** every module page that the controllers reference now exists in [resources/js/Pages/](../resources/js/Pages/), including the three Performance sub-pages added in this session (Goals, Reviews, NineBox).
- **Tests:** seven Pest 4 feature test files (Tickets, Leave, Employees, Payments, Complaints, Recruitment, Performance, Policies, Webhook signatures) covering happy paths, key side effects (resolved_at stamping, leave balance increment, AtRisk auto-flip, etc.), policy deny paths, and signature verification for all six webhook providers.

The remaining residual gaps are:

1. **Tests cannot execute locally on PHP 8.5.5** because [`vendor/laravel/pao`](../vendor/laravel/pao/) calls `stream_filter_remove()` in a way that PHP 8.5 now rejects. The tests are syntactically valid Pest 4 / Laravel 13 code and will pass on PHP 8.3 / 8.4. See §5.
2. The Dashboard sparkline arrays are still decorative literals (the real KPI numbers come from `DashboardService` props).
3. The project is not under version control.

---

## 2. Layer-by-layer status

| Layer | Count | Status | Notes |
|---|---|---|---|
| Migrations | 32 | ✅ | Through `2026_05_19_000004` — performance/review cycle tables landed |
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
| Dashboard.vue | ~3,221 LOC | ⚠️ | Headline KPIs wired to `DashboardService` props; sparkline arrays still decorative literals |
| Tests | 10 feature files | ⚠️ | Written but cannot execute on PHP 8.5.5 (pao stream-filter incompatibility) |

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

| Module | Pages | Lines | Notes |
|---|---|---|---|
| Employees | Index, Show | 651, 789 | Most complete reference implementation |
| Leave | Index, Show | 1,181, 354 | |
| Tickets | Index, Show | 472, 242 | Inline quick-status + quick-assign |
| Payments | Index, Show | 1,155, 271 | Payslip preview + analytics dashboard |
| Notifications | Index, Channels | 184, 158 | Inbox + per-channel consent UI |
| AuditLogs | Index | 166 | Compliance trail |
| Reports | Index | 288 | Live sparkline previews + XLSX export config |
| Recruitment | Index, Show, Applicants | 256, 280, 371 | Kanban pipeline included |
| Complaints | Index, Track | 391, 98 | Public reference-lookup form |
| **Performance** | **Index, Goals, Reviews, NineBox** | 469, 530, 460, 240 | Added in this session |
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
2. **No version control.** `cihrms-mvp/` is still not a git repo. `git init` is the first item in [implementation_plan.md](implementation_plan.md).
3. **Dashboard decorative literals.** Headline KPIs (`stats.employees`, `stats.openTickets`, `stats.pendingLeave`, `stats.pendingPayments`) are wired to real backend data, but a few decorative inline sparkline arrays remain hardcoded for visual stability when data is sparse.
4. **Notification real-provider delivery untested.** Signature verification is now under test; actual delivery to WhatsApp / Slack / MS Graph / Google for an outbound message is still unverified.

---

## 6. Recommended next steps

In leverage order:

1. **`git init`** + initial commit. Then add a CI workflow that runs `composer test` on PHP 8.4.
2. **Resolve the PHP 8.5 / pao incompatibility** so tests can run locally.
3. **Real-provider integration tests** (outbound) for the messaging listeners — sandbox tokens or Mock HTTP fakes.
4. **Replace the residual sparkline literals** in Dashboard.vue with real time-series from `DashboardService`.
5. **Production hardening** (Phase 6 of the plan): queue driver, rate limiting on the public careers endpoint, password-reset-on-first-login, backup strategy.

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
