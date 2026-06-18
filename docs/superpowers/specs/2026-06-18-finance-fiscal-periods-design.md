# Finance Phase 2 — Fiscal Periods & Close

**Date:** 2026-06-18
**Status:** Approved design — ready for implementation plans
**Phase:** 2 of 4 in the Finance "source of all monetary throughput" roadmap

## Roadmap context

Phase 1 (Universal Posting) is complete: every money event posts a balanced
journal entry through the single `JournalPostingService::post()` mutator (wrapped
by `PostingService`). Phase 2 makes the ledger *period-aware and tamper-resistant*:
a fiscal calendar, a closed-period posting guard, month-end close/reopen/lock,
journal immutability, and subledger↔GL reconciliation. Phase 3 (statements) and
Phase 4 (budgeting) build on an accurate, closeable ledger.

## Decisions (locked during brainstorming)

| Decision | Choice |
|---|---|
| Fiscal calendar | **Calendar year (Jan–Dec) + a period-13 "Adjustment" bucket** for year-end auditor entries |
| Period states | **Open → Closed → Locked** (Closed reopenable by privileged role + audit; Locked permanent) |
| Close vs subledger discrepancy | **Warn and allow with an audited override** (not a hard block) |
| Posting guard on undefined periods | **Allow** (no fiscal calendar set up for a date → no restriction) — keeps existing tests green; production is covered by seeding the calendar |

## Architecture

The posting choke point is `JournalPostingService::post()` — every post (including
reversals and all Plan-3-refactored services) flows through it. The closed-period
guard hooks there, in one place. Period close/reopen/lock and blocked-posting
attempts are recorded in the **existing tamper-evident audit chain** (`AuditLog`),
not a new audit system.

## Data model

```
fiscal_years                     fiscal_periods
──────────────────────           ────────────────────────────────────────────
id                               id
year (int, unique e.g. 2026)     fiscal_year_id → fiscal_years (cascade)
status (open/closed/locked)      period_no (1–13; 13 = Adjustment)
starts_on / ends_on              name ("January 2026" … "Adjustment 2026")
timestamps                       starts_on / ends_on  (1–12 = calendar months)
                                 status (open/closed/locked)
                                 closed_at / closed_by / locked_at / locked_by
                                 timestamps
                                 unique (fiscal_year_id, period_no)
```

- `FiscalPeriodStatus` enum: `Open`, `Closed`, `Locked` (string-backed).
- Periods 1–12 are calendar months, resolvable by date. Period 13 (Adjustment)
  is targeted explicitly (no date-based resolution) for year-end entries.
- `journal_entries` gains a nullable `fiscal_period_id` (FK), stamped at post time.
- `fiscal_years.status` is a convenience rollup; the authoritative posting gate is
  the individual `fiscal_periods.status`.

## P2-1 — Fiscal periods + posting guard + immutability (foundation)

### Components
1. **`fiscal_years` + `fiscal_periods` tables + models + factory.** `FiscalYear hasMany FiscalPeriod`; `FiscalPeriod belongsTo FiscalYear`, `closedBy`/`lockedBy` → User.
2. **`FiscalPeriodStatus` enum** (Open/Closed/Locked) with labels.
3. **`FiscalCalendarSeeder`** — seeds the current + next calendar year, 13 periods each, all `Open`. Idempotent (upsert by `(year, period_no)`). Registered in `DatabaseSeeder`. A `FiscalCalendarService::ensureYear(int $year)` helper generates a year's 13 periods (12 month ranges + adjustment) so the seeder and an admin "open next year" action share one generator.
4. **`PeriodResolver`** — `resolveForDate(Carbon|string $date): ?FiscalPeriod` returns the period (1–12) whose `[starts_on, ends_on]` contains the date, or null if none defined.
5. **`ClosedPeriodException`** (`App\Exceptions\Finance`, extends `DomainException`).
6. **Posting guard** in `JournalPostingService::post()`: before mutating balances, resolve the entry's period by `entry_date`. If `Closed`/`Locked` → throw `ClosedPeriodException` (and record an audit event); if `Open` → set `entry.fiscal_period_id`; if none → proceed unrestricted, no stamp.
7. **`journal_entries.fiscal_period_id`** nullable FK + fillable.
8. **Immutability guards**:
   - `JournalLine::booted()` — throw if updating or deleting a line whose entry is not `Draft` (financial content is write-once).
   - `JournalEntry::booted()` — throw on delete/forceDelete of a `Posted`/`Reversed` entry. Status-transition updates (Draft→Posted, Posted→Reversed) remain allowed.

### Why "no period defined → allow"
ALL existing posting tests create JEs with arbitrary dates and do not seed a
fiscal calendar. A guard that *required* a defined period would break hundreds of
tests. Instead the guard only *blocks* periods an admin has explicitly Closed/Locked.
Production gets full coverage because `DatabaseSeeder` seeds the calendar (all Open).
Future hardening (require a period for every post) is out of scope.

### Reversal interaction
`reverse()` posts the reversal JE dated `now()` → it lands in the current period and
is guarded normally. Marking the original entry `Reversed` is a header status update
(not a `post()`), so it is not re-guarded — standard "reverse in the current period".

## P2-2 — Close / reopen / lock workflow + audit + admin UI

- **Close** (`finance.period.close`): runs the subledger recon (P2-3), surfaces
  discrepancies; the admin closes the period (Open→Closed). A close performed
  despite a non-zero variance records the override (with the variance) in the
  audit chain. Sets `closed_at`/`closed_by`.
- **Reopen** (`finance.period.reopen`, higher/separate grant): Closed→Open, audited.
  A `Locked` period cannot be reopened.
- **Lock** (`finance.period.lock`): Closed→Locked, permanent, audited.
- **Fiscal Calendar admin UI** (`Finance/FiscalCalendar/Index.vue`): lists the
  year's periods with status + close/reopen/lock actions, gated by permission and
  `2fa:fresh` (matching the money-mutating posture of payments/journal posting).
  Controller + FormRequests + Resource per the project's Enum→FormRequest→Service→Resource pattern.
- A `PeriodCloseService` encapsulates the state transitions + audit writes + the
  pre-close recon call.

## P2-3 — Subledger↔GL reconciliation

- **`SubledgerReconciliationService`** compares each subledger to its GL control
  account and returns rows `{ subledger, subledger_total, gl_balance, variance }`:
  - AP: Σ vendor-invoice outstanding (`total − amount_paid`, approved/partially-paid) vs GL **2100**
  - AR: Σ customer-invoice outstanding (`total − amount_received`) vs GL **1200**
  - Loans: Σ loan `outstanding_balance` (active) vs GL **1300**
  - (Bank reconciliation already exists from F5 and is not re-implemented.)
- Surfaced as a **report** (Finance UI + endpoint) and consumed by `PeriodCloseService`
  as the **pre-close check** (warn + audited override).
- Variance tolerance `0.005` (matching the JE balance tolerance).

## Error handling & integrity

- **Fail-loud**: posting into a Closed/Locked period throws `ClosedPeriodException`
  with a clear message naming the period; the business event fails (its transaction
  rolls back) rather than posting into a sealed period.
- **Audit**: close/reopen/lock and blocked-posting attempts are recorded in the
  existing tamper-evident `AuditLog` chain.
- **Immutability**: posted ledger content cannot be edited or deleted — only reversed.
- **Atomicity**: the guard runs inside the existing post transaction; rejection rolls
  back the whole business event (e.g. a payroll approval that targets a closed period
  fails wholesale).

## Testing (Pest)

- **P2-1**: period resolution (date→period; out-of-range→null); guard blocks
  Closed + Locked, allows Open + undefined, stamps `fiscal_period_id`; immutability
  (line update blocked, line delete blocked, entry delete blocked once posted; Draft
  still editable); existing finance/payroll/loan/disbursement suites stay green.
- **P2-2**: close requires permission; close records audit; reopen permission +
  Locked-can't-reopen; lock permanence; UI permission gate; override-on-variance audited.
- **P2-3**: recon detects an injected AP/AR/loan variance; ties out to zero when
  balanced; close override path records the variance.

## Conventions to follow

- Enum → FormRequest → Service → Event → Resource for new modules.
- DB-backed permissions; per-user JSON `permissions` column for test grants.
- New finance references via `SequenceService` (n/a here — no new sequences).
- Money-mutating / integrity-mutating routes behind permission + `2fa:fresh`.

## Out of scope (later phases / future hardening)

- Financial statements / Trial Balance / P&L / Balance Sheet / Cash Flow (Phase 3).
- Budgets / encumbrances (Phase 4).
- Requiring a defined fiscal period for *every* post (current design allows undefined).
- Multi-currency period revaluation.
