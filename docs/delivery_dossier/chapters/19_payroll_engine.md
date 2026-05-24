# Chapter 19 — Payroll Engine

> *In one paragraph.* Payroll Engine is the period-locked, dual-approval machine that turns the workforce master (Chapter 3) into a balanced set of monthly statutory schedules and a single net-pay disbursement file. Each payroll run reads salaries, allowances, deductions, attendance, and loan repayments for one calendar month; computes PAYE under the Ghana Revenue Authority's 2026 monthly bracket, SSNIT Tier-1 at 13/5.5%, NHIA at the 2.5% routed split, Tier-2 at 5% per NPRA-licensed trustee, and any court-ordered or voluntary deductions with a 1/3 take-home floor; locks the result on a tamper-evident PayrollLine; and on approval (by someone other than the creator) regenerates the GRA PAYE return, SSNIT Tier-1 schedule, NHIA allocation, Tier-2 trustee schedules and a GhIPSS bank-credit file as side-effects. The same run can mint the Oracle IPPD2/IPPD3 upload file and the GIFMIS journal voucher for state accountants, with the JV refusing to export unless debits equal credits. This is the chapter that closes the gap analysis line "statutory payroll engine MISSING."

## Where to find it

- **Sidebar location:** **Workforce & Finance** group → **Payroll Runs** (top-level item, badge icon). The legacy "Payroll" tile that previously pointed at one-off payments has been re-aimed at this index for any user with `payroll.view_all`; users without that permission still see the per-employee payslip view on their profile (Chapter 32).
- **Roles that see it:**
    - **super_admin** and **ceo** — every run, every line, including the salary numbers.
    - **hr_admin** — creates runs, calculates and recalculates, downloads statutory returns; cannot approve their own runs (dual-control).
    - **finance_officer** — approves and reverses; cannot create. Holds `payroll.approve`, `payroll.reverse`, `statutory.export`, `payroll.disburse`. Approval requires a fresh 2FA challenge.
    - **dept_head** — sees only runs scoped to their department (`run.department_id` matched against `User::managesDepartment()`), read-only.
    - **employee** — does not reach `/payroll-runs` directly; their payslip line surfaces on the Profile portal (Chapter 32) and through the mobile/API channel (Chapter 28).
    - **auditor** — read-only across every run, every line, every statutory return — same as the wider audit posture in Chapter 24.
- **Related modules:** Employees (Ch 3) — every line resolves salary, allowances, deductions, bank, SSNIT, Tier-2 trustee, Ghana Card identity from the employee row; Leave (Ch 4) — paid leave days roll into attendance so the calculator never silently drops a day of pay; Attendance (Ch 5) — the zero-attendance gate is the first line of defence against ghost workers; Loans (Ch 21) — scheduled installments are picked up at calculate time and posted on approve; Benefits (Ch 23) — voluntary deductions can carry benefit premiums; Disbursements (Ch 22) — every calculated line materialises into a Disbursement row at approve time, ready to be pushed to GhIPSS / MoMo; Finance Hub (Ch 20) — the GIFMIS exporter mints the balanced sub-ledger journal voucher; Audit Logs (Ch 24) — every state transition (Draft → Calculated → Approved → Paid → Reversed) is event-sourced; Profile portal (Ch 32) — employees see their own slice; Standards benchmark (Ch 44) — every section of this chapter has a touchpoint there.

## The screens

![Payroll Runs index — masthead, filter strip, table of runs](../assets/screenshots/19_payroll_engine/index.png)

*Callouts: ❶ Editorial-Sovereign masthead — "STATUTORY PAYROLL" eyebrow, page title "Payroll Runs", a one-line subhead that names the legal anchor of every column ("Act 896 PAYE · SSNIT · Tier-2 · NHIA — dual-control approved, auditor-ready Ghana Audit Service packs"), and the cobalt "Create Run" CTA on the right. · ❷ Filter strip — status dropdown (Draft / Calculated / Approved / Paid / Reversed) and a year input; both fire an Inertia partial reload on change with `preserveState`. · ❸ Table row — monospace `PR-2026-05-ORG` reference, period label, scope (department name or "Whole organization"), status badge, line count with "+N skipped" if any were rejected, gross and net totals in GHS-formatted cedi, and an "Open" link.*

![Payroll Run detail — stat-card band, action row, lines tab, returns tab](../assets/screenshots/19_payroll_engine/show.png)

*Callouts: ❶ Eight stat cards: lines processed (with "skipped" sublabel), gross, net pay, PAYE, SSNIT 5.5% (employee), SSNIT 13% (employer), NHIA (2.5% split), Tier-2 (5% employer). All denominated in GHS with two-decimal precision. · ❷ Action row — "Calculate / Recalculate" (visible while Draft or Calculated), "Approve (2FA required)" (visible to a non-creator with `payroll.approve` once Calculated), "Mark as paid" (visible after Approved), "Reverse" (visible to anyone with `payroll.reverse` once Approved or Paid). · ❸ Tab bar — Lines (paginated 50 at a time, with skipped rows highlighted in amber and their `skip_reason` shown inline) and Statutory returns (one row per generated file, with record count, total, and a download link). · ❹ The reversal-reason textarea expands inline when the reverser is permitted; the reason is required, must be at least 10 characters, and ends up on the audit log and the StatutoryReturn re-generation provenance.*

![Create Payroll Run slide panel — year, month, optional reason](../assets/screenshots/19_payroll_engine/create.png)

*Callouts: ❶ Three fields only — Year (defaults to current), Month (defaults to current, 1–12 validation), and an optional Reason textarea (1,000 chars max). · ❷ "Create draft" submits to `POST /payroll-runs`. The server enforces a unique constraint on `(period_year, period_month, department_id)` so the same scope cannot mint two open drafts. · ❸ A successful create redirects to the new run's show page in the Draft state, ready for the calculate action — no period numbers are computed in the panel itself.*

> The three screenshot files referenced above will be captured in Wave 1 (task W1.19). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Payroll Runs index

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Create Run** (cobalt CTA, top right) | Opens the right-hand slide panel for creating a new draft run (year, month, optional reason). | `payroll.run` (hr_admin, finance_officer, super_admin) | Creating a run is the single most-used action — it earns the headline position rather than hiding under a menu. |
| **Status dropdown** | Filters the table by `draft`, `calculated`, `approved`, `paid`, `reversed`. Refires the query immediately on change. | Anyone who can see the index | "What's still open?" is the question stakeholders ask before they ask anything else. |
| **Year input** | Restricts the table to one calendar year. | Same | Year-over-year comparisons and audit pulls both work cleanly when this filter is the first thing to set. |
| **Row Open link** | Navigates to the run's detail page. | `view` permission on that run (RBAC-checked in `PayrollRunPolicy::view`) | A row link beats hiding the action behind an icon — the whole row is part of an audit trail. |
| **Pagination** (bottom-right) | 15 per page, `withQueryString()` so filters survive paging. | Anyone who can see the index | Keeps the table responsive even with five years of monthly runs accumulated. |

### Payroll Run detail

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Back arrow** | Returns to the index. | Anyone viewing | Inertia preserves the scroll position and any filters that were set. |
| **Stat-card band** | Read-only — eight totals pulled from the cached aggregates on the `payroll_runs` row (`gross_total`, `net_total`, `paye_total`, `ssnit_tier1_employee_total`, `ssnit_tier1_employer_total`, `nhia_total`, `tier2_employer_total`, plus a derived "voluntary" tile in some contexts). | Anyone viewing | These nine numbers are what every approver, auditor and finance officer looks at first; surfacing them above any drill-down is non-negotiable. |
| **Calculate / Recalculate** | POSTs to `/payroll-runs/{run}/calculate`. Server-side, `PayrollService::calculate()` deletes any existing lines, re-reads every active employee, runs the full statutory calculator, and persists fresh PayrollLine rows plus updated totals on the run. | `payroll.run` while the run is Draft or Calculated | A calculated run is recomputable until it is approved — so a late allowance correction does not require throwing the run away. The transition is atomic (wrapped in `DB::transaction`) and refuses to run on Approved/Paid (`DomainException`). |
| **Approve (2FA required)** | POSTs to `/payroll-runs/{run}/approve` behind the `2fa:fresh` middleware. The service refuses if the approver is the same user as the creator (`DomainException: Dual-control violation`). On success: flips status to Approved, stamps `approved_by`/`approved_at`, posts each line's loan repayments through `LoanService::postRepayment`, and fires `PayrollRunApproved` — which queues `GenerateStatutoryReturns` and `MaterialiseDisbursements`. | Non-creator with `payroll.approve`, while the run is Calculated | This is the legal sign-off: the statutory schedules and disbursement file are *not* generated until this happens. Forcing it through fresh 2FA means a stolen session cannot quietly approve a payment run. |
| **Mark as paid** | POSTs to `/payroll-runs/{run}/mark-paid`. Stamps `paid_at`, flips status to Paid, and fires `PayrollRunPaid` — which (when `payroll.gifmis.auto_mint_on_paid` is on) triggers `MintGifmisJournal` to build the balanced GIFMIS JV CSV. | `payroll.approve`, while the run is Approved | Approval and payment are deliberately separate states. The money may move hours or days after the run is approved; the GIFMIS journal should post only when the cash has actually left the org bank account. |
| **Reverse** | Opens an inline textarea (reason, ≥ 10 chars). POSTs to `/payroll-runs/{run}/reverse` behind `2fa:fresh`. Service flips status to Reversed, stamps `reversed_by`/`reversed_at`/`reason`, marks every PayrollLine as `reversed`, and fires `PayrollRunReversed`. | `payroll.reverse`, while the run is Approved or Paid | Mistakes happen — a typo on a grade step, a late-arrived court order, a fraudulent enrolment caught after the fact. Reversal stops the bleed without rewriting history. The reason is required so the audit log has a real explanation, not "fixing it." |
| **Lines tab** | Paginated table (50 per page) of every PayrollLine in the run. Each row shows employee name + employee_no, grade/step, basic, allowance total, SSNIT 5.5%, PAYE, net, and status badge. Skipped lines highlight in amber and show their `skip_reason` ("Identity unverified — Ghana Card validation required." or "No attendance recorded in N working days — potential ghost worker."). | Anyone viewing the run | The lines tab is the audit pane. Every employee who was processed (or refused) is here, with the same numbers that flow into the GRA / SSNIT / NHIA / Tier-2 / GhIPSS files. |
| **Statutory returns tab** | One row per generated `StatutoryReturn` file — PAYE return, SSNIT Tier-1 schedule, NHIA allocation, one Tier-2 schedule per assigned trustee, and the GhIPSS bank file. Each row exposes the record count, the total, and a "Download" link. | `statutory.export` for the download | Returns are generated as a queued side-effect of approval. Until they exist, the tab shows "Approve the run to generate statutory return files." |
| **Download IPPD export** | GET `/payroll-runs/{run}/ippd-export` — regenerates the Oracle IPPD2/IPPD3 CAGD pipe-delimited file from current PayrollLine rows on every request. Header line "H" + one "D" line per employee + trailer "T" with totals; amounts in pesewas (integer × 100), names ASCII-transliterated, fixed-width columns padded to spec. | `statutory.export` | Public-service MDAs pushing monthly payroll into the Integrated Personnel & Payroll Database receive a file that exactly matches the CAGD spec — same column count, same widths, same pesewa convention. Regenerating on every request means a stale download is impossible. |
| **Download GIFMIS export** | GET `/payroll-runs/{run}/gifmis-export` — builds the balanced sub-ledger journal voucher (debits: salary expense, employer SSNIT, employer Tier-2; credits: net-pay payable, PAYE, SSNIT employee and employer split, NHIA, Tier-2, Tier-3, voluntary). Throws `RuntimeException` (422 with the residual) if total debits ≠ total credits to two decimals — that's a calculator bug, not a user error, and the state accountant should see it before it reaches GIFMIS. | `statutory.export` | The Ghana Integrated Financial Management Information System accepts bulk JV uploads from MDAs. The CIHRMS exporter emits the exact column set GIFMIS expects (`journal_id|line_no|gl_code|cost_centre|dr_amount|cr_amount|narration|period|source_doc|reference`) plus a trailer with the totals so the reconciler can verify balance before import. |

> *Notes:* The "Approve" button is gated by both `PayrollRunPolicy::approve` (which requires the user not to be the creator and the run to be Calculated) and the `2fa:fresh` middleware. The same gate covers reversal — combined with the audit-log handler in Chapter 24, every approval and reversal is signed, dated, and reversible only by a non-self party with a fresh second-factor challenge. Calculate, recalculate, mark-paid and the export endpoints do not require fresh 2FA: they either don't move money or they don't change the run's legal posture.

### Create Payroll Run slide panel

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Year** | required, integer, 2000–2100 | `payroll.run` | Defaults to the current calendar year via `new Date().getFullYear()` in the Vue form. |
| **Month** | required, integer, 1–12 | Same | Defaults to the current month. There is no calendar widget — payroll periods are explicit, not picked. |
| **Department (server-side only in the MVP)** | optional, must exist on `departments`; the unique constraint `payroll_runs_period_unique` over `(period_year, period_month, department_id)` blocks duplicate scopes | Same | The UI today creates whole-org runs (`department_id = null`); the unique index already permits one per-department run alongside the whole-org one, which is what Phase 2 will surface in the panel. |
| **Reason** | optional, string, max 1,000 chars | Same | Free-text justification — useful for special / back-dated / supplementary runs. Flows into the `reason` column on the row and into the audit log. |

> *Notes:* `PayrollService::createDraft()` runs the insert inside a transaction, sets the human-readable reference (`PR-YYYY-MM-ORG` or `PR-YYYY-MM-Dn` for a department-scoped run), stamps `created_by`, and fires `PayrollRunStarted`. On success the panel closes and the user lands on the new run's detail page in the Draft state.

## The data behind it

CIHRMS stores the payroll computation across **four** tightly-coupled tables, plus three reference tables that exist purely to keep historical runs reproducible:

- **`payroll_runs`** — one row per (year, month, scope) period. Carries the lifecycle status, the dual-approval audit columns (`created_by`, `approved_by`, `reversed_by`, plus the matching `*_at` timestamps), nine cached totals (`gross_total`, `net_total`, `paye_total`, `ssnit_tier1_employee_total`, `ssnit_tier1_employer_total`, `nhia_total`, `tier2_employer_total`, `tier3_total`, `voluntary_deductions_total`), and counters for lines processed and skipped. The cached totals are rewritten on every `calculate()`; the source of truth remains the line rows.
- **`payroll_lines`** — one row per (run, employee). Carries the resolved salary basics (`basic`, `allowance_total`, `gross`, `overtime_hours`, `overtime_pay`), the statutory deduction outputs (`ssnit_base`, `ssnit_tier1_employee`, `ssnit_tier1_employer`, `nhia_split`, `tier2_employer`, `tier3_employee`, `paye`), the voluntary-deduction total, the net, the full calculator snapshot as JSON in `breakdown`, the line status (`calculated` / `skipped` / `reversed` / `paid`), and a `skip_reason` when the gate rejected the employee. The `breakdown` JSON is what makes a closed run reproducible — re-running tomorrow would read the same band rates from the historical effective intervals.
- **`statutory_returns`** — one row per generated file (PAYE, SSNIT Tier-1, NHIA Split, one per Tier-2 trustee, and the GhIPSS bank file). Carries the kind, optional `trustee_id`, the on-disk `file_path` under `storage/app/returns/YYYY/MM/`, the total amount and record count, the generation timestamp, and submission tracking (`submitted_at`, `submitted_by`, `submission_reference`) so the auditor can see which schedules were filed and when.
- **`disbursements`** — one row per (run, line). Materialised at approve time by `MaterialiseDisbursements` (Chapter 22), with the channel resolved from the employee's preference (`bank_transfer` / `mobile_money` / `cash` / `ghipss`), the E-Levy applied on MoMo channels per the Electronic Transfer Levy Act 2022 (Act 1075, 1.5%), and the net-to-recipient amount after the levy.

The three reference tables are effective-dated by design — every calculator queries them with the run's period-end date, never "now":

- **`tax_brackets`** — versioned PAYE bracket table per jurisdiction + cadence. The 2026 monthly seed has seven rows: 0% on the first GHS 490, then 5% / 10% / 17.5% / 25% / 30% / 35% bands ending at the open-ended top.
- **`statutory_rates`** — versioned scalar rates and caps: `SSNIT_EMPLOYER` 13%, `SSNIT_EMPLOYEE` 5.5%, `NHIA_SPLIT` 2.5%, `TIER2_EMPLOYER` 5%, `TIER3_MAX_COMBINED` 16.5%, plus `MAX_INSURABLE_EARNINGS` of GHS 61,000/month (effective 1 Jan 2025, carried forward).
- **`pension_trustees`** — NPRA-licensed corporate trustees for Tier-2. The seeder ships five active rows (Enterprise Trustees Ltd, Petra Trust, Glico Pensions, Standard Pensions Trust, Old Mutual Pensions Trust) with their NPRA licence numbers and the schedule format the trustee accepts.

### What the gross-pay calculation reads

For each active employee in scope:

| Input | Source |
|---|---|
| **Basic salary** | `Grade::baseSalaryFor($step, $periodDate)` if the employee has a current grade and step; falls back to the legacy `employees.salary` column. Resolved by `PayrollService::resolveBasicSalary()`. |
| **Allowances** | All `allowances` rows on the employee with an effective interval covering the period — aggregated by `AllowanceAggregator` into a taxable total and a non-taxable total. Type comes from `AllowanceType`: housing, transport, responsibility, risk, fuel, communication, acting, entertainment, hardship, other. |
| **Overtime hours** | `attendance_summaries.overtime_hours` summed across the run's date range. Hourly rate = basic / 173.33 (the standard 52w × 40h ÷ 12 monthly average); overtime pay is added on top of gross. Pre-multipliers (Labour Act 651 §35 — 1.5× weekday OT, 2× rest-day OT) are applied upstream by `OvertimeCalculator` (Ch 5), so this multiplication is a flat rate. |

Gross = basic + (taxable allowances + non-taxable allowances) + overtime pay.

### What the statutory deductions compute — and how

The four deductions are computed by four named calculators (`PayeCalculator`, `SsnitCalculator`, `Tier2Calculator`, plus the NHIA split co-emitted by SSNIT), each pure and effective-dated.

#### PAYE (Ghana Revenue Authority)

Implemented in `App\Services\Payroll\PayeCalculator::calculate($chargeable, $effectiveOn)`. Chargeable income is `(basic + taxable allowances) − SSNIT employee` (non-taxable allowances are excluded; Tier-3 deductible is added in once the voluntary Tier-3 deduction lands). The calculator loads the bracket rows from `tax_brackets` filtered by effective interval, then walks the bands from the bottom up, taxing each one at its marginal rate. Same `(chargeable, date)` always produces the same answer — that is the invariant the chapter rests on.

The 2026 monthly bracket seed (Income Tax Act 2015 (Act 896), as amended):

| Lower (GHS) | Upper (GHS) | Marginal rate | Tax in this band |
|---|---|---|---|
| 0.00 | 490.00 | **0%** | 0.00 |
| 490.00 | 600.00 | **5%** | 5.50 |
| 600.00 | 730.00 | **10%** | 13.00 |
| 730.00 | 3,896.67 | **17.5%** | 554.17 |
| 3,896.67 | 19,896.67 | **25%** | 4,000.00 |
| 19,896.67 | 50,416.67 | **30%** | 9,156.00 |
| 50,416.67 | — (open) | **35%** | n × 0.35 |

In plain language: the first GHS 490 of chargeable income each month is tax-free, the next slice up to GHS 600 is taxed at 5%, and so on; anything above GHS 50,416.67 is taxed at 35% at the margin. The calculator returns both the total tax and a band-by-band breakdown (lower, upper, rate, amount taxed, tax in band, human label) so the payslip can display the workings, not just the total.

If the `tax_brackets` table is empty (the migration ran but the seeder didn't), the calculator falls back to a static 2026 monthly table baked into `PayrollCalculator::PAYE_BRACKETS_MONTHLY`. The fallback exists so a fresh installation never silently mis-taxes anyone — and ensures the unit tests can run without database state.

#### SSNIT Tier-1 + NHIA split (SSNIT / National Health Insurance Act 852)

Implemented in `App\Services\Payroll\SsnitCalculator::calculate($basic, $effectiveOn)`. Rates are looked up from `statutory_rates` for the period date; the base is `min(basic, MAX_INSURABLE_EARNINGS)` (GHS 61,000/month cap).

| Side | Rate of (capped) basic | Notes |
|---|---|---|
| **Employer Tier-1** | **13%** | The employer pays this; it does not appear as an employee deduction on the payslip. |
| **Employee Tier-1** | **5.5%** | Subtracted from gross before PAYE is computed. |
| **NHIA split** | **2.5%** | Routed *out of* the employer's 13% — not an additional employer cost. The remaining 11% (13% − 2.5%) is the true Tier-1 pension contribution. |

The calculator returns five fields — `base` (after the cap), `employee`, `employer`, `nhia_split`, `tier1_net` (employer − nhia_split) — so every downstream schedule (PAYE return, SSNIT contribution schedule, NHIA allocation, GIFMIS JV) can pick exactly the figure it needs. The GIFMIS journal in particular splits the employer-side credit between the NHIA-payable GL and the SSNIT-Tier-1-employer-payable GL, keeping the JV balanced.

#### Tier-2 occupational pension (National Pensions Act 2008 / Act 766)

Implemented in `App\Services\Payroll\Tier2Calculator::calculate($basic, $effectiveOn)`. Rate: **5% employer** of capped basic — same `MAX_INSURABLE_EARNINGS` cap as Tier-1. Mandatory since the 2008 Act, paid into a privately-managed NPRA-licensed corporate trustee, not to SSNIT. Each employee is linked to one trustee via `employees.tier2_trustee_id`; the statutory-return generator groups lines by trustee and emits one schedule per trustee (file naming includes the trustee id). Employees without an assigned trustee are flagged in the run summary (`unassigned` bucket) and excluded from the trustee schedules — they fall back to SSNIT-administered TUC default until an active enrolment is recorded.

#### Tier-3 voluntary

The data path is present (`tier3_employee` column on every PayrollLine, `tier3_total` on the run, `TIER3_MAX_COMBINED` rate at 16.5% in `statutory_rates`, GL code `cr_tier3` in the GIFMIS JV, the dedicated `Tier3` enum case on `StatutoryReturnKind`) and the legacy `PayrollCalculator::calculate()` already computes the deductible cap (16.5% of basic, tax-relieved). The current production engine writes `tier3_employee = 0.0` on every line with a comment marking the Tier-3 voluntary deduction as the next wire-up — the deduction route is the existing `Deduction` row of type `Tier3Voluntary` (in the `DeductionType` enum) which already flows through `DeductionAggregator`; the dedicated dual-counting between voluntary-deductions and the Tier-3 schedule still needs the split-off. The Tier-3 statutory schedule kind exists; the export generator is stubbed pending that split.

#### How the four chain together — one worked example

A GHS 7,000/month basic with a GHS 2,000 housing allowance (taxable) and no other variables in May 2026:

1. **Gross** = 7,000 + 2,000 = **GHS 9,000.00**.
2. **SSNIT base** = min(7,000, 61,000) = **7,000.00** (allowances do not pay SSNIT).
3. **SSNIT employer 13%** = 910.00 of which **NHIA split 2.5%** = 175.00 and **true Tier-1 employer** = 735.00.
4. **SSNIT employee 5.5%** = **385.00** (this is the only line that appears as a deduction on the payslip).
5. **Tier-2 employer 5%** = **350.00** (paid to the employee's assigned NPRA trustee).
6. **Taxable gross** = 7,000 + 2,000 = 9,000 (all allowances are taxable here).
7. **Chargeable** = 9,000 − 385.00 = **8,615.00**.
8. **PAYE** walks the bands: 0% × 490 + 5% × 110 + 10% × 130 + 17.5% × 3,166.67 + 25% × (8,615.00 − 3,896.67) = 0 + 5.50 + 13.00 + 554.17 + 1,179.58 = **GHS 1,752.25**.
9. **Net after statutory** = 9,000 − 385.00 − 1,752.25 = **6,862.75**.
10. **Voluntary deductions** are aggregated next (see below); if none, **net = 6,862.75**.

The same employee in October 2026 with the same numbers will produce identically the same line because every rate is looked up by the run's `period_end` date, not by `now()`.

### What the voluntary-deduction pipeline does

`DeductionAggregator::aggregate($employee, $gross, $netAfterStatutory, $periodDate)` is priority-ordered and net-floor-aware:

1. Loads all `deductions` rows on the employee with an effective interval covering the period.
2. Sorts by the `DeductionType` enum's `priority()`:

| Deduction | Priority | Floor-protected? |
|---|---|---|
| Court-ordered garnishment | 10 (first) | **No** — statutory; ignores the take-home floor |
| Loan repayment | 20 | Yes |
| Salary advance | 30 | Yes |
| Tier-3 voluntary pension | 40 | Yes |
| SACCO contribution | 50 | Yes |
| Union dues | 60 | Yes |
| Staff welfare | 70 | Yes |
| Other | 80 (last) | Yes |

3. Walks the sorted list. For each row it resolves the amount (fixed `amount`, or `percentage × gross`, capped by `cap_balance` if set — loan repayments use the cap to stop at outstanding balance) and checks whether subtracting it would push net below `gross × 1/3` (the public-sector take-home floor, configurable on construction). Garnishments bypass the floor (court order is non-negotiable); everything else gets partially applied to the floor and the remainder is deferred.
4. Returns `{ total, applied: [...], deferred: [...] }` — the deferred lines surface in the line's `breakdown.deductions.deferred` JSON so the next month's run knows to pick them up.

Loan repayments are handled in two phases (this is the only deduction with a side-effect outside the payroll tables):

- **At calculate time** — `PayrollService::collectLoanRepayments()` finds every scheduled `LoanRepayment` whose `due_period` matches the run's period and whose loan is `activeForRepayment`, and pulls them into the line's `breakdown.loans.lines[]`. The `LoanRepayment` row is **not** mutated.
- **At approve time** — the service iterates each calculated line's `breakdown.loans.lines`, calls `LoanService::postRepayment($repayment, $runId, $lineId)`, and the loan service marks the repayment paid, decrements the loan's outstanding balance, and flips status to `paid_off` when it hits zero. If approval is rolled back, the loan-side effect is rolled back with it (same DB transaction).

### What the calculator refuses to pay — the ghost-worker gates

`PayrollService::calculate()` runs two hard gates before producing a line for any employee:

- **Gate 1 — identity unverified.** `Employee::hasUsableIdentity()` checks for a verified `IdentityVerification` row (Ghana Card PIN matched against NIA — Chapter 22). If it returns false, the employee gets a skipped line with `skip_reason = "Identity unverified — Ghana Card validation required."` and the `skipped_count` increments. No statutory deductions, no net pay.
- **Gate 2 — zero attendance.** `AttendanceService::aggregatePeriod()` aggregates the employee's days from `attendance_summaries`. If `days_worked + days_on_leave === 0` for the period (i.e. no biometric / web-clock / leave-paid day at all), the line is skipped with `"No attendance recorded in N working days — potential ghost worker."` — the most explicit ghost-worker signal CIHRMS produces.

A skipped line is still a row in the payroll table — it shows up amber-highlighted on the Lines tab with its reason, so HR can see exactly *who* was rejected and *why* before the run is approved. It also keeps the run's `lines_count + skipped_count` matching the active-employee headcount, which is what the dashboards and audit packs reconcile against.

### What the run lifecycle looks like end-to-end

```
Draft ─calc─► Calculating ─done─► Calculated ─approve─► Approved ─pay─► Paid
                                       ▲                     │             │
                                       │                     ▼             ▼
                                  recalculate              reverse      reverse
                                                              │             │
                                                              ▼             ▼
                                                          Reversed      Reversed
```

The legal posture changes only at the Approved transition — that is when:

- `PayrollRunApproved` fires.
- `GenerateStatutoryReturns` (queued, three retries, `payroll` queue) runs the five-file generator: PAYE return, SSNIT Tier-1 schedule, NHIA allocation, one Tier-2 schedule per assigned trustee, and the GhIPSS bank-credit file.
- `MaterialiseDisbursements` (queued) creates the Disbursement rows ready for Finance to push to the actual rails.
- Loan repayments captured at calculate time are now posted against the loan ledger.
- The audit log (Chapter 24) records the run state change with the approver's identity and 2FA challenge id.

The Paid transition is when `MintGifmisJournal` (gated by `payroll.gifmis.auto_mint_on_paid`, default off) builds the GIFMIS journal voucher. If the JV doesn't balance, the listener logs a `critical` rather than crashing the payment — because rolling back the Paid state once the cash has moved is a much worse failure mode than a missing JV that the finance officer can re-run manually.

## How it talks to other modules

- **Employees (Ch 3)** — `PayrollService::calculate()` queries `Employee::active()->with('currentGrade.steps', 'currentPosition', 'tier2Trustee')`. The salary, bank, SSNIT number, TIN, Ghana Card, Tier-2 trustee, `current_grade_id`, `current_step`, and disbursement channel all come from here. A missing trustee on an active employee surfaces as an "unassigned" warning on the Tier-2 schedule generator.
- **Leave (Ch 4)** — paid leave days reduce `days_worked` but raise `days_on_leave`, so an employee on approved annual or sick leave passes Gate 2 (zero-attendance) and still gets paid for the period.
- **Attendance (Ch 5)** — `AttendanceService::aggregatePeriod()` provides the working-days / days-worked / days-on-leave / overtime-hours numbers. Overtime hours flow straight into the payroll line; days_worked drives the ghost-worker gate.
- **Loans (Ch 21)** — `LoanRepayment` rows with `status = scheduled` and `due_period = run_period` are pulled into the run at calculate time and posted via `LoanService::postRepayment()` at approve time.
- **Benefits (Ch 23)** — benefit premiums (where they are configured as a deduction on the employee) flow through the same `Deduction` rows as any other voluntary deduction, with the standard floor protection.
- **Disbursements (Ch 22)** — `MaterialiseDisbursements` listener creates one Disbursement per PayrollLine on approval. The channel resolves from `employee.disbursement_channel`; the E-Levy is applied on MoMo channels at 1.5% per Act 1075 (sourced from `statutory_rates.E_LEVY_RATE` with the 0.015 fallback baked into `BatchDisbursementService::E_LEVY_FALLBACK_RATE`).
- **Finance Hub (Ch 20)** — the GIFMIS exporter mints a balanced double-entry JV with explicit GL code mappings under `config/payroll.php` → `gifmis.gl_codes`. Each MDA overrides the eleven GL codes per their CAGD chart-of-accounts via env vars (`GIFMIS_GL_DR_SALARY`, `GIFMIS_GL_CR_PAYE`, etc.), so the exporter never silently posts to a sandbox account.
- **Audit Logs (Ch 24)** — `PayrollRunStarted`, `PayrollRunCalculated`, `PayrollRunApproved`, `PayrollRunPaid`, `PayrollRunReversed` are all dispatched on each transition. They land in the immutable audit log with the run reference, period, totals, and actor identity.
- **Webhooks (Ch 27)** — `FanOutWebhooks::handlePayrollRunApproved` ships the approval event to any subscriber tenant — so a downstream MDA's GIFMIS bridge, an external payroll auditor, or a finance partner can react to "this run is now legally approved" in real time.
- **Profile portal (Ch 32)** — employees see their own monthly payslip line; the legacy `Payment` model (with `PayslipGenerated` event + queued `UploadPayslipToCloud` mirror to OneDrive/Drive) covers the per-employee PDF for ad-hoc / one-off payments. Bulk payslip-PDF emission for an entire payroll run is not yet wired — see "What's planned next" below.
- **Public API v1 (Ch 28)** — `GET /api/v1/payroll/runs`, `GET /api/v1/payroll/runs/{run}`, `GET /api/v1/payroll/runs/{run}/returns`, and `GET /api/v1/payroll/runs/{run}/returns/{return}/download` expose the run, its lines, and the generated schedule files behind the `api.scope:payroll:read` middleware, so a partner MDA's internal portal can pull verified state without screen-scraping the Inertia pages.

## Standards touchpoints

The payroll engine is the single chapter with the densest set of legal anchors — each named statute is what the calculator, the schedule generator, or the GL mapping is actually implementing. Forward references all go to **Chapter 44 — Standards & Statute Index**.

- **Income Tax Act, 2015 (Act 896), Sixth Schedule — Pay As You Earn** — the seven-band monthly PAYE bracket table seeded in `GhanaStatutoryReferenceSeeder` and applied by `PayeCalculator` is the live transcription of the schedule as amended for FY 2026. The `tax_brackets` table is effective-dated against future amendments, and the PAYE return generator emits the GRA-format CSV (TIN, Staff ID, Gross, SSNIT 5.5%, Chargeable, PAYE, Period) that the State Tax Office's bulk uploader accepts. See Chapter 44.
- **National Pensions Act, 2008 (Act 766), §3 — Mandatory three-tier pension scheme** — Tier-1 (5.5% employee + 13% employer to SSNIT, capped at the Maximum Insurable Earnings), Tier-2 (5% employer, mandatory, to a privately-managed NPRA-licensed trustee), and Tier-3 (voluntary, up to 16.5% combined, tax-relieved) are all data-modelled and computed end-to-end. The `statutory_rates` row for `MAX_INSURABLE_EARNINGS` (GHS 61,000/month, effective 1 Jan 2025) is the Act's published cap; the `pension_trustees` table carries each trustee's NPRA licence number (`NPRA-CT-001` through `NPRA-CT-005` in the seed). The Tier-2 trustee schedules are grouped by trustee_id so a single payroll run produces one upload per active trustee. See Chapter 44.
- **National Health Insurance Act, 2012 (Act 852), §29 — NHIA contribution** — the 2.5% portion of basic that SSNIT routes out of the employer's 13% Tier-1 contribution to the National Health Insurance Fund. `SsnitCalculator` returns the NHIA split as a distinct field (`nhia_split`); the NHIA allocation statement is generated as its own `StatutoryReturnKind::NhiaSplit` file; the GIFMIS JV credits NHIA on its own GL code (`cr_nhia`) and reduces the SSNIT-employer credit by the NHIA portion to keep the entry balanced. See Chapter 44.
- **Social Security and National Insurance Trust Act, 1991 (PNDCL 247) and successor regulations — Tier-1 contribution remittance** — the SSNIT Tier-1 schedule (`StatutoryReturnKind::SsnitTier1`) emits the seven-column CSV (SSNIT No, Staff ID, Full Name, Basic, Employer 13%, Employee 5.5%, Period) accepted by the SSNIT employer-services portal. The 14-day remittance deadline is captured as the `REMITTANCE_DEADLINE_DAYS` constant on `StatutoryRate` for the operations dashboard to surface as an SLA warning. See Chapter 44.
- **Labour Act, 2003 (Act 651), §35 — Overtime computation** — `OvertimeCalculator` (Chapter 5) applies the statutory premium multipliers (1.5× weekday OT, 2× rest-day / holiday OT) before the payroll engine sees the hours. The payroll engine multiplies the resulting `overtime_hours` by a flat hourly rate derived from basic ÷ 173.33 monthly hours and adds it on top of gross. The Act 651 §35 multiplier is encoded once, in `OvertimeCalculator`, so a change in policy is one config edit, not a search across the codebase. See Chapter 44.
- **Electronic Transfer Levy Act, 2022 (Act 1075) — E-Levy** — `BatchDisbursementService` applies E-Levy at 1.5% (sourced from `statutory_rates.E_LEVY_RATE`, fallback `0.015`) on every MoMo-channel disbursement; bank-channel and GhIPSS-channel disbursements are not levied. The levy is computed and recorded against the Disbursement row separately from the net-to-recipient amount so the audit can see who received less than their gross net because of the levy. See Chapter 44 and Chapter 22.
- **Data Protection Act, 2012 (Act 843) §§17, 18 — Financial personal data** — salary, bank account, SSNIT number, and TIN are all personal financial data under the Act. `PayrollLineResource` does not expose them to users without `payroll.view_all`; the public API scope `payroll:read` is independent of and stricter than the Profile-portal self-view scope; bulk export of the GhIPSS bank file is behind `statutory.export` rather than `payroll.view_all`. See Chapter 44 and Chapter 24.
- **Public Financial Management Act, 2016 (Act 921), §11 — Internal controls** — dual control on payroll approval is enforced at the policy layer (`PayrollRunPolicy::approve` refuses if `created_by === user_id`) and at the service layer (`PayrollService::approve` throws `DomainException` on the same check). Fresh 2FA is enforced at the route layer (`2fa:fresh` middleware on `approve` and `reverse`). The combination satisfies Act 921 §11's separation-of-duties expectation for any disbursement against the Consolidated Fund. See Chapter 44.
- **GIFMIS (Ghana Integrated Financial Management Information System) — sub-ledger bulk JV import** — `GifmisJournalExporter` emits the pipe-delimited CSV the GIFMIS bulk-loader accepts (`journal_id|line_no|gl_code|cost_centre|dr_amount|cr_amount|narration|period|source_doc|reference`) and refuses to write a file whose debits don't equal credits to two decimals. The GL code map is per-MDA via env vars under `config/payroll.php` → `gifmis.gl_codes`, so the same exporter serves every Controller & Accountant-General-mapped CAGD chart. See Chapter 44 and Chapter 20.
- **IPPD2/IPPD3 (Integrated Personnel & Payroll Database) — Oracle CAGD upload format** — `IppdExporter` produces the H-header, D-detail (twenty fixed-position fields per the IPPD3 spec), T-trailer file in pesewa-integer amounts. Names are ASCII-transliterated because the CAGD parser is ASCII-only; pipes inside any field are sanitised so they cannot break the delimiter. Trailer-line totals are recomputed from the line rows, not copied from the run cache, so a calculator residual would be caught by a golden-file test before upload. See Chapter 44.
- **IFRS — Accruals on the payroll provision** — every approved payroll run mints the matching GIFMIS journal with payable accounts (net-pay payable, PAYE payable, SSNIT employee and employer payable, NHIA payable, Tier-2 payable, Tier-3 payable, voluntary deductions payable) on the right side, so the trial balance reflects the accrued liability the instant the run is approved — not when each cheque clears. The exporter's debit/credit balance check is the IFRS double-entry invariant in code. See Chapter 44.
- **ISO 30414 §4.6 (Compensation)** — total-compensation, gender-pay-gap, and median-pay disclosures rest on the same `payroll_lines` table this chapter writes to. The analytics chapter (Chapter 13) reads the aggregated cached totals; the per-employee detail is what makes the disclosures defensible. See Chapter 44.
- **National Identification Authority Act, 2006 (Act 707) — Ghana Card** — the identity gate is what makes the engine deserve to be called a "ghost-worker-resistant" payroll. An employee without `hasUsableIdentity()` cannot have a payroll line produced; the live NIA verification adapter (Chapter 22) is what populates `IdentityVerification` rows. See Chapter 3, Chapter 22 and Chapter 44.

## What's planned next

Phase 1 of the government-grade roadmap (see Chapter 46) closes the four explicit gaps still visible in the engine: (1) **Tier-3 voluntary deductions** — finish the split between `voluntary_deductions_total` and the Tier-3 line on each PayrollLine, then wire the `Tier3` statutory return generator that the enum and schedule already anticipate; (2) **Bulk payslip-PDF emission** — extend `PayslipGenerated` (already complete for per-payment payslips with cloud mirror to OneDrive/Drive via `UploadPayslipToCloud`) to fire once per `payroll_lines` row on approval, so every employee gets a PDF in their portal mailbox the moment the run is approved rather than only on ad-hoc payment generation; (3) **Department-scoped runs in the UI** — the unique constraint `(period_year, period_month, department_id)` and the `department_id` field are already on the table and honoured by the calculator, but the Create panel does not yet expose the dropdown; surfacing it is a one-day frontend task; (4) **Statutory-return submission tracking** — the `submitted_at`, `submitted_by`, and `submission_reference` columns exist on `statutory_returns` but the "Mark as filed" inline action that fills them is still on the Wave 1 backlog. Phase 2 adds the live GRA / SSNIT / NPRA / NHIA submission webhooks so the engine can confirm receipt rather than rely on a manual mark. Phase 3 promotes the GIFMIS exporter from "download and upload" to a live REST push under `payroll.gifmis.auto_mint_on_paid = true` for production MDAs, with the IPPD bridge following the same arc once CAGD certifies the file format against a pilot ministry.
