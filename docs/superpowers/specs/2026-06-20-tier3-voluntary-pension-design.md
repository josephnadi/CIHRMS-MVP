# Tier-3 Voluntary Pension — Design

**Date:** 2026-06-20
**Status:** Approved design — ready for implementation plan
**Context:** Gov-grade audit found Tier-3 voluntary pension stubbed — `PayrollLine.tier3_employee` is hardcoded to `0.0` ("will be wired when Tier-3 lands"), even though the enum (`DeductionType::Tier3Voluntary`), the return kind (`StatutoryReturnKind::Tier3`), the totals column (`PayrollRun.tier3_total`), the GL account (2230 / `payroll.tier3_payable`), the rate constant (`TIER3_MAX_COMBINED`), and the GIFMIS export already exist. This wires the actual computation.

## Decisions (locked)

| Decision | Choice |
|---|---|
| Contribution basis | **Percentage of basic** — an employee elects `tier3_rate` (e.g. 0.05 = 5% of basic); contribution = `rate × basic` each run. Stored on the employee, mirroring `tier2_trustee_id`. |
| Tax relief | **Full relief with the 16.5% combined cap** — the relieved portion of Tier-3 reduces PAYE chargeable income (exactly as Tier-1 employee already does), capped so Tier-2 (5%) + relieved Tier-3 ≤ 16.5% of basic (`TIER3_MAX_COMBINED`). Any elected excess above the cap is still deducted from net pay but is NOT relieved (it stays in chargeable, i.e. taxed). |
| Trustee routing | Optional `tier3_trustee_id` (FK `pension_trustees`, nullable) — the Tier-3 statutory schedule groups by trustee, mirroring Tier-2. |

## The math (Tier3Calculator)

For `basic` and the employee's elected `rate`, effective-dated:
- `elected   = round(basic × rate, 2)` — the full Tier-3 deduction.
- `cap       = TIER3_MAX_COMBINED (0.165) × basic`; `tier2 = TIER2_EMPLOYER (0.05) × basic`.
- `availableRelief = max(0, cap − tier2) = round(0.115 × basic, 2)`.
- `relieved  = min(elected, availableRelief)` — reduces PAYE chargeable.
- `excess    = elected − relieved` — deducted but taxed.

`employee = elected` (the cash deducted). `relieved` feeds the chargeable reduction. Zero rate / zero basic → all zero (no-op).

## Payroll engine change (`PayrollService::calculateLine`)

Today: `chargeable = max(taxableGross − ssnit_employee, 0)`; `tier3_employee = 0.0`; `net = gross − ssnit_employee − paye`.

New (only changes for an enrolled employee; `rate = 0/null` → identical to today, so existing payroll tests stay green):
- `$tier3 = $this->tier3->calculate($basic, (float) $employee->tier3_rate, $periodDate)`.
- `$chargeable = max(round($taxableGross − $ssnit['employee'] − $tier3['relieved'], 2), 0)` — relieved Tier-3 reduces the PAYE base.
- `'tier3_employee' => $tier3['employee']`.
- `$net = round($gross − $ssnit['employee'] − $tier3['employee'] − $paye, 2)` — full Tier-3 leaves net pay.

## GL accrual + statutory return

- **Accrual JE** (`buildAccrualDocument`): add `$credit('payroll.tier3_payable', round((float) $run->tier3_total, 2), 'Tier-3 voluntary')`. Balanced by construction — the line `net` already dropped by Σ Tier-3, so `tier3_total` credited to 2230 restores the balance (`DR gross = net + paye + ssnit + tier2 + tier3 + loans + voluntary`).
- **Statutory return** (`StatutoryReturnGenerator`): add `generateTier3PerTrustee` mirroring `generateTier2PerTrustee` — group lines by `tier3_trustee_id`, write a `StatutoryReturnKind::Tier3` schedule per trustee summing `tier3_employee`; call it in `generateAll`. Employee gains a `tier3Trustee()` relation.

## Enrolment

- Migration: add `employees.tier3_rate` (decimal(6,4), default 0) + `employees.tier3_trustee_id` (nullable FK `pension_trustees`, nullOnDelete).
- `Employee`: fillable + cast `tier3_rate` decimal:4 + `tier3Trustee()` belongsTo.
- The employee store/update FormRequest validates `tier3_rate` (nullable, numeric, 0–0.5 sanity bound) + `tier3_trustee_id` (nullable, exists). The employee edit form gets a Tier-3 rate field + trustee select.

## Error handling & integrity

- **Backwards-compatible**: an employee with no Tier-3 election (`rate` null/0) computes exactly as before — `tier3 = 0`, chargeable/net/JE unchanged. Existing payroll tests must remain green.
- **Excess is taxed, not lost**: elected above the relief cap is still deducted (the employee saves it) but does not reduce chargeable — it's taxed, which is correct.
- **Effective-dated rates**: cap + Tier-2 rate via `StatutoryRate::lookup` on the period end (already the pattern).

## Testing (Pest)

- `Tier3Calculator`: elected = rate×basic; relieved capped at (16.5%−5%)×basic; excess = elected − relieved; zero-rate no-op; a high rate (e.g. 15%) splits into relieved 11.5% + excess.
- Payroll line: an enrolled employee's run yields the right `tier3_employee`, a PAYE lower than the no-Tier-3 case (relief applied), and `net` reduced by the full Tier-3; a non-enrolled employee is byte-identical to today.
- Accrual JE balances with Tier-3 (DR = CR; 2230 credited by `tier3_total`).
- Statutory return: a Tier-3 schedule is generated per trustee summing the contributions.

## Conventions

- Mirror `Tier2Calculator` / `generateTier2PerTrustee` / `tier2_trustee_id`. `declare(strict_types=1)`; effective-dated `StatutoryRate::lookup`.

## Out of scope (future)

- Employer voluntary Tier-3 contributions (only the employee share here); flat-amount elections (percentage only); per-employee Tier-3 provider statements; mid-year election change history/audit; Tier-3 remittance tracking is covered by the existing remittance-tracking feature once the schedule is generated.
