# CIHRMS — Implementation Plan 2 (Phase 1: Government-Grade Foundation)

> **Stack:** Laravel 13.8 · Vue 3 · Inertia.js v2 · Tailwind v3 · SQLite (dev) → PostgreSQL (prod)
> **Architecture:** Enum → FormRequest → Service → Event → Listener → Resource → Inertia Page
> **Scope:** Phase 1 of the government-readiness roadmap (research-backed, 2026-05-15)
> **Duration:** ~8–10 engineering weeks
> **Goal:** Bring CIHRMS to the minimum bar for a credible Ghana MDA / public-sector pitch

This plan is the implementation companion to the gap analysis. It covers **only Phase 1** — the six work-streams whose absence makes any government pitch a non-starter. Phases 2–4 will get their own plans once Phase 1 lands.

---

## What "government-grade" means here

| Driver | Source | Implication for Phase 1 |
|---|---|---|
| Ghana public payroll runs on IPPD2/IPPD3 + HRMIS, integrated with GIFMIS | CAGD / PSC | We must produce a GIFMIS/IPPD-compatible export & statutory return files |
| Ghost-worker fraud costs ~10% of the wage bill monthly; NIA + Ghana Card eliminated 44,707 ghost NSS names | NIA, biometricupdate.com | Every employee record must be Ghana Card–validated; payruns must gate on it |
| Statutory: PAYE (7 brackets), SSNIT 18.5% (13.5/5.5, w/ 2.5% NHIA split), Tier-2 5/5 mandatory, 14-day remittance | GRA, NPRA, ICLG | Payroll engine must be effective-dated and produce monthly returns |
| Data Protection Act 2012 (Act 843), Cybersecurity Act 2020, Auditor-General oversight | DPC, CSA | Tamper-evident audit + 2FA + data residency planning |
| NITA e-Government Interoperability Framework | NITA | Postgres + queue infra + observability before any production deploy |

---

## Phase 1 work-streams

| # | Work-stream | Weeks | Why first |
|---|---|---|---|
| 1 | **Statutory payroll engine** (PAYE, SSNIT, NHIA, Tier-2) | 3 | Without this, nothing else in HR is credibly "Ghana-ready" |
| 2 | **Positions, Grades & Steps (Establishment)** | 2 | Public sector hires against approved posts, not free-form |
| 3 | **Ghana Card / NIA verification adapter** | 1.5 | Anti-fraud gate before anyone reaches a payrun |
| 4 | **Tamper-evident audit log** | 0.5 | Cheap, high-trust signal for Auditor-General review |
| 5 | **PostgreSQL + Horizon + backup baseline** | 1 | SQLite will fail any procurement security review |
| 6 | **Two-factor auth for privileged roles** | 1 | Mitigates the single biggest attack surface |

The six streams are sequenced so #5 lands **between** the schema-heavy #1/#2 (which benefit from a real DB) and the privileged-action streams #3/#4/#6. Streams 3, 4, 6 can be parallelised once #5 is done.

---

## Work-stream 1 — Statutory Payroll Engine (3 weeks)

### 1.1 Domain model

New tables (migrations, all soft-deleted, all effective-dated where applicable):

| Table | Key columns | Notes |
|---|---|---|
| `tax_brackets` | `effective_from`, `effective_to`, `lower_bound`, `upper_bound`, `rate`, `cumulative_tax_at_lower` | One row per bracket per year; `effective_to` nullable for current |
| `statutory_rates` | `code` (e.g. `SSNIT_EMPLOYER`, `SSNIT_EMPLOYEE`, `NHIA_SPLIT`, `TIER2_EMPLOYER`, `TIER2_EMPLOYEE`), `rate`, `effective_from`, `effective_to` | Source-of-truth for all percentages |
| `payroll_runs` | `period_year`, `period_month`, `status` (enum), `gross_total`, `net_total`, `paye_total`, `ssnit_total`, `tier2_total`, `locked_at`, `processed_by` | Aggregate root |
| `payroll_lines` | `payroll_run_id`, `employee_id`, `position_id`, `gross`, `paye`, `ssnit_employee`, `ssnit_employer`, `nhia`, `tier2_employee`, `tier2_employer`, `loan_deduction`, `other_deductions_json`, `net` | Immutable once `payroll_runs.status = approved` |
| `allowances` | `employee_id`, `type` (housing, transport, responsibility, risk, fuel, etc.), `amount`, `is_taxable`, `effective_from`, `effective_to` | Effective-dated |
| `deductions` | `employee_id`, `type` (loan, garnishment, sacco, union dues), `amount_or_pct`, `effective_from`, `effective_to`, `cap_balance` | |
| `statutory_returns` | `payroll_run_id`, `kind` (enum: PAYE, SSNIT, TIER2_TRUSTEE_X), `generated_at`, `file_path`, `submitted_at`, `submitted_by` | One per kind per run |

### 1.2 Enums (`app/Enums/`)

```
PayrollRunStatus: draft, calculating, calculated, approved, paid, reversed
StatutoryReturnKind: paye, ssnit_schedule, tier2_schedule, tier3_schedule
AllowanceType: housing, transport, responsibility, risk, fuel, communication, other
DeductionType: loan_repayment, salary_advance, garnishment, union_dues, sacco, welfare, other
```

### 1.3 Service layer (`app/Services/Payroll/`)

| Class | Responsibility |
|---|---|
| `PayrollService` | Orchestrates run lifecycle: create draft, calculate, approve, lock, reverse |
| `PayeCalculator` | Pure: takes annual-equivalent taxable income → PAYE due (uses `tax_brackets` for the effective month) |
| `SsnitCalculator` | Pure: takes gross basic → 13.5% employer / 5.5% employee, splits 2.5% → NHIA |
| `Tier2Calculator` | Pure: takes gross basic → 5% / 5%; routes per `trustee_id` on employee |
| `AllowanceAggregator` | Sums effective allowances at period date, splitting taxable vs non-taxable |
| `DeductionAggregator` | Sums effective deductions (loans first, then garnishments, then voluntary), capped at net floor |
| `StatutoryReturnGenerator` | Emits CSV/XLSX in GRA / SSNIT / trustee schemas to `storage/app/returns/{year}/{month}/` |

**Key invariant:** every calculator is **pure and effective-dated** — given the same input + same date, returns same output forever. This is what lets historical payruns be reproducible if an audit asks.

### 1.4 Events & Listeners

| Event | Listener | Queue |
|---|---|---|
| `PayrollRunStarted` | `LockEmployeeMutationsForPeriod` | `payroll` |
| `PayrollRunCalculated` | `RecordAnalyticsEvent` | `analytics` |
| `PayrollRunApproved` | `GenerateStatutoryReturns` (PAYE + SSNIT + Tier-2) | `payroll` |
| `PayrollRunApproved` | `NotifyTrusteeOfTier2Schedule` (one notification per trustee) | `notifications` |
| `PayrollRunReversed` | `RecordAnalyticsEvent` | `analytics` |

### 1.5 Form Requests

- `StorePayrollRunRequest` — `period_year`, `period_month`, `department_ids[]` (nullable = whole-organization)
- `ApprovePayrollRunRequest` — adds dual-approval check: requires `payroll.run` and `payroll.approve` separated by different users
- `ReversePayrollRunRequest` — requires reason text + `super_admin` only

### 1.6 Resources & Policies

- `PayrollRunResource`, `PayrollLineResource`, `StatutoryReturnResource`
- `PayrollRunPolicy::view` — `hr_admin` org-wide, `dept_head` scoped to their dept lines, employee sees only own line (via `/profile/pay`)
- `PayrollRunPolicy::approve` — denies the same user who created the draft

### 1.7 New permissions (`User::ROLE_PERMISSIONS` + RolePermissionSeeder)

```
payroll.run            (hr_admin, finance_officer)
payroll.approve        (finance_officer, super_admin)
payroll.reverse        (super_admin)
payroll.view_all       (hr_admin, finance_officer, auditor)
statutory.export       (finance_officer, auditor)
```

### 1.8 UI (Inertia pages under `resources/js/Pages/Payroll/`)

- `Payroll/Runs/Index.vue` — list with status pills + month/year filter
- `Payroll/Runs/Create.vue` — period picker + dept scope
- `Payroll/Runs/Show.vue` — summary cards (gross/net/PAYE/SSNIT/Tier2), tabs: Lines, Returns, Audit
- `Payroll/Returns/Index.vue` — download buttons per kind

### 1.9 Tests (Pest)

- Unit: `PayeCalculator` against 2026 brackets (10 fixture cases incl. boundaries)
- Unit: `SsnitCalculator` (NHIA split correctness)
- Unit: `Tier2Calculator` (rate, trustee routing)
- Feature: full happy-path run on 50 seeded employees, asserting totals and that returns generate
- Feature: dual-approval enforcement (creator cannot approve)
- Feature: reverse flow restores employees to pre-run state and writes audit
- Snapshot: PAYE return CSV matches `tests/Fixtures/paye_return_2026_05.csv`

### 1.10 Acceptance criteria (Work-stream 1)

- A run with the canonical demo data set produces totals that match a hand-calculated spreadsheet to the cedi
- Statutory return CSVs validate against published GRA/SSNIT field formats
- Reversing a run is observable in the audit log and produces no orphan rows
- All new code under `app/Services/Payroll/` has ≥85% line coverage

---

## Work-stream 2 — Positions, Grades & Steps (2 weeks)

Public-sector HR doesn't hire "Jane Doe — Senior Officer." It hires "Jane Doe **into position #4231**, currently vacant on the establishment, grade GS-12 step 3, funded by cost-center 0200-OPS."

### 2.1 Domain model

| Table | Key columns |
|---|---|
| `grades` | `code` (e.g. `GS-12`), `name`, `level`, `min_step`, `max_step` |
| `grade_steps` | `grade_id`, `step` (1..max), `base_salary`, `effective_from` |
| `positions` | `code`, `title`, `grade_id`, `department_id`, `cost_center`, `funding_source`, `status` (enum: vacant, filled, frozen, acting), `is_supervisory`, `reports_to_position_id` (self-FK), `headcount_ceiling` (usually 1) |
| `position_assignments` | `position_id`, `employee_id`, `start_date`, `end_date`, `is_acting`, `step_at_start` |
| `establishment_ceilings` | `department_id`, `grade_id`, `fiscal_year`, `approved_headcount` |

### 2.2 Migrations to existing tables

- `employees`: add `current_position_id`, `current_grade_id`, `current_step`, `step_anniversary_date`
- Backfill: every existing employee gets an auto-generated position based on `job_title` + `department_id`

### 2.3 Enums

```
PositionStatus: vacant, filled, frozen, acting
FundingSource: gog, idf, donor, igf  (Government of Ghana, Internally Generated, etc.)
```

### 2.4 Service layer (`app/Services/Establishment/`)

| Class | Responsibility |
|---|---|
| `PositionService` | Create / freeze / vacate / fill. Enforces `establishment_ceilings` on fill |
| `StepIncrementService` | Nightly job: every employee whose `step_anniversary_date` is today and whose last appraisal ≥ minimum rating gets `current_step + 1` (capped at grade max). Emits `StepIncremented`. |
| `OrgChartService` | Builds nested-set tree from `positions.reports_to_position_id` for visualization |

### 2.5 Integration with payroll (work-stream 1)

`PayrollService::calculateLine($employee, $period)` now reads basic salary from `grade_steps` keyed by `(employee.current_grade_id, employee.current_step, period)` — **not** `employees.salary`. The legacy `employees.salary` column is deprecated but kept for one cycle to compare-and-flag mismatches in a warning report.

### 2.6 UI

- `Establishment/Positions/Index.vue` — filterable table; status pills; vacant-count headline
- `Establishment/Positions/Show.vue` — current incumbent, history, salary projection at next step
- `Establishment/OrgChart.vue` — d3-style hierarchical view (use `@/Components/OrgTree.vue` — new)
- `Establishment/Grades/Index.vue` — grade + steps + salaries matrix
- `Establishment/Ceilings/Index.vue` — per-dept ceilings vs filled

### 2.7 Permissions

```
positions.view, positions.manage   (hr_admin, super_admin)
establishment.exceed               (super_admin only — overrides ceiling with reason)
grades.manage                      (super_admin)
```

### 2.8 Acceptance criteria (Work-stream 2)

- Cannot hire into a frozen position
- Cannot exceed `establishment_ceilings` without `establishment.exceed` permission + a reason captured in audit
- Org-chart renders for 200+ position seed in <300ms
- Step-increment job runs idempotently (running it twice in a day doesn't double-increment)
- Payroll run picks up base salary from `grade_steps`, not `employees.salary`

---

## Work-stream 3 — Ghana Card / NIA Verification Adapter (1.5 weeks)

### 3.1 Goals
- Validate every `employees.national_id` is a real, currently-valid Ghana Card
- Detect duplicates (same Ghana Card on two employees → flag)
- Block payroll runs from including employees whose Ghana Card is unverified or expired

### 3.2 Domain model

| Table | Key columns |
|---|---|
| `identity_verifications` | `employee_id`, `provider` (enum: nia_official, manual_upload, third_party_kyc), `ghana_card_number`, `verified_at`, `verified_by`, `expires_at`, `evidence_path`, `status` (enum: pending, verified, failed, expired), `raw_response_json` |

### 3.3 Adapter design

`app/Services/Identity/` — interface-driven so we can swap providers as procurement clears one:

```
interface IdentityProvider {
    public function verify(string $ghanaCardNumber, array $personal): VerificationResult;
}

NiaOfficialProvider          // Real NIA Identity Verification System (when access secured)
ThirdPartyKycProvider        // uqudo / Youverify / Smile ID — fallback for pilot
ManualUploadProvider         // Last resort: HR uploads Ghana Card scan + senior reviewer approves
```

The active provider is config-driven (`config/identity.php`), so dev/staging can run on `ManualUploadProvider` while production talks to NIA.

### 3.4 Workflow

1. On `Store/UpdateEmployeeRequest`, if `national_id` changed, queue `VerifyEmployeeIdentity` job
2. Job calls active `IdentityProvider::verify`
3. On success: `identity_verifications.status = verified`, `expires_at = now + 12 months`
4. On dup detection (same Ghana Card on another employee row): raise `DuplicateIdentityDetected` event → HR notification + complaint case auto-created
5. `PayrollService::calculate()` skips employees without a fresh `verified` row and writes them to a `skipped_lines` report

### 3.5 UI

- New `Verified` badge on Employee/Show.vue hero
- New filter `unverified_only` on Employees/Index.vue
- New dashboard tile "Unverified workforce: N" on HR overview
- `Identity/Reverify.vue` slide-panel for manual re-verification

### 3.6 Tests

- Mock provider returns success → status flips to verified
- Mock provider returns 404 → status `failed`, audit written
- Duplicate Ghana Card across two employees → event fires, complaint exists
- Payroll run on dataset with one unverified employee → that employee in `skipped_lines`, others process normally

### 3.7 Acceptance criteria (Work-stream 3)

- Adapter swappable via config without code change
- Manual provider fully functional end-to-end (for pilot)
- Unverified employees are skipped on payroll with audit trail
- Ghost-worker scenario (manual insert of duplicate ID) is caught within 5 minutes by background sweep

---

## Work-stream 4 — Tamper-Evident Audit Log (0.5 weeks)

Cheap; massively raises trust signal for an Auditor-General review.

### 4.1 Migration

Extend existing `audit_logs` table:

```
add column previous_hash    char(64)   nullable
add column row_hash         char(64)   nullable
add column chain_position   bigint     nullable, indexed
```

### 4.2 `WriteAuditLog` job changes

Inside `app/Jobs/WriteAuditLog.php`, wrap the insert in a transaction with a row-lock on the latest row, then:

```
$previous = AuditLog::orderByDesc('chain_position')->lockForUpdate()->first();
$row = AuditLog::create([
    ...$payload,
    'previous_hash' => $previous?->row_hash,
    'chain_position' => ($previous?->chain_position ?? 0) + 1,
]);
$row->row_hash = hash('sha256', $row->previous_hash . $row->canonicalJson());
$row->save();
```

### 4.3 Verification command

`php artisan audit:verify-chain` — re-hashes every row in order; exits non-zero on first mismatch and prints the breaking chain position. Used by:
- A nightly scheduled job that alerts on failure
- The `/audit-logs` UI's "Verify chain" button (super_admin only)

### 4.4 UI

- Existing `/audit-logs` Index gets two new columns: `Position`, `Chain status` (badge: green = verified, red = broken)
- "Verify chain" button posts to `POST /audit-logs/verify` and shows toast result

### 4.5 Acceptance criteria (Work-stream 4)

- Tampering with any row in `audit_logs` is detected by `audit:verify-chain` and the UI verify button
- Verification of 100,000 rows runs in <30 seconds
- Audit insertion overhead per request <5 ms on average

---

## Work-stream 5 — PostgreSQL + Horizon + Backup Baseline (1 week)

### 5.1 Database

- Add `pgsql` block to `config/database.php` with sane defaults
- Add `.env.production.example` showing `DB_CONNECTION=pgsql`, host, port, ssl mode
- Audit all migrations for PG-incompatible quirks (mostly: `enum` columns — already using PHP enums + `string` cast, so likely fine; nullable `unique` on composite keys; SQLite-specific JSON usage)
- Add CI matrix: run Pest on **both** SQLite and PostgreSQL to catch divergence early
- Add `php artisan db:dump` wrapper that uses `pg_dump` on PG, `sqlite3 .dump` on SQLite

### 5.2 Queue infrastructure

- Add `laravel/horizon` to composer
- Configure two named worker pools matching existing queues: `audit` (max 2 workers, low priority) and `analytics` (max 4 workers)
- Add a new `payroll` pool (max 1 worker, high memory) and `notifications` (max 3)
- Supervisor config under `deploy/supervisor/cihrms-horizon.conf`
- Horizon dashboard mounted at `/horizon`, gated by `super_admin` only

### 5.3 Backup baseline

- Add `spatie/laravel-backup` package
- Daily full DB dump + `storage/app/avatars`, `storage/app/documents`, `storage/app/returns` → S3-compatible bucket (or local NFS for pilot)
- Backup health-check endpoint exposes last-successful timestamp for uptime monitoring
- Retention: 30 daily, 12 monthly

### 5.4 Observability

- Add `sentry/sentry-laravel` with DSN from env
- Add JSON structured logging in production (`LOG_CHANNEL=stack` with `daily` + `sentry`)
- Add request-ID middleware so logs are correlatable

### 5.5 Acceptance criteria (Work-stream 5)

- `composer dev` works against either SQLite or local Postgres via `.env`
- CI is green on both DBs
- Horizon dashboard shows all 4 queues
- A nightly backup is verifiable by date + size in the dashboard

---

## Work-stream 6 — Two-Factor Auth for Privileged Roles (1 week)

### 6.1 Approach

Use TOTP (Google Authenticator / Authy compatible) — no SMS dependency, works offline.

### 6.2 Migration

Add columns to `users`:
- `two_factor_secret` (encrypted)
- `two_factor_recovery_codes` (encrypted JSON array)
- `two_factor_confirmed_at`
- `two_factor_required` (bool — set true for `hr_admin`, `finance_officer`, `super_admin`)

### 6.3 Flow

- On first login after deployment, users in privileged roles see `/two-factor/enroll` (cannot skip)
- Enrollment shows QR code (using `bacon/bacon-qr-code` + `pragmarx/google2fa`) and 10 recovery codes
- After enrollment, every login (or every privileged action — configurable) requires a TOTP code
- Recovery codes single-use, regeneratable from `/profile` Security tab

### 6.4 Sensitive-action re-prompt

For `payroll.approve`, `payroll.reverse`, `establishment.exceed`, `users.elevate-role`: require fresh TOTP within last 5 minutes (cached as `2fa_fresh:{user_id}`).

### 6.5 UI

- Extend `Profile/Edit.vue` Security tab with: "Two-factor authentication" section
- New `Auth/TwoFactorChallenge.vue` between login and dashboard
- New `Auth/TwoFactorEnroll.vue` for first-time setup

### 6.6 Acceptance criteria (Work-stream 6)

- All three privileged roles cannot reach the dashboard without 2FA enrolled
- Approving a payroll run within 5 min of last TOTP entry does NOT re-prompt; beyond 5 min DOES re-prompt
- Recovery code usage is single-shot and audited
- Rate-limiting: 5 failed TOTP attempts in 15 min locks the account and notifies super_admin

---

## Cross-cutting deliverables

| Deliverable | Owner stream | Notes |
|---|---|---|
| Permission seeder update (RolePermissionSeeder) | all | One PR per stream appends; merged at end |
| `docs/PROJECT_STATE.md` updated | each stream on merge | Keeps the live snapshot accurate |
| README / `composer dev` updates | stream 5 | Add Postgres + Horizon instructions |
| Demo seed data refresh | streams 1+2 | Seed grades, steps, positions, allowances, tax brackets, statutory rates for 2026 |
| Pest suite green on PG and SQLite | stream 5 | CI matrix |
| Onboarding deck for HR pilot users | post-merge | Out of scope here, but flagged |

---

## Out of scope for Phase 1 (deferred to Phase 2+)

- Performance management module (PSC contracts, 360°)
- Biometric attendance integration
- LMS / learning module
- MoMo / GhIPSS disbursement (we generate the bank file in P1 but don't push)
- Asset management
- DPA data-subject rights portal (right-to-access, right-to-erasure)
- USSD / SMS fallback
- WCAG 2.1 AA audit pass
- Whistleblower channel
- SSO / SAML / OIDC

---

## Open questions / decisions needed before kickoff

1. **NIA API access** — is there an existing MoU / credential, or do we pilot on a third-party KYC provider (uqudo, Youverify, Smile ID)?
2. **Tier-2 trustee list** — which licensed trustees do pilot employees use? Need at least one trustee schema confirmed (Petra, Enterprise, Glico, etc.)
3. **GIFMIS export format** — do we have access to the published CAGD schema, or do we need to request it?
4. **2FA channel** — TOTP only, or also SMS (more expensive, less secure, but more accessible for senior public servants who may not install apps)?
5. **Hosting target for pilot** — NITA data center, AWS Cape Town (af-south-1), or on-prem at the pilot MDA?
6. **Pilot MDA / department** — confirms volume of seed data we need and which trustees / cost-center coding to model

---

## Definition of done (Phase 1)

Phase 1 ships when **all six** of the following are true:

1. A fresh demo deployment produces a payroll run on 100 seeded employees with PAYE, SSNIT, NHIA, Tier-2 totals matching an externally-prepared spreadsheet
2. The same run generates GRA P.A.Y.E., SSNIT, and Tier-2 statutory return files in the published schemas
3. Every employee has either a verified Ghana Card row or appears in the `skipped_lines` report of the next payroll run
4. `php artisan audit:verify-chain` exits 0 on a clean DB and exits non-zero on a tampered row
5. The same test suite runs green against SQLite **and** PostgreSQL in CI
6. `hr_admin`, `finance_officer`, and `super_admin` accounts cannot reach the dashboard without 2FA enrolled

When all six are green, we have something we can credibly demo to an MDA.
