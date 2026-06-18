# Finance Phase 3 — Financial Statements

**Date:** 2026-06-18
**Status:** Approved design — ready for implementation plans
**Phase:** 3 of 4 in the Finance "source of all monetary throughput" roadmap

## Roadmap context

Phases 1 (Universal Posting) and 2 (Fiscal Periods & Close) are complete: every
money event posts a balanced journal entry through one engine, and the ledger is
period-aware, closeable, immutable, and reconciled to its subledgers. Phase 3
turns that accurate ledger into the **financial statements** that prove and report
it. Phase 4 (budgeting) builds on the statements.

## Decisions (locked during brainstorming)

| Decision | Choice |
|---|---|
| Statement set | **All four** — Trial Balance, Statement of Financial Activities (Income & Expenditure / P&L), Statement of Financial Position (Balance Sheet), Statement of Cash Flows — plus GL drill-down and comparative (prior-period) columns |
| Computation | **On-the-fly** from posted `journal_lines` (no materialized snapshots) |
| Terminology | **NPO/charity** — "Statement of Financial Position", "Statement of Financial Activities", "Surplus/Deficit" |
| Export | **CSV for every statement; PDF where a library already exists** |
| Cash Flow method | **Both direct and indirect** (UI toggle); the two must reconcile to the same net change in cash |

## Architecture

One **balance engine** feeds all statements; each statement is a thin presenter.

```
posted journal_lines ⨝ journal_entries (entry_date, status=posted, fiscal_period_id)
        │  Σ debit / Σ credit per account over a date window
        ▼
   LedgerBalanceService → per-account {debit_total, credit_total, natural_balance}
        ├─► TrialBalanceReport       (as-of; Σdr = Σcr)
        ├─► IncomeExpenditureReport   (income 4xxx − expenditure 5xxx → surplus/deficit, period)
        ├─► FinancialPositionReport   (assets 1xxx = liabilities 2xxx + equity 3xxx + surplus, as-of)
        └─► CashFlowReport            (direct | indirect, period)
                        │
   each presenter → Controller → Inertia/Vue page → CSV/PDF export
```

The natural-balance convention (asset/expense = debit−credit; liability/equity/income
= credit−debit) is the same one the posting engine uses. Roll-ups use the existing
`GlAccount` parent/children hierarchy (roots 1000/2000/3000/4000/5000 → leaves).

## The balance engine — `LedgerBalanceService` (foundation)

The single well-tested aggregation method everything derives from:

- `balances(?CarbonInterface $from, CarbonInterface $to): Collection` — one row per
  account `{account_id, code, name, type, debit_total, credit_total, natural_balance}`,
  summed from posted `journal_lines` joined to `journal_entries` where
  `entry_date <= to` (and `>= from` when given) and `status = 'posted'`.
  `natural_balance` applies the type-based sign convention.
- `asOf(CarbonInterface $date)` = `balances(null, $date)` — cumulative point-in-time.
- `activity(CarbonInterface $from, CarbonInterface $to)` = `balances($from, $to)` — period flow.

Only `posted` entries are summed; `draft` and `reversed` entries are excluded
(a reversed entry's effect is undone by its posted reversal, so the net is correct).

## Statements

### Trial Balance (as-of)
Every account with its debit-side and credit-side balance as of a date; the report's
proof is **Σ debit balances = Σ credit balances**. The first deliverable — it directly
exercises and validates the engine.

### Statement of Financial Activities (Income & Expenditure, period)
Income accounts (4xxx) and expenditure accounts (5xxx) activity for the period,
grouped by roll-up, netting to **Surplus/Deficit** for the period.

### Statement of Financial Position (Balance Sheet, as-of)
Assets (1xxx), Liabilities (2xxx), Equity/Funds (3xxx) as of a date, with the
**current-period surplus** (income − expenditure for the year-to-date) folded into
funds. The accounting equation **Assets = Liabilities + Equity + Surplus** must hold
(an integrity assertion in tests).

### Statement of Cash Flows (period, direct + indirect)
Cash/bank accounts are the asset accounts linked to `org_bank_accounts` plus
`1010 Cash on Hand` and `1130 Cash in Transit`.

- **Direct**: for each posted `journal_line` hitting a cash account, the entry's
  contra-lines determine the category — **Operating** (income/expense, AR/AP,
  payroll payables), **Investing** (non-current asset purchases), **Financing**
  (loans, equity/funds). Receipts (debits to cash) and payments (credits to cash)
  are summed per category.
- **Indirect**: start from the period surplus/deficit; adjust for non-cash items and
  working-capital changes (Δ Accounts Receivable, Δ Accounts Payable, Δ other
  payables) over the period.
- Both methods must reconcile to the same **net change in cash** = actual movement
  in the cash accounts' natural balances over the period (a built-in correctness check).

## Comparatives + GL drill-down

- **Comparative columns**: each statement accepts a target period and renders the
  **prior period** alongside (this period vs last).
- **GL drill-down**: from any account on a statement, list the posted journal lines
  composing its balance for the window — an extension of the existing Journal Explorer
  (`JournalController`).

## Export

- **CSV** for every statement, reusing the app's existing report-export infrastructure.
- **PDF** where a PDF library is already present (the Documents module renders PDFs —
  confirm the library in planning). If not readily reusable, ship CSV-only and add PDF
  as a fast follow rather than block the statements.

## Decomposition & build order

- **P3-1 — `LedgerBalanceService` + Trial Balance**: the engine + the first statement +
  the report→controller→Vue→export scaffolding pattern. New permission
  `finance.reports.view`. A "Finance → Reports" hub entry.
- **P3-2 — Statement of Financial Activities + Statement of Financial Position**:
  the two core statements, comparative columns, and GL drill-down.
- **P3-3 — Statement of Cash Flows** (direct + indirect toggle): the complex one, last.

Build P3-1 → P3-2 → P3-3.

## Error handling & integrity

- The statements' **correctness invariants are the primary tests**: Trial Balance
  Σdr = Σcr; Financial Position assets = liabilities + equity + surplus; Cash Flow
  direct net = indirect net = actual cash-account movement.
- Reports are **read-only** — no posting, no mutation; gated by `finance.reports.view`.
- A requested period/date with no postings yields zero rows / zero totals (not an error).

## Testing (Pest)

- Engine: `balances`/`asOf`/`activity` over a seeded set of postings with known totals;
  posted-only (drafts/reversed excluded); date-boundary correctness (inclusive ends).
- Each statement: grouped totals against the seeded set; the integrity invariant per
  statement; comparative (prior-period) values; CSV/PDF export shape; permission gate.
- Cash Flow: direct and indirect both equal the actual net cash movement for the period.

## Conventions to follow

- Enum → FormRequest → Service → Resource for new modules; DB-backed permissions;
  per-user JSON `permissions` column for test grants.
- Reports are pure read models over the ledger — no new writable tables.

## Out of scope (later / future)

- Budgets vs actuals and variance reporting (Phase 4).
- Materialized period snapshots / report caching (revisit only if data volume demands).
- Multi-currency consolidation / FX revaluation.
- Consolidated/segment reporting across multiple entities.
