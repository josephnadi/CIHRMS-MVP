# Finance — Final Settlement → GL Posting

**Date:** 2026-06-19
**Status:** Approved design — ready for implementation plans
**Context:** Off-boarding follow-up to the Finance "source of all monetary throughput" roadmap. Closes the gap where the entire final settlement (gratuity, severance, leave encashment, PAYE, loan clearing, net pay) posts **zero** journal entries — most acutely, an off-boarding loan is "closed" without ever crediting Loans Receivable (GL 1300), so the asset is overstated forever and the subledger silently masks it.

## Problem

`OffboardingService::approveSettlement()` → `closeOutstandingLoans()` waives the scheduled `LoanRepayment` rows, zeroes `loan.outstanding_balance`, and flips the loan to `PaidOff` — but posts nothing to the GL. Consequences:

- **GL 1300 (Loans Receivable)** keeps the cleared principal as a debit balance indefinitely.
- `SubledgerReconciliationService::loanPrincipalOutstanding()` sums `principal_portion WHERE status != Paid`, and `Waived != Paid`, so the subledger *also* still counts the cleared principal. Both sides are overstated by the same amount → reconciliation shows **no variance** and the drift is masked.
- The whole settlement (gross earnings, PAYE, deductions, net pay) is off-ledger: termination expense is never recognized, and the org's terminal-pay liability never appears.

## Decisions (locked)

| Decision | Choice |
|---|---|
| Posting scope | **Accrual + payment** — book the balanced accrual JE at `approveSettlement`, and a separate payment JE when the settlement is marked paid (end-to-end on-ledger) |
| Negative net payable | **Clear the loan only up to what gross covers; leave the uncovered principal as a live receivable** (no auto write-off; the uncovered installments stay un-waived and owed) |
| New accounts | **One** new expense account `5130 Termination & Severance Benefits Expense`; route garnishments + other deductions both to the existing `2250 Voluntary Deductions Payable` |
| Loan clearing basis | Credit `1300` by **principal** and `4600` by **interest** of the cleared installments (GL 1300 only ever held principal; interest is income) — never by the gross `outstanding_balance` |

## The accounting

The calculator already yields a balanced structure:
- `gross = gratuity + severance + leave_encashment + prorated_13th_month + ex_gratia`
- `total_deductions = outstanding_loans + garnishments + other_deductions + paye_on_settlement`
- `net_payable = gross − total_deductions`

### Accrual JE — posted on `approveSettlement` (purpose `accrual`)

Let `nonLoan = paye + garnishments + other` and `loanCleared = min(outstanding_loans, max(0, gross − nonLoan))`. In the **normal case** (`net_payable ≥ 0`) `loanCleared == outstanding_loans` and the floor is a no-op. Split `loanCleared` into `principalCleared` / `interestCleared` proportionally from the actual cleared `LoanRepayment` rows. `netPay = gross − nonLoan − loanCleared` (≥ 0 by construction).

| | Account (slug) | Amount |
|---|---|---|
| **DR** | `settlement.benefits_expense` → 5130 | gross_settlement |
| **CR** | `settlement.paye_payable` → 2210 PAYE Payable | paye_on_settlement |
| **CR** | `loan.principal_receivable` → 1300 | principalCleared |
| **CR** | `loan.interest_income` → 4600 | interestCleared |
| **CR** | `settlement.deductions_payable` → 2250 | garnishments + other_deductions |
| **CR** | `settlement.net_pay_payable` → 2300 Salaries Payable | netPay |

Zero-amount legs are omitted (e.g. no interest, no PAYE, no deductions). Balanced by construction: `DR gross = paye + (garn+other) + loanCleared + netPay`.

### Payment JE — posted when the settlement is marked paid (purpose `payment`)

| | Account | Amount |
|---|---|---|
| **DR** | `settlement.net_pay_payable` → 2300 Salaries Payable | net paid |
| **CR** | active **Payroll-purpose** bank GL (resolved by `OrgBankAccountPurpose::Payroll`, fail-loud if none) | net paid |

Clears the liability the accrual raised. The payment amount equals the accrual's `netPay` (the floored, non-negative figure), not the snapshot's possibly-negative `net_payable`.

## Architecture

A new `SettlementPostingService` (in `app/Services/Offboarding/` or `app/Services/Finance/`) owns both JEs, built as `PostingDocument`s through the single `PostingService` choke point (so the closed-period guard and idempotency apply automatically). `OffboardingService::approveSettlement()` calls it after the status flip; a new `paySettlement()` action calls the payment leg.

```
approveSettlement → SettlementPostingService::postAccrual(settlement, actor)
                      ├─ compute loanCleared (floor) + principal/interest split from waived rows
                      ├─ closeOutstandingLoans waives ONLY the cleared installments
                      └─ PostingService::post(accrual doc)            [purpose 'accrual']
paySettlement     → SettlementPostingService::postPayment(settlement, actor)
                      └─ PostingService::post(payment doc)            [purpose 'payment']  + status→Paid, paid_at
SubledgerReconciliationService::loanPrincipalOutstanding → exclude Waived (and Paid)
```

- New `JournalSourceType::FinalSettlement = 'final_settlement'`.
- New GL account `5130` (ChartOfAccountsSeeder) + posting slugs (`settlement.benefits_expense`, `settlement.paye_payable`, `settlement.deductions_payable`, `settlement.net_pay_payable`) in `PostingAccountSeeder`; loan legs reuse `loan.principal_receivable` / `loan.interest_income`.
- Idempotency keys: `(FinalSettlement, settlement.id, 'accrual')` and `(…, 'payment')` — re-approval / re-pay can't double-post.

## Loan clearing detail (the core correctness fix)

`closeOutstandingLoans` today waives **all** scheduled installments unconditionally. New behavior:
- Gather the employee's open loans' `Scheduled` installments. `principalCleared = Σ principal_portion`, `interestCleared = Σ interest_portion` of the installments actually cleared.
- In the **normal case**, clear all → waive all, exactly as today, but now with a GL credit.
- In the **shortfall case** (`loanCleared < outstanding_loans`), clear installments up to `loanCleared` (oldest-first); waive only those, **leave the rest `Scheduled`** (still owed — they remain in 1300 and in the subledger). The loan stays `Repaying`, not `PaidOff`, when principal remains.
- `SubledgerReconciliationService::loanPrincipalOutstanding()` changes to exclude `Waived` (and `Paid`) so the cleared principal drops from the subledger in lockstep with the GL credit, while genuinely-uncovered installments still count.

## Error handling & integrity

- **Drift guard**: assert `principalCleared + interestCleared == loanCleared` within the JE tolerance (0.005); the snapshot's `outstanding_loans` is rebuilt from current installments at posting time to avoid stale-snapshot drift.
- **Closed period**: posting flows through `JournalPostingService`, so a closed/locked period throws `ClosedPeriodException` automatically — no new code.
- **Idempotency**: the unique source index prevents double accrual/payment.
- **Dual control & status guards** are unchanged (calculator ≠ approver; only Calculated → Approved; only Approved → Paid).
- **Reversal of an approved settlement** is **out of scope** — `approveSettlement` remains one-way and `cancel()` on a case still does not touch the GL. If reversal is later needed, it posts a `PostingService::reverseFor` of both purposes. Flagged, not built.
- **Negative gross-vs-statutory** (gross can't even cover PAYE + garnishments) is rarer than the loan shortfall; `loanCleared` floors to 0 and `netPay` floors to 0, leaving the entire loan as a receivable. Documented; no special handling beyond the floor.

## Permissions & UI

- The payment action is permission-gated (reuse the existing off-boarding settlement permission; the approver/payer attribution already exists via `approved_by`/`paid_at`). 2FA-fresh is optional and not required by this spec.
- The off-boarding settlement view surfaces the accrual + payment JE references (drill-through to the GL) and a **Mark settlement paid** action on an Approved settlement.

## Testing (Pest)

- **Accrual**: balanced JE on approve; correct legs/amounts; loan principal→1300, interest→4600; PAYE→2210; deductions→2250; netPay→2300; zero-legs omitted; idempotent (re-approve = no second JE).
- **Loan clearing**: normal case clears all + 1300 nets to 0 for that loan; subledger principal-outstanding drops to 0; shortfall case clears partial, leaves remaining installments `Scheduled` and still counted by the subledger, loan stays `Repaying`.
- **Payment**: DR 2300 / CR payroll bank for netPay; status → Paid + `paid_at`; idempotent; fail-loud when no active payroll bank.
- **Subledger**: after a normal settlement, `SubledgerReconciliationService` shows **no** variance on 1300 (both GL and subledger dropped together); pre-fix this is the regression that was masked.
- **Edge**: negative net_payable → loanCleared floored, netPay 0, uncovered principal remains in 1300 and in the subledger; JE still balances.

## Conventions to follow

- Enum → FormRequest → Service → Resource; DB-backed permissions; per-user JSON `permissions` for test grants; all posting through `PostingService`; distinct `source_purpose` per JE so the idempotency index doesn't collide; `declare(strict_types=1)`; `casts()` method form.

## Decomposition (implementation plans)

- **S-1 — Accrual + loan clearing + subledger fix**: new `5130` account + slugs + `JournalSourceType::FinalSettlement`; `SettlementPostingService::postAccrual`; rework `closeOutstandingLoans` (principal/interest split, shortfall floor); `SubledgerReconciliationService` Waived exclusion; wire into `approveSettlement`; full test set. *This alone fixes the loan-clearing correctness gap and the subledger masking.*
- **S-2 — Payment leg + UI**: `SettlementPostingService::postPayment`; `OffboardingService::paySettlement` (Approved → Paid) + controller action + permission + off-boarding UI (mark-paid + JE drill-through); tests.

## Out of scope (future)

- Reversal/cancellation of an approved or paid settlement (GL un-posting).
- Splitting `5130` into separate gratuity/severance/leave/ex-gratia expense accounts (single account + narration for now).
- Routing settlement net pay through the existing payroll/disbursement batch machinery instead of a dedicated payment step.
- Lump-sum gratuity PAYE treatment (the calculator already flags this as a future enhancement).
