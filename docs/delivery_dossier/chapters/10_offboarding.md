# Chapter 10 — Off-boarding & Clearance

> *In one paragraph.* Off-boarding is how CIHRMS closes the book on a departing employee in a way that finance, IT, the line manager, the pensions trustee, and the Auditor-General can all reconcile against. Every separation — whether it is a clean resignation, a contract running out, a redundancy under Act 651 §31, a dismissal-for-cause, or a death in service — opens a single **off-boarding case**: a tracked ledger of who has signed off what, what the final settlement comes to, who calculated it, who approved it (under dual control), and when the employee's status finally flips to *terminated*. The gap analysis flagged this domain as "missing"; the system in fact ships a full Inertia module — `/offboarding` — that runs the case from initiation through clearance, settlement, approval, and termination.

## Where to find it

- **Sidebar location:** **Workforce** group → **Off-boarding** (top-level item with the `logout` icon). The link is registered against the `offboarding` module key and uses the sky/cobalt palette in `useIconPalette.js`.
- **Roles that see it:**
    - **super_admin** and **ceo** — every case, every action.
    - **hr_admin** — sees and initiates all cases; can clear items, calculate settlements, and complete the case (`offboarding.view`, `offboarding.initiate`, `offboarding.clear`, `offboarding.settle`, `offboarding.manage`).
    - **finance_officer** — views all cases and is the dual-control approver of the final settlement; can also recalculate before approval (`offboarding.view`, `offboarding.settle`, `offboarding.approve`).
    - **dept_head / manager** — no direct grant in the seeder, but `OffboardingCasePolicy::view` lets a department head see the cases for employees in the department(s) they manage (via `User::managesDepartment()`).
    - **employee** — sees only their own off-boarding case (the policy returns true when `$case->employee->user_id === $user->id`); the sidebar link is hidden because `can('offboarding.view')` is false.
    - **auditor** — read-only across the audit log (no direct module permission in MVP — escalates through the `audit.view` lane in Chapter 24).
- **Related modules:** Employees (Ch 3) — the case writes the employee's status to *terminated* on completion; Leave (Ch 4) — unused annual-leave days become leave encashment in the settlement; Payroll (Ch 19) — the final settlement carries a `payroll_line_id` so the off-cycle disbursement can be linked; Loans (Ch 21) — outstanding loan balances are netted off the gross and the schedule waived on approval; Identity (Ch 25) — the `complete` route is gated behind `2fa:fresh` so a fresh second factor is mandatory before the termination is committed; DPA (Ch 26) — the cancelled / completed dates and the breakdown JSON are retained for the statutory window.

## The screens

![Off-boarding directory — masthead, filter strip, case-card grid](../assets/screenshots/10_offboarding/directory.png)

*Callouts: ❶ Masthead — "OFF-BOARDING DOSSIER" eyebrow with the `logout` glyph, headline, and a one-line subtitle that names the legal anchor ("Every separation logged against Act 651 §17 · clearance across IT, Finance, HR, line manager · dual-control settlement"). · ❷ Filter strip — debounced free-text search across case reference and employee name; status dropdown (Draft / In Progress / Awaiting Settlement / Settled / Completed / Cancelled); exit-type dropdown (all eight enum values, see below); one-click "Clear". · ❸ Case card — gradient avatar with initials, employee name and department, status badge, an exit-type chip with a colour-coded background and a contextual icon (resignation = `exit_to_app`, retirement = `elderly`, dismissal = `gavel`, redundancy = `group_remove`, death = `sentiment_sad`, abscondment = `person_off`, mutual = `handshake`, end-of-contract = `event_busy`), an LWD (Last Working Day) chip that turns amber within 14 days and red within 7, a clearance-progress bar that turns green at 100%, and a Net Settlement row that shows the calculated figure or "Not yet calculated" italicised when no snapshot exists.*

![Off-boarding case detail — hero, action bar, clearance accordion](../assets/screenshots/10_offboarding/detail.png)

*Callouts: ❶ Hero card — exit-type glyph in an amber well, employee name, department, employee number (monospace), exit-type chip, LWD countdown chip (urgent → red, past → muted), and three quick stats: clearance signed-off ratio (`6 / 12`), clearance progress percentage, and net settlement. · ❷ Four stat-cards — Notice Received, Last Working Day, Clearance Progress, Net Settlement — each with a 3px left border in a brand colour. · ❸ HR Action bar (shown only when the viewer has `complete` or `approve_settle` permission) — "Complete Case & Terminate" (green gradient, marked `(2FA)`) appears when the settlement is approved; "Cancel Case" is always shown to a manager, and clicking it reveals an inline reason textbox. · ❹ Tabs — Clearance · Settlement · Audit.*

![Clearance tab — accordion grouped by area with per-item sign-off](../assets/screenshots/10_offboarding/clearance.png)

*Callouts: ❶ Area header — each clearance area (IT & Assets, Finance, HR Records, Library, Stores, Security & Access, Departmental Handover, Pension Discharge, Other) is collapsed into a panel with its own glyph (computer / account_balance_wallet / badge / menu_book / devices / security / manage_accounts / payments / checklist) and a "signed/total" pill. · ❷ Item row — status icon (filled circle = cleared, dashed circle = pending, half-circle = waived), label, an "optional" pill if `is_required = false`, the department / responsible user if assigned, the notes (italicised), and, when cleared or waived, the actor and timestamp. · ❸ Action buttons — a pending item shows "Clear" (emerald) and "Waive" (amber) side-by-side; waiving reveals an inline reason input that is `required` (the FormRequest enforces `required_if:action,waive`).*

![Settlement tab — parameter overrides, earnings, deductions, workflow buttons](../assets/screenshots/10_offboarding/settlement.png)

*Callouts: ❶ Calculation Parameters panel — gratuity months-per-year (default 1.0), severance months-per-year (default 1.5), working days-per-month (default 22), ex-gratia, prorated 13th month, other deductions, and a `pay_paye` checkbox. The panel only renders while the settlement is unapproved or absent; once approved, the figures are locked. · ❷ Earnings card — Gratuity, Severance (Act 651), Leave encashment (with the day-count in the dt), Pro-rated 13th, Ex-gratia, and a heavy "Gross Settlement" row. · ❸ Deductions card — Outstanding loans, Garnishments, Other, PAYE on settlement, total, and a highlighted "Net Payable" row in the brand cobalt. · ❹ Workflow buttons — "Approve Settlement" (emerald, `(2FA)`) is shown to the finance approver while the status is *calculated*; "Complete Case & Terminate Employee" (cobalt, `(2FA)`) takes its place once the settlement is approved.*

> The four screenshot files referenced above will be captured in Wave 1 (task W1.10). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Off-boarding directory

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Initiate Case** (cobalt CTA, top right) | Opens the right-hand slide panel for a new case. The same panel auto-opens when the user lands at `/offboarding?new=1` from a Quick Action — the flag is then stripped from the URL so a refresh or back-navigation doesn't re-open the panel. | `offboarding.initiate` (hr_admin, super_admin, ceo) | One-step initiation — HR shouldn't have to remember a separate URL for "new case". |
| **Search box** ("Search reference or employee…") | Free-text filter that fires 380 ms after typing stops. Matches `offboarding_cases.reference` and the related `users.name` of the employee. | Anyone who can see the directory | Most lookups are by case ref (`OFF-2026-NNNNN`) or by the leaver's name. |
| **All Statuses** dropdown | Filters the case list by `draft`, `in_progress`, `awaiting_settlement`, `settled`, `completed`, or `cancelled`. | Same | "In Progress" and "Awaiting Settlement" together are the daily working queue. |
| **All Exit Types** dropdown | Filters by the eight `ExitType` enum values (Resignation, Retirement, End of Contract, Dismissal, Redundancy, Mutual Separation, Death, Abscondment). | Same | Retirement and end-of-contract have very different payout profiles — segregating them in the directory removes confusion. |
| **Clear** (appears only when a filter is set) | Resets all three filters and re-queries. | Same | One-click undo. |
| **Case card row click** | Navigates to `/offboarding/{case}`. | `view` permission on that case (RBAC-checked in `OffboardingCasePolicy::view`) | Whole-card hit target is faster than a small "open" button. |
| **Open Case** (inline link, bottom-right of each card) | Same as card click — explicit affordance for screen readers and keyboard users. The `@click.stop` prevents the card-level click from firing twice. | Same | Accessibility — a card isn't naturally focusable; this link is. |
| **Pagination** | Standard Inertia pagination — 20 per page, range and total displayed when there are enough pages. | Anyone | Keeps the grid responsive when hundreds of historical cases accumulate. |

> *Notes:* The directory query eager-loads `employee.user`, `employee.department`, `settlement`, and `initiator`, so there is no N+1 between the 20 cards. The card avatar uses one of six brand-cobalt gradients indexed off `employee.id` so the same person always renders the same colour. The "Initiate Case" button writes through the `InitiateOffboardingRequest` FormRequest, which validates `last_working_day >= notice_received_on` and that `exit_type` is a valid enum case.

### Off-boarding case detail

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Back arrow** (top left) | Returns to the directory; Inertia preserves the scroll position and any filters in place. | Anyone viewing | Standard back affordance. |
| **Hero card** | Read-only display: exit-type glyph in an amber circle, employee name + department + employee number, exit-type chip, LWD countdown chip. | Anyone viewing | Identity-at-a-glance — answers "who is leaving, when, and how" in one line. |
| **4 stat cards** | Notice Received, Last Working Day (turns red when past or imminent), Clearance Progress (percentage), Net Settlement (GHS or "—" if uncalculated). | Anyone viewing | The four numbers HR rings around on a manila folder. |
| **Complete Case & Terminate** (green-gradient CTA in the HR Action bar, also in the Settlement tab) | POSTs to `/offboarding/{case}/complete`. The service refuses if any required clearance item is still pending OR if the settlement isn't approved. On success it flips the employee row to *terminated*, fires `OffboardingCompleted`, and sets `completed_at` / `completed_by`. Route is gated by `2fa:fresh` — the user must have stepped up to 2FA in the current session. | `offboarding.manage` | Termination is the irreversible bit. Forcing a fresh 2FA stops a stolen session from ending someone's employment. |
| **Cancel Case** (red outline button) | Reveals an inline reason textbox; on confirm POSTs to `/offboarding/{case}/cancel` with the reason, which is appended to the case's existing `reason` with a `[CANCELLED]` prefix. The case status becomes *cancelled* and the case is closed (not deleted). | `offboarding.manage` | Withdrawn resignations are a real thing — the audit trail wants the reason on the record. |
| **Tabs** | Clearance / Settlement / Audit. Tabs are client-side toggles; the case payload is already in the Inertia render. | Anyone viewing | Cuts the perceived load time — there is no second round-trip when changing tab. |

### Clearance tab

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Clear** (emerald button on a pending item) | POSTs to `/offboarding/{case}/clearance/{item}` with `action=clear`. The service refuses if the item isn't pending, then writes `status=cleared`, `cleared_by`, `cleared_at`, and any notes. If clearing the last required pending item, the case auto-advances from *in_progress* to *awaiting_settlement*. | `offboarding.clear` or `offboarding.manage` | Multi-department sign-off — IT marks the laptop returned, Finance marks the loans squared, HR marks the file complete. |
| **Waive** (amber button on a pending item) | Reveals an inline reason input (required), then POSTs `action=waive`. The FormRequest enforces `notes required_if:action,waive`. Waived items count toward "completed" for clearance progress but the reason is captured on the row. | Same | Real life: the laptop was already returned six months ago and was reissued to someone else; HR notes that and moves on without blocking the case. |
| **Item row, when cleared/waived** | Displays the actor and timestamp ("Cleared by Janet on 14 Jun 2026"). | Anyone viewing | Audit-grade attribution at the row level. |

> *Notes:* The default checklist seeded at case creation has 12 items across IT & Assets (3), Finance (2), HR Records (3), Stores (1, optional), Security (1), Departmental Handover (1), and Pension (1). `OffboardingService::addClearanceItem()` lets a case be extended programmatically with department-specific items — Phase 1 will surface this on the UI as an "Add clearance item" affordance. The "is_required" flag controls whether a pending item blocks `complete`: optional items can stay open forever without blocking termination.

### Settlement tab

| Field | Validation | Who can use it | Notes |
|---|---|---|---|
| **Gratuity months/yr** | numeric, 0–6 | `offboarding.settle` | Default 1.0 (one month of basic per year of service). For schemes that pay 2× per year, HR enters 2.0 before clicking Calculate. The override is saved into the `breakdown.multipliers` JSON column so the audit trail records what was used. |
| **Severance months/yr** | numeric, 0–6 | Same | Default 1.5 — applies only to `ExitType::Redundancy` (Act 651 §31). For every other exit type the calculator forces severance to zero regardless of this input. |
| **Working days / month** | numeric, 15–31 | Same | Default 22. Used to derive the daily rate for the leave-encashment formula (`accrued_leave_days × basic / wd_per_month`). |
| **Ex-gratia (GHS)** | numeric, ≥ 0 | Same | Discretionary one-off addition — useful for long-service or compassionate top-ups. |
| **Prorated 13th month** | numeric, ≥ 0 | Same | If the org policy pays a 13th cheque, HR types the pro-rated amount here. The calculator does not compute it automatically in the MVP. |
| **Other deductions** | numeric, ≥ 0 | Same | Anything Finance has flagged outside of loans and garnishments (e.g. an outstanding training-bond balance). |
| **Garnishments** | numeric, ≥ 0 | Same | Court-ordered garnishees. |
| **Apply PAYE** (checkbox) | boolean | Same | If on, PAYE is computed on the gross settlement via the `PayeCalculator` against the regular monthly bracket table; if off, the gross is paid untaxed. |
| **Calculate Settlement** (cobalt CTA) | — | Same | POSTs to `/offboarding/{case}/settlement/calculate`. On success a `FinalSettlement` row is written with the status *calculated*, the case status moves to *awaiting_settlement*, and every input (basic salary, years of service, accrued leave days, multipliers used) is snapshotted into the row. Re-running on an unapproved case soft-deletes the prior snapshot and writes a new one — re-calculation is first-class. |
| **Approve Settlement** (green CTA, `(2FA)`) | — | `offboarding.approve` (finance_officer, super_admin, ceo) | POSTs to `/offboarding/{case}/settlement/approve`. Refuses if the settlement isn't in status *calculated*. **Hard dual-control gate:** `OffboardingService::approveSettlement()` throws if `calculated_by === approved_by`. On success the settlement row freezes (`approved_by`, `approved_at`), any open loan accounts are netted to zero and their remaining scheduled instalments waived, and the `SettlementApproved` event fires. The route is gated by `2fa:fresh`. |
| **Complete Case & Terminate Employee** (cobalt CTA, `(2FA)`) | — | `offboarding.manage` | Only visible once the settlement is approved. Behaviour as above. |

> *Notes:* The "Earnings" card is rendered straight from the `FinalSettlementResource::earnings` array — gratuity, severance, leave encashment, prorated 13th, ex-gratia, and a heavy "Gross Settlement" row. The "Deductions" card renders `deductions.outstanding_loans` (computed live from open `LoanAccount` rows at calculation time), garnishments, other, PAYE on settlement, and a highlighted "Net Payable" in the brand cobalt. The full computation breakdown (inputs, multipliers, narrative string) is stored in the `breakdown` JSON column on `final_settlements` so an auditor pulling the row in five years' time can reconstruct exactly how the figure was arrived at — including which Act 651 multipliers were in force.

### Audit tab

| Item | What it shows | Who can see it |
|---|---|---|
| **Vertical timeline** | Four nodes — Case Initiated (`initiated_by`, notice date, exit type), Settlement Calculated (net payable, calculated_at), Settlement Approved (approved_at), Case Completed (terminated date). Nodes that haven't happened yet are omitted; the vertical line stops at the last completed step. | Anyone with view |
| **Reference card** | Case reference (`OFF-YYYY-NNNNN`), initiator name, exit type. | Same |
| **Effective dates card** | Rehire-eligible flag (yes/no), last working day, effective termination date. | Same |
| **Reason / Context** | Free text typed by the initiator. | Same |
| **Exit Interview Summary** | Free text — populated through the HR Records clearance area's exit-interview item (today it lives in the case's `exit_interview_summary` field; no dedicated UI panel for typing it in the MVP). | Same |

## The data behind it

CIHRMS stores three tables and four enum types to run off-boarding:

- **`offboarding_cases`** — one row per departing employee. Fields: `reference` (unique, `OFF-YYYY-NNNNN`, minted via `SequenceService::next("offboarding:{year}")`), `employee_id`, `initiated_by`, `exit_type` (cast to `ExitType` enum), `status` (cast to `OffboardingStatus` enum), `notice_received_on`, `last_working_day`, `effective_termination_date`, `rehire_eligible` (default `true`), `reason` (text), `exit_interview_summary` (text), `completed_at`, `completed_by`. Soft-deleted; uniqueness on reference is enforced at the DB.
- **`clearance_items`** — many rows per case (the seeded default template creates 12). Fields: `offboarding_case_id`, `area` (cast to `ClearanceArea` enum — nine values), `label` (free text — the human-readable description), `status` (cast to `ClearanceItemStatus` — `pending`, `cleared`, or `waived`), `responsible_department_id`, `responsible_user_id`, `is_required` (default `true`), `cleared_by`, `cleared_at`, `notes`, `evidence_paths` (JSON array of attachment paths). Indexed on `(offboarding_case_id, status)` and `(area, status)`.
- **`final_settlements`** — one row per case (soft-deleted; the service replaces any unapproved prior snapshot when recalculating). Fields: `offboarding_case_id`, `status` (cast to `SettlementStatus` — `calculated`, `approved`, `paid`, `cancelled`), snapshot inputs (`basic_salary`, `years_of_service`, `accrued_leave_days`, `working_days_per_month`), earnings (`gratuity`, `severance`, `leave_encashment`, `prorated_13th_month`, `ex_gratia`, `gross_settlement`), deductions (`outstanding_loans`, `garnishments`, `other_deductions`, `total_deductions`), `paye_on_settlement`, `net_payable`, workflow columns (`calculated_by`, `calculated_at`, `approved_by`, `approved_at`, `payroll_line_id`, `paid_at`), the `breakdown` JSON blob, and freeform `notes`.
- **Enums** — `ExitType` (Resignation, Retirement, EndOfContract, Dismissal, Redundancy, MutualSeparation, Death, Abscondment) carries the methods `qualifiesForGratuity()` (Retirement / EndOfContract / Redundancy / MutualSeparation / Death) and `qualifiesForSeverance()` (Redundancy only — Act 651 §31). `OffboardingStatus` distinguishes terminal states (`Completed`, `Cancelled`) via `isTerminal()`. `ClearanceArea` enumerates the nine sign-off lanes. `ClearanceItemStatus` is the simple `pending` / `cleared` / `waived` triple.

The case joins out to **one** Employee (cascade-delete from the employee), **one** initiating User, **one** completing User, **many** ClearanceItems, and **one** FinalSettlement (`HasOne::latestOfMany()` — there can only be one active snapshot per case at a time). The settlement joins back to a `payroll_line_id` for the off-cycle disbursement once Finance pays it.

What every reader of the screen needs to keep in mind:

- **The case reference is not the same as a payroll voucher.** `OFF-2026-00012` is the audit identifier for the separation; the disbursement still needs a `PayrollLine` (Ch 19) with its own GIFMIS voucher (Ch 23) once Finance pays the net.
- **The settlement snapshot is immutable once approved.** Re-running `calculateSettlement` on an approved row throws `DomainException`; the only escape is to cancel the case and start over. This is intentional — the figure that was approved is the figure that gets paid.
- **Dual control is enforced in code, not just policy.** `OffboardingService::approveSettlement()` raises a `DomainException` if `calculated_by === approved_by`. The Pest test `enforces dual control on settlement approval` covers this regression.
- **Years of service is computed at calculation time** as a `floatDiffInYears` between `hire_date` and the case's `effective_termination_date` (defaults to `last_working_day`). It is rounded to two decimal places and snapshotted — so a partial year (4.50 = 4 years 6 months) becomes 4.50 months of basic times the gratuity multiplier.
- **Accrued leave days come from the current-year `leave_balances` annual row only.** Carry-over from prior years is not in scope of the MVP; if the institute lets unused leave carry forward, HR types the corrected day-count straight into the snapshot via an "other_deductions" line until Phase 2 generalises this.
- **The `effective_termination_date` defaults to `last_working_day` but is stored separately** so an institute that needs a paid notice period beyond the last day worked has a place to record the real termination date without overwriting the LWD.

## Lifecycle

The state machine is enforced by the service, not by the controller, so any future API entry-point follows the same rules:

```
draft ──[ initiate ]──▶ in_progress ──[ last required item cleared ]──▶ awaiting_settlement
                                                                              │
                                                                       [ calculateSettlement ]
                                                                              ▼
                                                              awaiting_settlement (FinalSettlement: calculated)
                                                                              │
                                                                       [ approveSettlement — dual control + 2FA ]
                                                                              ▼
                                                              awaiting_settlement (FinalSettlement: approved)
                                                                              │
                                                                       [ complete — 2FA ]
                                                                              ▼
                                                                          completed
                                                                          (Employee.status ← terminated)
```

A case can be **cancelled** from any non-terminal state — a withdrawn resignation, a re-instated employee — and the cancellation reason is appended to the case `reason` with a `[CANCELLED]` prefix. Cancelling preserves the case row for the audit trail.

The default clearance template seeded at initiation (`OffboardingService::DEFAULT_CLEARANCE_TEMPLATE`) is:

| Area | Item | Required? |
|---|---|---|
| IT & Assets | Return laptop, mobile device & SIM | yes |
| IT & Assets | Disable accounts and revoke access | yes |
| IT & Assets | Return ID & access badge | yes |
| Finance | Reconcile outstanding imprest / advances | yes |
| Finance | Settle outstanding loans (or schedule netting) | yes |
| HR Records | Return staff file documents | yes |
| HR Records | Sign exit interview | yes |
| HR Records | Reaffirm NDA / confidentiality obligations | yes |
| Stores | Return uniforms / tools / vehicles | optional |
| Security & Access | Gate pass & parking access revoked | yes |
| Departmental Handover | Departmental handover note signed by supervisor | yes |
| Pension Discharge | SSNIT discharge form filed; Tier-2 trustee notified | yes |

The pension item explicitly cues the SSNIT (Tier-1) discharge paperwork and the Tier-2 trustee notification, so the handoff to the National Pensions Regulatory Authority's two-tier scheme is captured in the same checklist that IT uses to disable accounts. There is no automatic API call to SSNIT or to a Tier-2 trustee in the MVP — the item is a clerical sign-off that the form has been filed.

## How it talks to other modules

- **`OffboardingInitiated` event** → fired at the end of every successful `initiate`. Nothing listens to it today; the event exists so Phase 1 can hang a "freeze access" listener off it without touching the service. (Roadmap, below.)
- **`OffboardingCompleted` event** → fired when a case completes. `FanOutWebhooks::handleOffboardingCompleted` picks it up and dispatches a `offboarding.completed` webhook payload to every active subscriber (Ch 24). The auto-discovery scanner finds the listener via its typed `handle()` parameter, so no explicit `Event::listen` registration is needed.
- **`SettlementApproved` event** → fired when the dual-control approval lands. The service uses this to mark the loan write-off (the loans are paid out of the settlement and any remaining scheduled instalments are voided). Webhook subscribers can also listen to it.
- **Employees (Ch 3)** — `complete()` sets `employees.status = terminated`. The status field on the directory and detail screens immediately reflects this. The Employee soft-delete is **not** triggered — the row stays in the table and the audit trail intact.
- **Leave (Ch 4)** — `OffboardingService::accruedLeaveDays()` queries the current-year `leave_balances` annual row(s) and returns `max(0, total_days − used_days)`. Future-dated approved leave is not subtracted in the MVP — that nuance is in the Phase 1 backlog.
- **Loans (Ch 21)** — `outstandingLoanBalance()` sums `LoanAccount.outstanding_balance` for accounts in `Disbursed` or `Repaying` status, and `closeOutstandingLoans()` (called from `approveSettlement`) row-locks the loan accounts, marks every scheduled `LoanRepayment` as `Waived`, sets the loan to `PaidOff` with a zero balance and `actual_end_date = today`. The loan is considered netted against the settlement at this point.
- **Payroll (Ch 19)** — `FinalSettlement::payroll_line_id` is a nullable FK to `payroll_lines`. The off-cycle disbursement is not auto-created in the MVP; Finance creates a one-line payroll run for the leaver and stitches it back to the settlement row when paying out. Phase 2 will auto-mint that line on approval.
- **Identity (Ch 25)** — the high-value gates (`approveSettlement` and `complete`) require a **fresh** 2FA stamp via the `2fa:fresh` route middleware. A stale session that hasn't stepped up in the configured window cannot terminate an employee or release a settlement.
- **Assets (the Assets module exists at `/assets` but is not yet in this dossier's chapter list — flag for inclusion).** Assets are touched only by the clearance checklist today — the IT & Assets area carries the laptop / SIM / badge items, and HR ticks them off after the IT team confirms physical return. The Assets module has its own asset-assignment ledger which today is **not** automatically scanned for unreturned items on case initiation — Phase 1 will add a pre-flight step that pulls every open `AssetAssignment` for the employee and seeds a clearance item per asset.
- **DPA (Ch 26)** — the case row carries the legal anchor for processing personal data after departure (the Data Protection Act, 2012 §40 retention window). Soft delete on the case preserves the audit trail; the dedicated redaction job (Phase 2) is what handles statutory erasure of the employee's underlying personal fields after the retention window.

## Standards touchpoints

- **Ghana Labour Act, 2003 (Act 651) §17 — termination of contract of employment.** The `notice_received_on` and `last_working_day` fields are the statutory dates §17 expects to see on the record; the `reason` text captures the §18 written reason for termination. The Initiate panel's subtitle calls out the Act explicitly so HR is reminded what the legal anchor is. See Chapter 44.
- **Ghana Labour Act §18 — notice required for termination.** The FormRequest enforces `last_working_day >= notice_received_on` so a backdated last-day is impossible. The actual notice-period rules (one month for monthly-paid, two weeks for weekly-paid) are NOT computed in the MVP — HR types the agreed last day; Phase 1 will validate it against the contract type.
- **Ghana Labour Act §31 — redundancy (severance).** `ExitType::Redundancy.qualifiesForSeverance()` returns true; every other exit type returns false. The `FinalSettlementCalculator` then pays `basic_salary × severance_months × years_of_service` only on Redundancy; the default multiplier is 1.5×.
- **National Pensions Act, 2008 (Act 766) — Tier-1 (SSNIT) and Tier-2 (occupational scheme).** The default clearance checklist seeds a "SSNIT discharge form filed; Tier-2 trustee notified" item under the Pension Discharge area. The item is a clerical sign-off; a live SSNIT / Tier-2 API call is roadmapped in Phase 1.
- **National Pensions (Amendment) Act, 2014 (Act 883) — Tier-3 portability.** Out of scope of the MVP; the case carries no Tier-3 fields today.
- **Public Services Commission Code of Conduct & Conditions of Service for the Public Services — clearance & gratuity rules.** The default checklist mirrors the PSC's standard exit pack (IT, finance, library, stores, security, departmental handover). The exact gratuity multipliers in the PSC scheme can be expressed via the per-case overrides on the calculator.
- **Data Protection Act, 2012 (Act 843) §40 — data retention after departure.** Cases and settlements are soft-deleted, never hard-deleted, so the statutory retention window is observed by default. Right-to-erasure on the underlying employee data is handled by a dedicated redaction job (out of scope of this chapter — see Chapter 26).

## Honest gaps

The chapter would be dishonest if it didn't name these:

- **Account deprovisioning is a clerical tick, not an automated kill switch.** "Disable accounts and revoke access" is a checklist item in the IT & Assets area; the system **does not** automatically force-logout, revoke API tokens, disable SSO, or mark the User row inactive when the case completes. The `Employee.status` flips to *terminated* but the underlying `User.is_active` flag is not touched. **Phase 1 must add** a listener on `OffboardingCompleted` that disables the user account, revokes Sanctum tokens, and forces an SSO logout.
- **No document generation.** The MVP does not produce an exit letter, a clearance certificate PDF, or an end-of-service receipt. The `evidence_paths` JSON on each clearance item is the placeholder for attached PDFs / scans, but the generation side (templating, signing, sealing with the Documents module's stamps from Ch 8) is not wired. Phase 1 deliverable.
- **No automatic SSNIT / NPRA handoff.** The pension clearance item is a checklist tick; there is no API call to SSNIT, no Tier-2 trustee notification email, no SSNIT discharge form rendered. Filing the form is a manual HR task.
- **No exit-interview UI.** The case has an `exit_interview_summary` column and the clearance template has a "Sign exit interview" item, but the actual interview is captured offline; HR types the summary into the case row by other means (today: through the database or a future panel).
- **Assets are not pre-flighted.** The clearance checklist names "Return laptop, mobile device & SIM" generically — it does **not** enumerate the specific assets the employee currently has assigned in the Assets module. A Phase 1 deliverable is to seed one clearance item per open `AssetAssignment` so the IT clearer can tick each piece of hardware individually.
- **No automatic off-cycle payroll line.** When the settlement is approved, no `PayrollLine` is created. Finance has to manually open a one-line payroll run for the leaver and link it back to the settlement via `payroll_line_id`. Phase 2 will auto-mint the line on approval.
- **Re-employment eligibility is captured as a boolean, not enforced.** `rehire_eligible` defaults to true and is editable; nothing in the recruitment module checks this flag when a former employee re-applies. Phase 1 will gate recruitment-shortlist visibility on it.
- **Notice-period validation is dumb.** The FormRequest only enforces `last_working_day >= notice_received_on`; it does not verify the gap matches the statutory minimum for the employee's pay frequency (1 month for monthly-paid; 2 weeks for weekly-paid). Phase 1 backlog.

## What's planned next

Phase 1 of the government-grade roadmap (8–10 weeks) lands seven things on this module: (1) an `OffboardingCompleted` listener that disables the User account, revokes tokens, and pushes an SSO logout; (2) auto-seeded clearance items for every open Asset assignment, plus an "Asset returned" action on each item that flips the Asset's status back to *Available* in one click; (3) a real exit-letter PDF and a clearance-certificate PDF generated from the Documents module's templated stamps; (4) a Phase 1 SSNIT and Tier-2 trustee notification flow — an email + a PDF discharge form pre-filled with the leaver's SSNIT number and the snapshot's pension-relevant fields; (5) auto-minting of the off-cycle payroll line on settlement approval so Finance no longer has to stitch it manually; (6) notice-period validation that knows the employee's pay frequency; (7) a "rehire-eligible" gate in the recruitment shortlist UI. Phase 2 picks up the harder bits — full Tier-2 API integration, automatic SSNIT discharge submission, and the dedicated DPA-grade redaction job that handles right-to-erasure requests after the statutory retention window closes (Chapter 26).
