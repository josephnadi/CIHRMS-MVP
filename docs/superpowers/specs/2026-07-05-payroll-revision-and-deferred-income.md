# Scope: Salary Revision + Arrears (B) and Deferred Income (D)

**Date:** 2026-07-05 · **Status:** scoped, awaiting build approval
**Source docs:** CIHRM Sample PAYROLL workbook (B); CIHRM 2025 Annual Financial Statements — Note 10 "Subscription in Advance" (D).

**Approved design decisions**
- **B — revision scope:** institute-wide % with per-grade override.
- **B — arrears:** dedicated back-pay run.
- **D — defer at:** invoice (accrual).
- **D — recognition:** straight-line over N months.

The two features are independent and can ship separately. Foundations already in
place: date-versioned grade-step `base_salary` (B); billing→AR→income posting via
`FeeProduct.gl_income_account_id` + the `2400 Subscription-in-Advance` account (D).

---

## B — Salary Revision + Arrears

### B1. Revision mechanism (leverages date-versioned salaries)
A revision writes **new effective-dated `grade_steps.base_salary` rows** (old × factor)
and closes the prior rows (`effective_to = effective_from − 1 day`). Because
`resolveBasicSalary()` already reads the rate effective on the run's period date,
future runs automatically use the new figure and history is untouched.

**Data model**
- `salary_revisions` (audit + reproducibility): `id, reference, scope('institute'|'grade'),
  grade_id?, percentage (e.g. 10.00), effective_from, applied_by, applied_at, notes, status`.
- Per-grade overrides: a child `salary_revision_lines(revision_id, grade_id, percentage)`
  when scope requires different rates per grade.

**Service:** `SalaryRevisionService::apply(percentage, effectiveFrom, scope, gradeOverrides[])`
- For each affected `GradeStep` with `effective_to IS NULL`:
  - set old row `effective_to = effectiveFrom - 1 day`
  - create new row: `base_salary = round(old * (1 + rate), 2)`, `effective_from = effectiveFrom`.
- Idempotency: a revision is a discrete, referenced event; re-applying the same
  reference is a no-op. Guard against double-revising the same effective_from.
- Permission: `payroll.run` (or a new `payroll.revise`).

**UI:** a "Salary Revision" action on the payroll module: percentage, effective date,
scope (whole institute / pick grades with per-grade %), preview of affected steps
(old → new), confirm.

### B2. Arrears — dedicated back-pay run
When `effective_from` is a **past** month already paid at the old rate, arrears are owed.

**Service:** `BackPayService::compute(fromPeriod, toPeriod, employees[])`
- For each employee × affected month in range: recompute the payslip **twice** — once
  with the old basic, once with the new — using the existing `PayrollService`
  line calculator (extracted to a pure function taking `basic`). Then:
  - `arrears_gross = Σ (new_basic − old_basic)` (+ any % allowances that ride on basic, e.g. transport)
  - `back_paye     = Σ (new_paye − old_paye)`   (re-tax each month; take the delta)
  - `arrears_net   = arrears_gross − back_paye − Δreliefs`
- Produce a **back-pay run** (a `PayrollRun` of a distinct `type = 'arrears'`, or a
  dedicated `back_pay_runs` table) with one line per employee carrying the arrears
  figures + a per-month breakdown in `breakdown`.

**GL on approval** (mirrors normal payroll accrual, arrears amounts only):
`DR Staff Cost / CR Net-pay Payable (arrears_net)`, `CR PAYE Payable (back_paye)`,
`CR SSNIT/Tier-3 payable (Δreliefs)`.

**UI:** "Back-pay run" wizard — pick the revision (or a period range) + employees →
preview arrears + back-PAYE per employee → approve (dual-control) → post.

### B refactor prerequisite
Extract the per-line math in `PayrollService::calculateLine()` into a pure
`computeLineFor(Employee, basic, periodDate, allowances, ...)` so both the normal
run and `BackPayService` compute identically (single source of truth for PAYE).

### B tests
- Revision writes new effective-dated steps; a run after the effective date uses the
  new basic; a run before it uses the old (history preserved).
- Per-grade override applies different rates.
- Back-pay for a 3-month retro window = 3 × (new−old) with correct back-PAYE (verified
  against a hand-computed figure and, if provided, the workbook's Back Pay/Back PAYE).
- Dual-control on the back-pay run.

---

## D — Deferred Income (Subscription in Advance)

### D1. Mark deferred products
`fee_products` add: `is_deferred (bool, default false)`, `recognition_months (int, null)`,
`deferred_gl_account_id (fk gl_accounts, null → default 2400)`.
Annual membership subscriptions → `is_deferred=true, recognition_months=12`.
Forms / one-offs → immediate income (unchanged).

### D2. Defer at invoice (accrual)
When billing a deferred product, the AR invoice's income line GL is the **deferred
liability (2400)** instead of the income account. So `create()` posts
`DR AR (1200) / CR Subscription-in-Advance (2400)` — revenue is *not* recognised yet.
(Implementation: `BillingRunService` passes `deferred_gl_account_id` as the line GL for
deferred products; keep the true income account on the recognition schedule.)

### D3. Recognition schedule (straight-line)
On billing a deferred product, create a schedule:
- `revenue_recognition_schedules`: `id, ar_invoice_id, member_id, fee_product_id,
  income_gl_account_id (the real 41xx), deferred_gl_account_id (2400), total_amount,
  months, start_date (membership period start, else invoice date), recognized_total,
  status('active'|'completed'|'cancelled')`.
- `revenue_recognition_entries` (one per month): `schedule_id, period_month (YYYY-MM),
  amount (total/months, last tranche absorbs rounding), status('pending'|'recognized'),
  recognized_at?, journal_entry_id?`.

### D4. Recognition run
`RevenueRecognitionService::recognizeForMonth(YYYY-MM, actor)`:
- For each `pending` entry due on/before the month, post
  `DR Subscription-in-Advance (2400) / CR Subscription income (41xx)` (idempotent via
  `JournalSourceType::RevenueRecognition` + entry id), mark `recognized`, bump schedule
  `recognized_total`; complete the schedule when fully released.
- Guarded by closed-period rules (fail-closed) like other posting.

### D5. Cancellation / refund
If a deferred subscription is cancelled or the member leaves, reverse the **unrecognised**
balance: `DR Subscription-in-Advance / CR AR` (or income adjustment), mark remaining
entries `cancelled`. Hook into the existing AR invoice cancel path.

### D tests
- Billing a deferred product credits 2400 (not income); a schedule of N entries is created.
- Recognising month 1 releases total/N: DR 2400 / CR 41xx; GL balances move; idempotent.
- After N months the schedule completes and 2400 for that source is zero.
- Non-deferred products still credit income immediately (unchanged).
- Cancellation reverses only the unrecognised remainder.

### D enum
Add `JournalSourceType::RevenueRecognition = 'revenue_recognition'` (+ label).

---

## Suggested build order
1. **B1** revision (self-contained, high value) → 2. **B-refactor** + **B2** arrears →
3. **D1–D3** defer-at-invoice + schedule → 4. **D4** recognition run → 5. **D5** cancellation.
Each step: migration (additive) → service (TDD) → controller/route → UI → tests → commit.

## Open items to confirm during build
- B: is the revision cadence recurring (annual) or ad-hoc? (Affects whether we add a
  scheduler; default: ad-hoc action.)
- D: does the membership period start = invoice date, or a fixed anniversary/Jan? (Drives
  `start_date`.) — the forthcoming **invoice samples** should answer this.
- D: recognition run trigger — manual monthly action now; auto-on-period-close can be a
  fast follow.
