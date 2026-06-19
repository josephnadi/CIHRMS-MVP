# Finance Phase 4 — Budgeting

**Date:** 2026-06-19
**Status:** Approved design — ready for implementation plans
**Phase:** 4 of 4 (final) in the Finance "source of all monetary throughput" roadmap

## Roadmap context

Phases 1–3 are complete and merged: every money event posts through one engine
(Phase 1), the ledger is period-aware/closeable/immutable/reconciled (Phase 2),
and the four financial statements report it (Phase 3). Phase 4 adds **budgeting**
— approved annual budgets per GL account, budget-vs-actuals reporting against the
ledger, and soft (non-blocking) variance controls — completing the roadmap.

## Decisions (locked during brainstorming)

| Decision | Choice |
|---|---|
| Granularity | **Annual per account with an even monthly spread** (enter one annual figure; `monthly = annual/12`, `YTD = monthly × periodNo` derived) |
| Accounts budgeted | **All account types** (asset/liability/equity/income/expense) |
| Controls | **Report + soft warnings** — variance highlighting + advisory over-budget surfaces; NEVER blocks a posting |
| Encumbrances | **Deferred** — variance = budget − actual (posted actuals only); no commitment reservation |

## Architecture

Budget figures sit directly alongside the existing `LedgerBalanceService::activity()`
(the actuals source from Phase 3). There is no new actuals machinery — only a budget
table and a comparison presenter. The report reuses the Phase 3 scaffolding
(presenter → `ReportController` → Inertia/Vue → CSV/PDF).

```
budgets (per fiscal year, draft→approved) ─┐
budget_lines (annual_amount per gl_account) ┘
        │  budget figures + even monthly spread (annual/12 × periodNo)
        ▼
   BudgetVsActualsReport ──uses──► LedgerBalanceService::activity(yearStart, asOf)  (actuals)
        │  per account: annual / period / YTD budget vs YTD actual → variance (favourable/unfavourable by type)
        ▼
   ReportController → Inertia/Vue page → CSV/PDF
   BudgetStatusService::remaining(account, asOf)  (advisory, non-blocking)
```

## Data model

```
budgets                          budget_lines
──────────────────               ──────────────────────────────────
id                               id
fiscal_year_id → fiscal_years    budget_id → budgets (cascadeOnDelete)
status (draft/approved)          gl_account_id → gl_accounts (restrictOnDelete)
approved_by → users (nullable)   annual_amount (decimal 18,2)
approved_at (nullable)           timestamps
timestamps                       unique (budget_id, gl_account_id)
unique (fiscal_year_id)
```

- `BudgetStatus` enum: `Draft`, `Approved` (string-backed).
- One budget per fiscal year (`unique fiscal_year_id`). Draft is editable; Approved
  records `approved_by`/`approved_at`. (Reopen/versioning is out of scope; an approved
  budget can be reverted to Draft to edit, by the same permission — a simple toggle,
  no version history.)
- `annual_amount` may be zero (an account with no budget). Lines exist only for accounts
  the finance team chose to budget; un-budgeted accounts simply have no line (treated as
  zero budget in the report).

## P4-1 — Budget model + entry/approval

### Components
1. **`BudgetStatus` enum** (Draft/Approved + labels).
2. **`budgets` + `budget_lines` tables + `Budget`/`BudgetLine` models** (`Budget hasMany BudgetLine`; `BudgetLine belongsTo Budget, GlAccount`; `Budget belongsTo FiscalYear, approver`).
3. **`BudgetService`**:
   - `forYear(int $year): Budget` — get-or-create the draft budget for a fiscal year (ensures the `FiscalYear` exists via `FiscalCalendarService::ensureYear`).
   - `setLine(Budget $budget, GlAccount $account, float $annualAmount): BudgetLine` — upsert a line (only on a Draft budget; throws if Approved).
   - `approve(Budget $budget, User $by): Budget` — Draft→Approved + attribution; throws if already approved.
   - `revertToDraft(Budget $budget): Budget` — Approved→Draft (clears approval) to allow edits.
4. **Budgets admin page** (`Finance/Budgets/Index.vue`): pick a fiscal year, enter the annual amount per GL account (grouped by type), save (upserts lines), approve/revert. Controller + FormRequests + Resource per the Enum→FormRequest→Service→Resource pattern.
5. **Permission `finance.budget.manage`** (entry/approval) → `finance_officer`. Viewing budget reports uses the existing `finance.reports.view`.
6. **Finance Hub link** to Budgets.

## P4-2 — Budget vs Actuals report

- **`BudgetVsActualsReport::forYear(int $year, int $asOfPeriodNo = 12)`** returns, per budgeted account, `{code, name, type, annual_budget, ytd_budget, ytd_actual, variance, favourable}` plus section/type roll-ups and grand totals.
  - `ytd_budget = annual_amount / 12 × asOfPeriodNo`.
  - `ytd_actual = LedgerBalanceService::activity(Jan 1 of year, end of period asOfPeriodNo).natural_balance` for the account.
  - `variance = ytd_budget − ytd_actual` (the YTD comparison; the annual figure is shown as a separate column for context).
  - `favourable`: **expense** → actual ≤ budget (under-spent is favourable); **income** → actual ≥ budget (at/over target is favourable); other types → informational (no flag).
- Includes accounts that have a budget line OR have actuals (union), so over-spend on an un-budgeted account still surfaces (budget 0, actual > 0 → unfavourable).
- A report page with variance highlighting (unfavourable rows flagged) + CSV/PDF export, gated by `finance.reports.view`. Cross-linked with the Phase 3 statements.

## P4-3 — Controls (soft, non-blocking)

- **`BudgetStatusService::remaining(GlAccount $account, CarbonInterface $asOf): float`** — `annual_budget − actual-to-date` for the approved budget of the as-of date's fiscal year; advisory only. Returns the full annual budget less actuals (no encumbrances). Any action (e.g. an AP-invoice form) may consult it to show "remaining budget"; **it never blocks**.
- **Variance-alerts surface**: the over-budget (unfavourable) accounts from `BudgetVsActualsReport`, presented as a panel (on the budget report and/or Finance Hub) so finance sees breaches at a glance.
- **Explicitly out of scope**: hard blocking, approval-threshold workflow, and encumbrances/commitments.

## Error handling & integrity

- **Read-only actuals**: budgets are the only new writable data; the report and `remaining()` never mutate the ledger.
- **No budget for a year** → the report shows actuals against zero budget (every line unfavourable for expense over-spend) — not an error.
- **No actual for a budgeted account** → budget with zero actual.
- **Spread is derived, never stored** — changing the annual figure instantly reflects in monthly/YTD without re-spreading.
- Budget edits are blocked once Approved (must `revertToDraft` first) so an approved appropriation isn't silently changed.

## Testing (Pest)

- **P4-1**: `forYear` get-or-create; `setLine` upsert + blocked-when-approved; `approve` attribution + blocked-when-already-approved; `revertToDraft`; permission gate (finance_officer manages, employee 403); the admin endpoint round-trip.
- **P4-2**: the spread math (`annual/12 × N`); variance + favourable/unfavourable by account type against a seeded budget + postings; union of budgeted + actual-only accounts; export shape; permission gate.
- **P4-3**: `remaining()` = budget − actual for the approved year; the alerts surface lists the unfavourable accounts; confirm nothing blocks posting.

## Conventions to follow

- Enum → FormRequest → Service → Resource; DB-backed permissions; per-user JSON
  `permissions` column for test grants; report scaffolding reused from Phase 3;
  every form/date input carries an `aria-label` (the `AccessibilityAuditorTest` gate);
  download routes are skipped by the routes smoke test.

## Out of scope (future)

- Encumbrances / commitment accounting (reserve budget on approved POs/AP invoices).
- Hard budget enforcement / approval-threshold workflows.
- Monthly-phased budgets entered per period (only even spread here); budget versioning/history.
- Multi-year / rolling budgets; budget transfers/virements.
