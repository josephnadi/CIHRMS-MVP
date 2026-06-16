# Finance Phase 1 — Universal Posting (the true General Ledger)

**Date:** 2026-06-16
**Status:** Approved design — ready for implementation plan
**Phase:** 1 of 4 in the Finance "source of all monetary throughput" roadmap

## Roadmap context

CIHRMS already has a large Finance module (GL, AP, AR, banking, Paystack
gateway, bank reconciliation). To make Finance the accurate single source of
truth for all money, the work is decomposed into four phases, each its own
spec → plan → build cycle:

1. **Universal Posting** (this spec) — every money event posts one balanced
   journal entry through a single pathway driven by a DB-backed account map.
2. **Fiscal Periods & Integrity** — fiscal calendar, posting guard for
   closed/locked periods, month-end close, journal immutability + audit,
   subledger↔GL reconciliation.
3. **Financial Statements & Reporting** — Trial Balance, Income Statement,
   Balance Sheet, Cash Flow, GL drill-down, period comparison.
4. **Budgeting & Controls** — budgets vs actuals, variance, thresholds,
   optional encumbrances.

## Problem

Journal posting exists (`JournalPostingService::post()` is the single mutator
of `gl_account_balances`), and AP/AR/bank/gateway services post entries. But:

- **Payroll, payments, loans, disbursements, and member billing post zero
  journal entries today.** These real money movements live entirely outside
  the GL, so the ledger is not a complete source of truth.
- Every posting service hand-builds its `JournalEntry` + lines and selects GL
  accounts inline. There is no shared pathway and no central account map, so
  posting logic is duplicated and account selection is hardcoded.

## Decisions (locked during brainstorming)

| Decision | Choice |
|---|---|
| Accounting model | **Full accrual + control accounts** (two-step accrue-then-settle; summarized per run/batch) |
| Account map storage | **DB-backed `posting_accounts` table + admin UI** (seeded defaults, finance-admin overridable, audited) |
| Existing AP/AR/bank/gateway services | **Refactor everything** onto the new engine for one uniform pathway (existing tests stay green as a safety net) |
| Posting timing | **Synchronous & atomic** — JE posts in the same DB transaction as the business event |

## Architecture

```
Business event (payroll / loan / fee / AP / AR / disbursement / gateway)
        │  builds a structured request
        ▼
   PostingDocument { sourceType, sourceId, purpose, date, narration, lines[] }
        │
        ▼
   PostingService ──uses──► AccountResolver ──reads──► posting_accounts (DB map)
        │  (resolve slugs, generate JE ref, idempotency guard, atomic)
        ▼
   JournalPostingService::post()   ← unchanged: balance check + ledger mutation
        ▼
   journal_entries + journal_lines + gl_account_balances
```

`JournalPostingService` remains the single mutator of `gl_account_balances`.
`PostingService` is a new layer *above* it that removes per-service
boilerplate. There is exactly one choke point for posting, which Phase 2 will
later extend with a closed-period guard.

## Components

Each is a focused, independently-testable unit.

### 1. `PostingDocument` / `PostingLine` (value objects)
- Immutable description of a journal request.
- `PostingDocument`: `sourceType` (JournalSourceType), `sourceId` (int|null),
  `purpose` (string|null discriminator), `date`, `narration`, `currency`,
  `lines` (PostingLine[]).
- `PostingLine`: account reference (EITHER `accountSlug` for fixed control
  accounts OR `accountId` for per-record dynamic accounts), `debit`, `credit`,
  `narration`. Exactly one of debit/credit is non-zero.

### 2. `posting_accounts` table + model + seeder
- Columns: `id`, `slug` (unique), `gl_account_id` (FK gl_accounts),
  `domain` (grouping, e.g. payroll/loans/member_fees/ap/ar/bank),
  `description`, `locked` (bool — system-critical, not deletable),
  `timestamps`.
- Seeder maps each slug to a chart-of-accounts code; idempotent (upsert by
  slug) so re-seeding is safe.

### 3. `AccountResolver`
- `resolve(string $slug): GlAccount`.
- Throws `MissingAccountMappingException` (names the slug) when unmapped or the
  mapped account is inactive/archived.

### 4. `PostingService` (the engine)
- `post(PostingDocument $doc): JournalEntry` — resolves account references,
  generates the JE reference via `SequenceService`, creates the draft
  `JournalEntry` + `JournalLine`s, enforces idempotency, then delegates to
  `JournalPostingService::post()` for balance validation + ledger mutation.
- `reverseFor(JournalSourceType $type, int $sourceId, ?string $purpose, User $by, string $reason)`
  — convenience for void/cancel; locates the posted entry and calls
  `JournalPostingService::reverse()`.
- **Idempotency:** one post per `(source_type, source_id, source_purpose)`.
  Default behavior: re-invocation detects the existing posted entry and
  returns it unchanged (no-op), so retries are safe. The DB unique index is
  the hard backstop; if a concurrent race attempts a true duplicate insert,
  the constraint violation surfaces as a typed `AlreadyPostedException`.

### 5. Admin UI — Finance → Posting Rules
- `PostingRuleController` (index + update), `UpdatePostingRuleRequest`,
  `PostingRuleResource`, Vue page `resources/js/Pages/Finance/PostingRules/Index.vue`.
- Lists slugs grouped by `domain`; finance admin reassigns the `gl_account_id`
  (locked rows show but are constrained). Remaps are audited.
- New permission `finance.posting_rules.manage`; route under the existing
  `finance.` group; link from the Finance Hub.

### 6. Chart-of-accounts control accounts
- Seed (if absent) the accounts the accrual model needs: Salary/Allowance
  Expense, Employer SSNIT/Tier-2 Expense, PAYE Payable, SSNIT Payable, Tier-2
  Payable, Net-Pay Payable, Loans Receivable, Interest Income, Member
  Receivable, Member Fees Income, Disbursement Clearing (optional in-transit).
- Extend the existing chart seeder; idempotent.

## Data model changes

- New table `posting_accounts` (see component 2).
- Extend `JournalSourceType` enum with: `Payroll`, `Disbursement`,
  `LoanDisbursement`, `LoanRepayment`, `MemberFee` (existing cases kept).
- Add nullable `source_purpose` (string) to `journal_entries` and a unique
  index on `(source_type, source_id, source_purpose)` for idempotency.
- Add a `journal_entry_id` link to source models that lack it (payroll run,
  loan, member invoice, disbursement batch) where useful for traceability.

## Accrual posting templates (control accounts)

Summarized per run/batch; each is one balanced JE linked to its source.

| Event | Debit | Credit |
|---|---|---|
| Payroll run finalized | Salary/Allowance Expense (gross) | PAYE Payable, SSNIT Payable, Tier-2 Payable, Loan Receivable (deductions), Net-Pay Payable (net) |
| Employer contributions | Employer SSNIT/Tier-2 Expense | SSNIT/Tier-2 Payable |
| Disbursement batch settled | Net-Pay Payable (clears liability) | Bank |
| Loan disbursed | Loan Receivable (principal) | Bank |
| Loan repayment (cash or payroll) | Bank / Net-Pay Payable | Loan Receivable (principal) + Interest Income (interest) |
| Member fee raised | Member Receivable | Member Fees Income |
| Member fee paid | Bank | Member Receivable |

## Event wirings (the 5 gaps)

Each builds a `PostingDocument` and calls `PostingService::post()` inside the
same transaction as the event:

1. **Payroll** — `Payroll\PayrollService` on run finalize/approve → accrual JE
   (gross expense → payables + net-pay-payable; employer contributions). Loan
   deductions credit Loan Receivable, tying payroll to loans.
2. **Disbursement** — `Disbursement\BatchDisbursementService` on batch
   settlement → clears Net-Pay Payable (and other payables) to Bank.
3. **Loans** — `Loans\LoanService` on disbursement and on repayment.
4. **Member fees / billing** — `Billing\MemberRegistrationService`,
   `Messaging\Ussd\MemberFeesHandler`, and portal fee payment → raise + settle
   member receivable.
5. **Gateway** — `Finance\PaystackGatewayService` settlements refactored onto
   the engine (clears the relevant receivable).

## Migration of existing services

Refactor onto `PostingService` (you chose refactor-everything for one uniform
pathway): `ApPaymentService`, `VendorInvoiceService`, `ArInvoiceService`,
`ArReceiptService`, `BankAdjustmentService`, `PaystackGatewayService`.

**Safety net:** their 254+ existing tests must stay green. The JE output is
equivalent, so add characterization assertions (compare resulting journal
lines + balance deltas) before/after each migration, one service at a time.
Dynamic accounts (`ap_gl_account_id`, chosen bank account) pass as literal
`accountId` on `PostingLine`; behavior is unchanged.

## Error handling & integrity

- **Atomic:** JE posts in the same DB transaction as the business event —
  commit together or both roll back. No money event without its journal.
- **Idempotent:** re-running a finalize / webhook cannot double-post (unique
  guard on source + purpose).
- **Reversible:** void/cancel → `PostingService::reverseFor()` → existing
  `reverse()`.
- **Fail-loud:** unmapped account or unbalanced document throws immediately
  with a clear message; the event fails rather than posting wrong.
- **Phase-2-ready:** single choke point for a future closed-period guard.

## Testing (Pest, per project convention)

- Unit: `PostingService` (ref generation, idempotency, balance, reversal),
  `AccountResolver` (resolve + missing-mapping), `PostingDocument` validation.
- Feature: each of the 5 new wirings produces the correct accrual JE *and*
  correct `gl_account_balances` deltas; idempotency; void → reversal.
- Characterization: refactored AP/AR/bank/gateway services produce identical
  journal lines + balance deltas to current behavior.
- Admin UI: posting-rules permission gate; remap persists and is audited.

## Conventions to follow

- Project pattern: Enum → FormRequest → Service → Event → Resource.
- New finance references use `SequenceService::next()` (never count()+1).
- DB-backed permissions; per-user JSON `permissions` column for test grants.
- Money-mutating routes already use `2fa:fresh` where appropriate — keep
  posting-rules management behind permission + (optionally) 2FA.

## Out of scope (later phases)

- Fiscal periods / closed-period posting guard (Phase 2).
- Financial statements / reporting (Phase 3).
- Budgets / encumbrances (Phase 4).
- Multi-currency revaluation.
