# CIHRMS Delivery Dossier — Change Log

All notable changes to this dossier are listed here.

## [v1.1] — 2026-06-20 — Finance accounting backbone + lifecycle/talent modules + QA hardening

- **Finance "GL as single source of monetary throughput"** — four complete phases: **Universal Posting** (`PostingService` single choke point + posting-account map + admin UI); **Fiscal Periods & Close** (fiscal calendar, closed-period guard, journal immutability, close/reopen/lock, subledger reconciliation); **Financial Statements** (Trial Balance, P&L, Balance Sheet, Cash Flow direct+indirect, drill-down, CSV/PDF); **Budgeting** (annual budgets per account, budget-vs-actuals, soft controls).
- **Settlement → GL** — off-boarding final settlement now posts accrual + payment journal entries (termination-benefit expenses 5130–5134, PAYE / loan / deductions / net-pay), reversal, and additive disbursement tracking.
- **Finance Analytics Dashboard** — KPIs + Chart.js charts (first charting lib added) + filters + CSV/PDF/PNG export; dedicated `finance.analytics.view` permission.
- **Statutory remittance tracking** (mark-filed + period-end+14 deadline + overdue posture) and **Tier-3 voluntary pension** (percentage election, 16.5% combined relief cap, GL 2230, per-trustee schedule).
- **Onboarding lifecycle module** (auto-initiate on hire, course auto-enrol); **LMS compliance enforcement** (mandatory requirements by role/dept, auto-assign + due dates + overdue dashboard + reminders); **course prerequisites** (self-enrol enforcement).
- **Functional QA pass** — static wiring audit + 4 module audits → fixed 25 form/CRUD defects (broken leave apply, document upload/delete, governance acknowledge, loan disburse, CoA archive 500, leave balance engine corrected to per-type statutory entitlements + working-days basis, disbursement dispatch UI so payroll money can be sent, shift edit/delete, leave comment/attachment).
- **Tests:** 895 → **1,414 Pest** / ~4,925 assertions, green on the SQLite + PostgreSQL CI matrix.

## [v1.0] — 2026-05-25 — Release

- **Built `build/CIHRMS_Delivery_Dossier_v1.0.docx` (851 KB).** All 49 source files compile via Pandoc with the brand-styled `reference.docx`.
- Wrote **47 numbered chapters** plus front matter and back matter.
- **Part I (Chapters 1–35)** — every shipped module walked end-to-end in advocate voice with the consistent chapter template (synopsis, screen tour, every-action table, data, integrations, standards, roadmap).
- **Part II (Chapters 36–43)** — engineer-voice annex covering architecture, canonical pattern, data model, RBAC, security, performance, testing, deployment.
- **Part III (Chapters 44–47)** — 13-framework standards benchmark, readiness advocacy, 4-phase roadmap, 3-path funding & sequencing analysis.
- Front matter: cover, 3-paragraph Executive Summary, reader's map per audience, At-a-glance scorecard.
- Back matter: Ghana-specific glossary, technical glossary, 47-row module index, 13-row standards cross-reference, about page.
- Plus `docs/delivery_dossier/build/module_audit.json` (1,660 lines) used as the canonical input to Chapters 44, 46, 47.
- Scope grew during Wave 0 from 30 to 47 chapters after codebase audit revealed 16 modules the original plan omitted (Attendance, Performance, Learning, Establishment, Offboarding, Loans, Disbursements, Identity, DPA, Whistleblower, Governance, Kiosk, Announcements, Messaging admin, Settings, Profile portal) — most flagged in the 8-day-old gap analysis as "Phase 1/2/3 missing" but in fact shipped.
- Honest accounting throughout: chains-broken findings (`ApplicantHired` / `PayslipGenerated` / `BenefitPremium` / `EnvelopeStatusChanged` event orphans), aspirational-vs-shipped matrix in Ch 40 (security headers, Argon2id docs mismatch, plain bank account numbers), env-keys-without-package gaps in Ch 43 (`spatie/laravel-backup`, `sentry/sentry-laravel`).
- Brand styling: Open Sans (the consolidated brand font) + navy `#0d1452` + action blue `#1a237e`; reference.docx patched programmatically rather than hand-edited in Word.
- All chapter writes verified against actual source code by per-chapter subagents — no fabricated field names, no invented routes.

## [v0.9] — 2026-05-25 — Wave 3 close

- Built `build/CIHRMS_Delivery_Dossier_v0.9.docx` (839 KB).
- Wrote chapters 44–47 (Part III — Standards & Market Readiness).
- All 47 body chapters complete; front and back matter still placeholders.

## [v0.5] — 2026-05-25 — Wave 1 close (notional)

- Wrote chapters 1, 2, 4–18 (Part I — Product).
- Built screenshot capture script `scripts/capture_screenshots.mjs` (screenshots themselves deferred).

## [v0.1] — 2026-05-24 — Wave 0: Foundation

- Scaffolded `docs/delivery_dossier/` tree.
- Built brand-styled `reference.docx` programmatically (Open Sans + navy + action blue).
- Added `build.ps1` Pandoc build script with pandoc-path auto-detection.
- Wrote Ch 3 (Employees) as proof-of-format.

---

## How to re-build

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
powershell -File build.ps1 -Version v1.0
```

Output: `build/CIHRMS_Delivery_Dossier_v1.0.docx`. Open in Word, optionally edit, then `File → Save As → PDF` to produce the final deliverable.

## Source layout reference

- `chapters/*.md` — 49 markdown files
- `reference.docx` — Pandoc style template
- `assets/palette.md` — brand colour tokens
- `assets/logo.png` — CIHRMS wordmark
- `build/` — generated `.docx` (gitignored except `module_audit.json`)
- `scripts/` — Playwright capture stub + Sharp annotation stub
- `README.md` — build instructions
