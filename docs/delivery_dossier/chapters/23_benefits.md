# Chapter 23 — Benefits

> *In one paragraph.* Benefits is CIHRMS's welfare desk. HR curates a catalogue of plans — health insurance, life assurance, dental, vision, wellness programmes, transport / fuel allowances modelled as "other", and the long-term provident-fund (Tier-3-style) accrual — and employees enrol against the active ones. Each enrolment carries its own monthly premium (defaulted from the plan's contribution percentage, overridable per offer letter), tracks who is covered, lets the employee register family dependants up to the plan's cap, lets them download a printable e-card for the provider's front desk, and lets them submit reimbursement claims against any active enrolment. Claims travel a four-state lifecycle — submitted → reviewing → approved → paid (or rejected at any decision point) — with HR/Finance deciding under the same `benefits.manage` gate. Behind the scenes, provident-fund enrolments are tallied month-by-month into a lifetime-contributed figure that surfaces on the employee's own benefits page. **Honest caveats up front:** in the MVP the monthly premium does not yet flow into the payroll engine as a Deduction (the wiring exists in shape, not in code), offboarding does not auto-end open enrolments, and the "open enrolment window" referenced below is policy, not enforced by the codebase. Phase 1 closes all three gaps.

## Where to find it

- **Sidebar location:** **Workforce** group → **Benefits** (top-level item, `card_giftcard` icon). Every role with `benefits.view` lands on `/benefits`, which renders an employee's own coverage by default; HR and CEO see the same page but can also reach `/benefits/plans` (catalogue admin) and `/benefits/claims` (org-wide approval queue) via cobalt sub-links once the right permission is held.
- **Roles that see it:**
    - **super_admin** and **ceo** — every plan, every enrolment, every dependant, every claim, every decision. (The policy's `before()` hook short-circuits on super_admin.)
    - **hr_admin** — full catalogue management (create/edit/archive plans), full claims queue, can decide claims, can also enrol and claim on their own behalf. Holds `benefits.manage` + the self-service trio (`view`, `enrol`, `claim`).
    - **finance_officer** — sees the queue read-only and pays approved claims (the "Mark Paid" transition); also holds the self-service trio so they can register their own dependants and claims.
    - **auditor** — read-only across every claim, every plan, every enrolment — same posture as the wider audit role in Chapter 24.
    - **manager / dept_head / it_support** — only the self-service trio (`benefits.view`, `benefits.enrol`, `benefits.claim`). They never see other employees' enrolments unless they also carry `benefits.view_all` (not granted by default).
    - **employee** — the same self-service trio: their own enrolments, their own dependants, their own claims. Cannot see the plans-admin or claims-queue pages.
- **Related modules:** Employees (Ch 3) — the Add-Employee slide panel multi-selects benefit plans, and the resulting enrolments hang off the employee row; Off-boarding (Ch 10) — termination should end open enrolments (currently a manual step, see honest gaps); Payroll Engine (Ch 19) — voluntary deductions exist for this kind of premium but the auto-bridge from `monthly_premium` to a `Deduction` row is not yet wired; Finance Hub & F1–F5 (Ch 20) — premium expense and claim-payment journals are roadmapped, not coded; Disbursements (Ch 22) — claim "Mark Paid" today is a status flip only; routing to GhIPSS / MoMo via the disbursements engine is Phase 1; DPA & Privacy (Ch 26) — claim descriptions and medical-plan enrolments are sensitive personal data and earn the §39 health-data treatment.

## The screens

![My Benefits — coverage snapshot, KPIs, plan composition, provident fund, enrolments, dependants, claims](../assets/screenshots/23_benefits/index.png)

*Callouts: ❶ Coverage-snapshot hero — cobalt-to-indigo gradient banner with three pulled-out KPIs (active enrolments count, monthly total in GHS, lifetime provident contributed). One-line subhead reads "{N} active enrolment(s) · GHS X/mo · Y dependants registered · Z claims pending · GHS W claimed lifetime". · ❷ Four KPI tiles below — Active enrolments, Dependants (with covered count), Monthly premium, Claims pending — each with a coloured icon well (cobalt, cyan, gold, magenta). · ❸ Two-pane band — "Plan composition" stacked bars by benefit type (Health, Provident, Life, Dental, Vision, Wellness, Other) and "Claims pipeline" status breakdown (Submitted / Reviewing / Approved / Paid / Rejected) with percentages. · ❹ Provident-fund cards (gold accent, only renders if the employee has any `provident_fund`-type enrolment) — each card shows the plan name, lifetime contributed in GHS, months active, and monthly rate. · ❺ "My enrolments" card grid with a status pill filter chip row (All / Active / Suspended / Terminated). Each card shows the plan icon + type chip, name, code, effective interval ("dd MMM yyyy → ongoing"), monthly premium, and two outlined buttons: "E-card" (downloads a PDF) and "Submit claim" (opens the claim slide panel). · ❻ Dependants table — name, relationship, DOB, masked national ID, "Covered" pill. · ❼ Claims table — reference (mono), plan, amount in GHS, submission date, colour-coded status pill.*

![Enrol in plan — slide panel](../assets/screenshots/23_benefits/enrol.png)

*Callouts: ❶ Cyan information banner — "Premium amounts default to the plan's standard rate. Override only if your offer letter specifies a different contribution." · ❷ Three fields — Plan (dropdown of active plans, label "{name} ({type})"), Effective from (date, defaults to today), Premium override (optional number, blank = use the plan's computed default). · ❸ Cobalt "Enrol" button — POST to `/benefits/enrol`. The `BenefitsService::enrol` method computes the premium server-side from `monthly_cost × employee_contribution_percentage / 100` when the override is blank.*

![Add dependant — slide panel](../assets/screenshots/23_benefits/dependant.png)

*Callouts: ❶ Full name (required, max 120). · ❷ Relationship (spouse / child / parent / other) and Gender (male / female / other) side by side. · ❸ Date of birth (required, must be in the past — `before:today` in the FormRequest). · ❹ National ID (optional, max 32) — Ghana Card PIN for the dependant when available. · ❺ "Add dependant" submits to `/benefits/dependants` — the service checks `existingDependants ≥ max_dependants` across the employee's active plans and rejects with a `DomainException` ("Dependant cap of N reached for active plans.") if the cap is reached.*

![Submit claim — slide panel](../assets/screenshots/23_benefits/claim.png)

*Callouts: ❶ Amber information banner — "Provide a detailed description (minimum 10 characters). Claims are typically reviewed within 5 business days." (The 5-day SLA is an operational promise, not a coded timer.) · ❷ Amount (GHS, required, ≥ 0.01) and Claim date (required, must be on or before today) side by side. · ❸ Description (required, 10–1,000 chars) — free-text justification with provider name, date of service, etc. · ❹ "Submit claim" assigns a server-generated `CLM-XXXXXXXX` reference and stamps `submitted_at`.*

![Benefit Plans — HR catalogue admin](../assets/screenshots/23_benefits/plans.png)

*Callouts: ❶ "Benefits catalogue · HR administration" eyebrow, "Benefit Plans" headline, "+ New Plan" cobalt CTA. · ❷ Table — Code (mono), Name (with effective interval underneath), Type, Provider, Monthly cost in GHS, Employee cover %, Max dependants, Status pill (ACTIVE / inactive). · ❸ Per-row Edit and Delete icons — Delete is a soft-archive in the MVP (the model uses `SoftDeletes`); a future foreign-key check will block deletion while any active enrolment references the plan. · ❹ Slide panel (Create / Edit) — code, name, type, provider, monthly cost, employee cover %, effective from, effective to, max dependants, is-active toggle, description.*

![Claims Queue — HR/Finance approval pane](../assets/screenshots/23_benefits/claims_queue.png)

*Callouts: ❶ "Benefits administration · Approval queue" eyebrow, "Claims Queue" headline, one-line lead text "Review and decide submitted benefit claims org-wide — each decision is recorded in the audit chain." · ❷ Paginated table (20 per page) — reference, employee (name + employee_no), plan, amount, submitted date, colour-coded status pill. · ❸ Per-row action cluster: when status is `submitted` or `reviewing` → "Approve" (emerald) and "Reject" (rose); when status is `approved` → "Mark Paid" (sky). The transitions are enforced server-side by `BenefitsService::guardTransition` — clicking the wrong button throws `DomainException("Illegal transition: X → Y")` and flashes an error. · ❹ Decision modal — notes textarea (required when the decision is "rejected"), Confirm button. The notes land on `decision_notes` and the deciding user lands on `decided_by`.*

> The six screenshot files referenced above will be captured in Wave 1 (task W1.23). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### My Benefits page (employee self-service — `/benefits`)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Enrol in Plan** (cobalt CTA, top right) | Opens the right-hand slide panel for a new enrolment (plan, effective-from, optional premium override). | `benefits.enrol` | Self-service is the first principle of this module — employees pick their own plans rather than wait for HR to add them. |
| **Coverage snapshot hero** | Read-only — active enrolment count, monthly total, dependants count, claims pending, lifetime claimed. | Anyone viewing the page | The numbers stakeholders ask about most often, surfaced before any scroll. |
| **KPI tiles** (Active / Dependants / Monthly / Claims pending) | Read-only. Active enrolments and dependants compare against lifetime / covered counts in the sub-label; Monthly is the sum of `monthly_premium` across active enrolments; Claims pending counts `submitted + reviewing` statuses. | Same | The same four numbers a Finance-side audit asks for, restated for the individual. |
| **Plan composition bars** | Stacked-bar breakdown of the employee's enrolments by `BenefitType` (health, provident, life, dental, vision, wellness, other). Each bar is normalised to the largest single-type bucket so the smallest type stays visible. | Same | A glance shows the "shape" of the employee's coverage — heavy on health, no life cover, etc. |
| **Claims pipeline bars** | Distribution across the five claim states (submitted, reviewing, approved, paid, rejected). | Same | Surfaces "you have N claims still waiting" without scrolling to the claims table. |
| **Provident-fund cards** | Per-enrolment card for any `provident_fund`-type plan: lifetime contributed (computed as `monthly_premium × months_since_effective_from`), months active, monthly rate. Only renders if the employee has at least one provident-fund enrolment. | Same | The single saving figure most employees care most about, surfaced as its own band rather than buried in the enrolment list. |
| **Filter pill bar** (All / Active / Suspended / Terminated) | Filters the enrolment-card grid below by status. Pure client-side filter — no server round-trip. | Same | Hiding terminated plans is the most-asked view; one click does it. |
| **E-card** (per active enrolment) | GET `/benefits/enrolments/{enrolment}/e-card` — renders a PDF (dompdf) of the employee's coverage proof: plan name + code, employee name + number, effective interval, monthly premium, dependants list. Downloads as `ecard-{id}.pdf`. | Owner of the enrolment, or `benefits.view_all` | Provider front-desks (clinics, opticians, dentists) ask for proof of cover — a printable e-card is the lowest-friction answer. |
| **Submit claim** (per active enrolment) | Opens the claim slide panel pre-bound to that enrolment. | Owner of the enrolment + `benefits.claim` (RBAC double-check: `BenefitsPolicy::submitClaim` re-verifies both) | Filing a claim against the right plan is the most-clicked action; pinning it to the enrolment card removes the "which plan was this for?" step. |
| **Add dependant** (in the Dependants section) | Opens the dependant slide panel. | `benefits.enrol` | Adding family is a self-service step, not an HR ticket. |
| **Dependants table** | Read-only list of full name, relationship, DOB, masked national ID, covered pill. | Same as the enrolment view | The accompanying-family slice of the same page; deliberately quiet so the enrolment cards stay the headline. |
| **Claims table** | Read-only list of the employee's most recent 50 claims (reference, plan, amount + currency, submitted date, status pill). | Same | The "what's the status of my reimbursement?" answer, surfaced on the same page as the action that creates one. |

> *Notes:* The whole page hydrates from a single Inertia render — `BenefitsController::index` returns `enrolments` (with plan), `dependants`, `claims` (latest 50, with enrolment + plan + decider), `plans` (active plans only, for the enrol dropdown), and `provident` (the `providentFundView` projection). No N+1 inside the tabs. The 50-claim ceiling exists so a heavy claims user doesn't pay a payload tax on every render — once Phase 1 adds claim pagination this becomes a query param.

### Plans admin (`/benefits/plans`)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **+ New Plan** (cobalt CTA, top right) | Opens the create slide panel. | `benefits.manage` | HR curates the catalogue; this is the entry point. |
| **Plans table** | One row per plan — code (mono), name + effective interval, type, provider, monthly cost in GHS, employee cover %, max dependants, status. | Same | One screen, every plan; the most-edited columns are surfaced front and centre. |
| **Edit** (pencil icon per row) | Opens the same slide panel pre-filled with the plan's values. PATCH on submit; only the changed fields are sent because the FormRequest uses `sometimes`-style validation per field. | Same | A rate change on an existing plan does not require recreating it. |
| **Delete** (trash icon per row) | Browser confirm dialog → DELETE `/benefits/plans/{plan}`. Soft-deletes the row (the model uses `SoftDeletes`). | Same | Soft delete keeps the audit trail for any historical enrolments that referenced the plan. **Note:** the MVP does *not* yet block deletion when active enrolments reference the plan — the UI confirm warns about it ("Active enrolments will block deletion — close them first if so.") but the service does not throw. Phase 1 wires the FK check. |
| **Slide panel form** | Code (unique, mono, max 40), Name (max 120), Type (one of seven `BenefitType` cases), Provider (max 120, optional), Monthly cost (decimal, ≥ 0), Employee cover % (0–100), Effective from (required), Effective to (optional, must be ≥ effective_from), Max dependants (0–50), Active toggle, Description (textarea). | Same | The whole plan schema in one panel — there's no separate "advanced settings" page in the MVP. |

> *Notes:* `BenefitPlan` has model-level attribute defaults (`is_active = true`, `employee_contribution_percentage = 0`, `max_dependants = 0`) so a freshly-created in-memory instance returns the same values the migration writes on insert. Without these defaults, `BenefitsService::enrol()` saw `is_active = null` on the model immediately after `create()` and threw "Plan X is not active" until the row was reloaded — a real bug fixed in the model layer rather than the service. The `cover_details` field is a JSON column intended for plan-specific extras (provider hotline numbers, in-network hospital lists, claim-form URLs) but is not yet surfaced in any UI — it's wired through the resource layer (`BenefitPlanResource::cover_details`) so a future plan-detail page reads it without a migration.

### Claims queue (`/benefits/claims`)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Claims table** | Paginated (20 per page) list of every claim org-wide, latest submitted first. Reference, employee (name + number), plan, amount, submitted date, status pill. | `benefits.manage` | One queue, one click per decision. The HR / Finance / CEO view, not the employee's. |
| **Approve** (emerald button, when status is `submitted` / `reviewing`) | Opens the decision modal pre-set to "approved". On confirm, PATCH `/benefits/claims/{claim}/decide` with `{ status: 'approved', notes }`. Server transitions via `BenefitsService::guardTransition`. | `benefits.manage` | The most-clicked decision — earns its own coloured button rather than a dropdown. |
| **Reject** (rose button, same states) | Opens the modal pre-set to "rejected". Notes are required (the FormRequest enforces `required_if:status,rejected` and the UI marks the textarea as required). | Same | Rejection is part of the same decision; symmetric placement. |
| **Mark Paid** (sky button, only when status is `approved`) | Transitions `approved → paid`. | Same | Approval and payment are deliberately separate states — the money may move a day or two after the decision, and the audit chain wants to see both. |
| **Decision modal — notes textarea** | Optional for approve / paid; required for reject (≥ 1 char, max 1,000 per `DecideClaimRequest`). Lands on `decision_notes` on the claim row. | Same | The "why" goes on the record; especially on rejection, the employee will ask. |
| **Decision modal — Confirm** | Submits the PATCH. On success, the modal closes and the table refreshes via `preserveScroll`. | Same | Standard. |
| **Pagination** (bottom of table) | Standard Inertia pagination with `withQueryString()`. | Same | Even a small institute accumulates hundreds of claims a year; pagination keeps the queue responsive. |

> *Notes:* The four-state lifecycle is policed by `BenefitsService::guardTransition`, which keeps an explicit allow-list: `submitted → {reviewing, approved, rejected}`, `reviewing → {approved, rejected}`, `approved → {paid}`, `rejected → {}`, `paid → {}`. Any other transition throws `DomainException("Illegal transition: X → Y")` and the controller surfaces it as a flash error — you cannot click "Mark Paid" on a submitted claim, even if the UI somehow exposed the button. The `decided_by` foreign key uses `nullOnDelete` so if the deciding user is later removed the audit trail still resolves (`Decision was made by [deleted user]`) without orphaning the claim row.

### Enrol slide panel

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Plan** | required, integer, must exist on `benefit_plans`. The UI's dropdown only lists `is_active = true` plans, so an inactive plan never appears as an option. | `benefits.enrol` | The service ALSO checks `is_active` on save and throws `DomainException("Plan X is not active.")` if the plan was deactivated between the page load and the submit. |
| **Effective from** | required, valid date | Same | Defaults to today client-side. Backdated enrolments are accepted (legacy data imports); a future open-enrolment-window check is roadmapped (see honest gaps). |
| **Premium override** | optional, numeric, ≥ 0 | Same | Blank = use the plan's computed default (`monthly_cost × employee_contribution_percentage / 100`). The override is the offer-letter-specific case (a CEO joining mid-grade, a hardship-allowance carve-out) — the cyan banner in the panel says exactly that. |

> *Notes:* `BenefitsService::enrol` is **not** wrapped in a transaction in the MVP because it does a single insert; the `BenefitEnroled` event fires unconditionally on success. The unique-index on `(plan_id, employee_id, effective_from)` (`benefit_enrolments_unique`) prevents two enrolments in the same plan on the same date for the same employee — a re-enrolment on a later date is permitted. Effective-to is set to `null` (open-ended) on create; a future unenroll flow will stamp it. The `enrolled_at` column captures the calendar date the enrolment was actually recorded — separate from `effective_from`, which is when cover begins — so an employee who picks up cover on 1 Jun but registered it on 15 May reads both correctly on the e-card.

### Dependant slide panel

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Full name** | required, max 120 | `benefits.enrol` (RBAC double-check: only the employee themself, or someone with `benefits.view_all`) | Free-text — no auto-uppercase, no canonical-form requirement. |
| **Relationship** | required, one of `spouse / child / parent / other` (the `DependantRelationship` enum) | Same | Four options is deliberate — extended-family edge cases roll into "other" rather than ballooning the enum. |
| **Date of birth** | required, valid date, must be `before:today` | Same | Future DOBs are rejected; the FormRequest does not currently enforce "child must be under 18 / 21 / 25" — that's a plan-level rule still on the backlog. |
| **National ID** | optional, max 32 | Same | Ghana Card PIN for the dependant when available — used by provider hospitals for own-record matching. Storage is plaintext (the field is just a string) — the Ghana-Card masking on the employee page is a frontend convenience, not a DB-level encryption layer. |
| **Gender** | optional, one of `male / female / other` | Same | Optional in the MVP to honour the "progressive capture" principle from Ch 3 — the employee can fill in later. |
| **Is covered** | optional boolean, defaults to `true` | Same | Per-dependant opt-in/out — useful when one plan covers spouse only and another covers child only; toggling this row leaves the dependant on file but excludes them from the plan-side roster. |

> *Notes:* The dependant cap is the only enrolment-level cross-check in the service. `BenefitsService::addDependant` computes `maxAcrossPlans = max(plan.max_dependants for each active enrolment)` and refuses if `existingDependants ≥ maxAcrossPlans` — that's "use the most generous plan's cap" semantics, not "the strictest plan limits everyone". If the employee has no active enrolments at all, the cap defaults to 0 and dependants cannot be added until at least one enrolment exists; that's the trade-off, and the controller surfaces the DomainException as a flash error.

### Claim slide panel

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Enrolment** | required, integer, must exist on `benefit_enrolments`; the policy `submitClaim` additionally checks `enrolment.employee_id === user.employee.id` (or `benefits.view_all`) | `benefits.claim` | Bound implicitly when the employee clicks "Submit claim" on an enrolment card; never visible as a free-form field. |
| **Amount** | required, numeric, ≥ 0.01 | Same | Currency defaults to GHS via the column default; the FormRequest accepts an optional 3-char ISO code so cross-border edge cases are not blocked. |
| **Claim date** | required, valid date, must be `before_or_equal:today` | Same | Backdated claims (a clinic visit from last week being filed now) are allowed; future dates are not. |
| **Description** | required, string, 10–1,000 chars | Same | The 10-char floor stops "drugs" / "fee" entries; the 1,000-char ceiling stops paste-bomb attacks. The amber banner in the panel says "Provide a detailed description (minimum 10 characters)". |

> *Notes:* The claim reference is generated server-side as `CLM-` + 8 random uppercase chars by `BenefitsService::submitClaim` (using Laravel's `Str::random(8)`). It is therefore not strictly monotonic — a fresh claim with reference `CLM-A1B2C3D4` may have been submitted before `CLM-Z9Y8X7W6` if both were created on the same day. The DB-level uniqueness constraint (`claim_reference`) handles collisions probabilistically — the chance of a clash is roughly 1 in 200 trillion at any reasonable volume. Phase 1 swaps this for the same `SequenceService` pattern Finance uses for invoice references (`CLM-YYYY-NNNNNN`) so the audit pack sorts chronologically without a join.

## The data behind it

CIHRMS stores benefits across **four** tables, all migrated together in `2026_05_29_000001_create_benefits_tables.php` and all using `SoftDeletes` so historical rows survive any deletion:

- **`benefit_plans`** — one row per plan that anyone may enrol in. Columns: `name` (max 120), `code` (unique, max 40), `type` (`BenefitType` enum — `health_insurance`, `provident_fund`, `life_insurance`, `dental`, `vision`, `wellness`, `other`), `provider` (nullable, max 120 — e.g. "Nationwide Medical Insurance Co.", "Star Life Assurance"), `description` (nullable text), `monthly_cost` (decimal 10/2 — the plan's headline monthly price, before any percentage split), `employee_contribution_percentage` (decimal 5/2, 0–100 — how much of the monthly cost the employee shoulders; the implicit complement is the employer subsidy), `is_active` (boolean, default true), `effective_from` / `effective_to` (start and optional end of the plan's life — newly-enrolled employees on a plan whose `effective_to` has passed should not be allowed; in the MVP this is policed by the plan dropdown filter, not by the service), `max_dependants` (unsigned smallint, default 0 — used by the cap-check in `addDependant`), and `cover_details` (nullable JSON — schema-less plan-specific extras, currently unused in any UI). Indexed on `type` and `is_active` for the plan-picker dropdown.
- **`benefit_enrolments`** — one row per (employee, plan, effective_from). Columns: `plan_id` and `employee_id` (both FK-cascade), `enrolled_at` (the calendar date the row was created), `effective_from` (when cover begins), `effective_to` (nullable, when cover ends — null = open-ended), `status` (`BenefitEnrolmentStatus` enum — `active`, `suspended`, `terminated`), `monthly_premium` (decimal 10/2 — the per-employee amount, either the plan's computed default or the override), and `notes` (free-text annotations). Unique index on `(plan_id, employee_id, effective_from)` (`benefit_enrolments_unique`); indexed on `employee_id` and `status` for the per-employee lookup and the claims-queue join.
- **`dependants`** — one row per registered family member of an employee (independent of plan — the dependant is on the *employee*, not the enrolment, and the per-plan cap is enforced at add-time). Columns: `employee_id` (FK-cascade), `full_name` (max 120), `relationship` (`DependantRelationship` enum — `spouse`, `child`, `parent`, `other`), `date_of_birth` (required, past), `national_id` (nullable, max 32 — Ghana Card PIN when available), `gender` (nullable, max 16), `is_covered` (boolean, default true). Indexed on `employee_id`.
- **`benefit_claims`** — one row per reimbursement claim against an enrolment. Columns: `enrolment_id` (FK-cascade), `claim_reference` (unique, max 20 — `CLM-XXXXXXXX`), `amount` (decimal 12/2 — note the larger precision than `monthly_cost`, anticipating large hospital bills), `currency` (char 3, default `GHS`), `claim_date` (required), `description` (required text, 10–1,000 chars), `status` (`ClaimStatus` enum — `submitted`, `reviewing`, `approved`, `rejected`, `paid`), `submitted_at` (timestamp, required), `decision_at` / `decision_notes` / `decided_by` (the decision triplet — `decided_by` is FK-nullable on `users.id` with `nullOnDelete`, preserving the audit trail past user removal). Indexed on `enrolment_id` and `status` for the queue ordering and the per-employee reverse-lookup.

The relationship graph is intentionally narrow: `BenefitPlan → hasMany → BenefitEnrolment`, `BenefitEnrolment → hasMany → BenefitClaim`, `Employee → hasMany → BenefitEnrolment`, `Employee → hasMany → Dependant`. There is no "BenefitClaim ↔ Dependant" link in the schema — claims today are filed against the *enrolment*, not against a specific dependant, on the principle that the employee submitting the claim is accountable for the expense regardless of which family member it covered. A future plan that prices dependant-specific claims differently will need that join table; the MVP does not.

### Plan catalogue — what gets seeded vs. what HR creates

The MVP does **not** ship a benefit-plans seeder — every plan an institute uses is created by HR through the `/benefits/plans` admin page. The seven `BenefitType` cases are the catalogue's vocabulary; the recommended starter catalogue documented for new-tenant onboarding (in Ch 33's runbook) is:

| Plan type | Suggested plan name | Typical provider | Suggested monthly cost (GHS) | Employee % | Max dependants | Notes |
|---|---|---|---|---|---|---|
| `health_insurance` | "CIHR Premium Health" | a private health insurer | 350 | 25% | 4 | Top-up over NHIA. Spouse + up to 3 children covered. |
| `health_insurance` | "CIHR Standard Health" | same insurer | 180 | 30% | 4 | Lower premium, narrower in-network list. |
| `life_insurance` | "Group Life Assurance" | a registered life insurer | 80 | 0% | 0 | Employer-paid; lump-sum payout on death-in-service. |
| `provident_fund` | "Tier-3 Provident Fund" | NPRA-licensed trustee | varies | 100% | 0 | Voluntary long-term savings (alongside Tier-1/Tier-2 in payroll). The `provident_fund` type is what surfaces in the lifetime-contributed cards on the employee page. |
| `dental` | "Dental Care Top-up" | private dental insurer | 60 | 50% | 4 | NHIA does not cover most dental work. |
| `vision` | "Vision & Optical" | private optical insurer | 40 | 50% | 4 | Frames and lenses; cataract surgery if in-network. |
| `wellness` | "Wellness & Gym" | corporate gym partner | 150 | 50% | 0 | Subsidised gym membership. The "transport allowance" and "fuel allowance" common to many Ghanaian institutes are modelled here as a `wellness`-or-`other` plan with `employee_contribution_percentage = 0` (employer-paid) when the institute opts to render them as a benefit rather than a payroll allowance. |
| `other` | "Education Subsidy" / "Transport Allowance" | n/a | varies | 0% or 100% | 0 | Schoolfees, travel cards, fuel coupons. The MVP renders these as a benefit row (with the monthly amount as a "premium") rather than a payroll allowance because they're optional — the employee enrols, not the payroll engine. The wiring to actually deduct (employee-paid) or credit (employer-paid) on payroll is roadmapped (see honest gaps). |

The `BenefitType::Other` case is a deliberate escape valve. Most Ghanaian institutes have institute-specific welfare schemes — a "rent advance recovery", a "transport coupon", a "burial fund" — that do not map cleanly to any of the six named cases. Stashing them under `other` keeps the enum stable.

### Premium computation — exactly

`BenefitsService::enrol(plan, employee, effectiveFrom, premium?, actor?)` does the maths in three lines (paraphrased):

```php
if ($premium === null) {
    $pct = (float) $plan->employee_contribution_percentage; // e.g. 25.00
    $premium = round((float) $plan->monthly_cost * ($pct / 100), 2);
}
// $premium is then stored on the enrolment row.
```

So a plan with `monthly_cost = 350.00` and `employee_contribution_percentage = 25.00` produces a default premium of `87.50` per month. If HR (or the employee) passes an explicit `premium = 100.00` in the enrol form, `100.00` is stored verbatim and the percentage is ignored. The override path is the offer-letter-specific case; the percentage path is the default.

Three things this does **not** do that you might expect it to:

1. **It does not pro-rate.** An enrolment effective 15 Jun still carries the full month's premium for June. A future "prorate by days in period" flag on the plan is on the Phase 1 backlog.
2. **It does not enforce an open-enrolment window.** Any active plan can be enroled into on any day, with any `effective_from`. The "open enrolment window" notion exists in policy and onboarding training, not in code.
3. **It does not recompute on plan-rate changes.** If HR raises `monthly_cost` on a plan with 200 active enrolments, the existing 200 enrolments keep their stored `monthly_premium` — only *new* enrolments pick up the new default. Phase 1 adds a "Recalculate premiums" action on the plan-edit panel that re-derives the percentage for each active enrolment and stamps an effective-from on the new amount.

### Provident-fund lifetime view

`BenefitsService::providentFundView(employee)` is a separate query path that surfaces on the My Benefits page only when the employee has at least one `provident_fund`-type enrolment. For each such active enrolment it computes:

```text
monthsActive    = max(0, monthsBetween(effective_from, now))
totalContributed = round(monthly_premium × monthsActive, 2)
```

— and returns the triplet `{plan_id, plan_name, monthly_premium, months_active, total_contributed}` for each. The page renders one gold-accented card per row, with the lifetime contributed in a large monospace figure and the "Contributed over N months · GHS X/mo" tagline beneath it.

What this view explicitly does **not** do:

- It does not subtract any withdrawals (the MVP has no withdrawal flow against a benefit enrolment).
- It does not factor in investment growth — `total_contributed` is the cumulative *deposit*, not the *current balance*. A real provident fund accrues interest; the MVP only tracks contributions.
- It does not call out to the trustee's API for the official balance — the figure is computed locally from the enrolment metadata.

Phase 1 wires the trustee-side balance call (parallel to the Tier-2 trustee schedules in Ch 19) so the "Total contributed" card can show both *what you've put in* and *what the trustee says you have*. Phase 3 adds the actual withdrawal / claim flow.

### Payroll-deduction wiring — what's there, what isn't

The Ch 19 payroll engine has a voluntary-deduction pipeline (`DeductionAggregator`) that walks the employee's `Deduction` rows priority-ordered, with a net-pay floor and a "deferred" return value for amounts that would have pushed the line below the 1/3-of-gross take-home floor. The intent is for benefit premiums to flow through that pipeline as `Deduction` rows — and Ch 19 explicitly says so in "How it talks to other modules":

> *"Benefits (Ch 23) — benefit premiums (where they are configured as a deduction on the employee) flow through the same `Deduction` rows as any other voluntary deduction, with the standard floor protection."*

**This wiring does not exist in the MVP codebase.** Concretely:

- The `DeductionType` enum has eight cases (`LoanRepayment`, `SalaryAdvance`, `Garnishment`, `UnionDues`, `Sacco`, `Welfare`, `Tier3Voluntary`, `Other`) — none of them is `BenefitPremium`.
- `BenefitsService::enrol` does not create a `Deduction` row. It only inserts the `BenefitEnrolment` row.
- `PayrollService::calculate()` does not query `BenefitEnrolment` and does not read `monthly_premium`.
- No event listener on `BenefitEnroled` materialises a `Deduction` row.

So the *current* operational truth is: enrol an employee in a GHS 87.50 health plan, run payroll for that employee in the same month, and the employee's net pay will be unchanged. The premium is "billed" only insofar as an HR officer manually creates a matching `Deduction` row of type `Other` referencing the enrolment in `notes`.

Phase 1 closes this with one of two patterns:

- **Pattern A — auto-materialise a Deduction.** A `BenefitEnroled` listener creates a matching `Deduction` row with `type = BenefitPremium` (new enum case), `priority = 90` (lowest, below all current voluntaries), `amount = enrolment.monthly_premium`, `effective_from = enrolment.effective_from`, `effective_to = enrolment.effective_to`, and a back-reference on `meta.enrolment_id`. The deduction inherits the take-home floor; a benefit premium that would push the employee below 1/3 is deferred to next month rather than refused outright. **This is the recommended pattern** because it keeps `DeductionAggregator` as the single source of truth for "everything that comes out of gross pay".
- **Pattern B — wire BenefitEnrolment directly into `PayrollService::calculate`.** `PayrollService::calculate` reads `Employee::active()->with('benefitEnrolments')`, sums the active premiums, and emits a synthetic line item in `breakdown.benefits`. Cheaper to ship; harder to audit (now the calculator has two pipes for "things that come out of gross").

Either way, the bridge is one week of work and is on the Phase 1 explicit-gaps list at the top of the chapter.

### Termination — voluntary and end-of-employment

The MVP has **no termination flow** for a benefit enrolment. There is no `BenefitsService::terminate` method; there is no "Unenrol" button on the My Benefits page; there is no listener on `EmployeeTerminated` / `OffboardingCompleted` that walks the employee's open enrolments and stamps `effective_to + status = terminated`.

Concretely:

- The schema supports termination (`status = 'terminated'`, `effective_to` date) but the only writer for these fields is a manual `BenefitEnrolment::update()` call from the console.
- The `BenefitEnrolmentStatus` enum has a `Suspended` case (between Active and Terminated) intended for the leave-without-pay or sabbatical case — also unused by any service method.
- `OffboardingService::complete` (Ch 10) flips the employee status to Terminated and closes any open loans against the final settlement, but it does **not** touch `benefit_enrolments`.

Operationally, this means a terminated employee can still appear on the next monthly premium total in HR's reports unless someone goes into the console (or, once Pattern A above ships, the deduction would stop firing because the *employee* is no longer `active()`). That's an inconsistency the Phase 1 work above closes — but it's an honest gap in the MVP and needs to live in this chapter.

### E-card PDF

`BenefitsController::downloadECard($enrolment)` renders a PDF using `barryvdh/laravel-dompdf` from the Blade template `resources/views/pdf/benefits-ecard.blade.php`. The template receives `enrolment`, `plan`, `employee` (with user + dependants), and renders a single A6-style card with:

- Plan name + code + type.
- Employee name + employee number.
- Effective from (and effective_to if set).
- Monthly premium.
- Dependants list (where `is_covered = true`).

The PDF is downloaded as `ecard-{enrolment_id}.pdf` (e.g. `ecard-42.pdf`). It is **not** an OCR-scannable insurance card with a barcode or QR — it's a printable proof-of-cover for clinic / dental / optical reception staff. Phase 1 adds a QR encoding the enrolment reference for provider-side scan-to-verify.

## How it talks to other modules

- **Employees (Ch 3)** — the Add-Employee slide panel's "Benefits enrolment" section multi-selects active plans and `EmployeeService::createEmployee` calls `BenefitsService::enrol` for each within the same transaction as the user / employee insert. If any enrolment throws (e.g. a plan was deactivated between page load and submit), the whole employee create rolls back.
- **Off-boarding (Ch 10)** — should auto-end open enrolments on `OffboardingCompleted`. Currently does not. See "Termination" above and the Phase 1 list.
- **Payroll Engine (Ch 19)** — should deduct `monthly_premium` per active enrolment via a `Deduction` row of a new `BenefitPremium` type, with floor protection. Currently does not. See "Payroll-deduction wiring" above.
- **Finance Hub / F1–F5 (Ch 20)** — when a claim is `Mark Paid`, the GIFMIS journal should debit the relevant benefit-expense GL and credit the employee's net-pay or vendor account (depending on whether the institute reimburses the employee or pays the provider direct). The MVP does **not** mint a journal on claim payment; it just flips the status. Phase 1 wires a `BenefitClaimDecided` listener that writes the AP invoice into the F2 pipeline.
- **Disbursements (Ch 22)** — when a claim is `Mark Paid`, the disbursement engine should materialise a row with the same channel (bank / MoMo / GhIPSS) and E-Levy treatment as a salary disbursement. Currently the "Mark Paid" transition is a status flip only — no Disbursement row is created. Phase 1 wires the listener.
- **DPA & Privacy (Ch 26)** — every claim's `description` field is free-form text that almost always names a clinical condition or treatment. That makes it health data under DPA Act 843 §39 and earns it the heightened-care treatment in the privacy chapter: encryption-at-rest (column-level encryption is a Phase 2 item), audit on every read by anyone other than the data subject, and inclusion in the right-to-erasure redaction set. The masked-Ghana-Card pattern from Ch 3 does not apply to dependant national IDs in the MVP — that's a known gap.
- **Audit Logs (Ch 24)** — `BenefitPlanCreated`, `BenefitEnroled`, `DependantAdded`, `BenefitClaimSubmitted`, `BenefitClaimDecided` are all dispatched as Laravel events at the relevant service boundaries. They are not yet wired into the immutable audit-log handler in Ch 24 — the events fire, but only as in-memory dispatches with no listener that persists to the audit chain. Phase 1 adds the listener (a one-class fix).
- **Profile portal (Ch 16)** — the employee's profile page renders an enrolments list on the "Benefits" tab via the same `Employee::benefitEnrolments()` relation. That tab is read-only; the action set lives on `/benefits`, not on `/profile`.

## Standards touchpoints

- **Data Protection Act, 2012 (Act 843) §39 — sensitive personal data (health)** — every `BenefitClaim.description` and every `BenefitEnrolment` against a `health_insurance` or `dental` or `vision`-typed plan is health data under §39. The Act requires explicit consent for processing, a defined retention period, and access controls that are stricter than for ordinary personal data. The MVP's `BenefitsPolicy` honours the "stricter access" requirement (`benefits.view_all` is held only by HR / Finance / Auditor / CEO / super_admin, not by line managers); the explicit-consent and retention pieces are roadmapped in Ch 26's DPA work pack. See Chapter 26 and Chapter 44.
- **National Health Insurance Act, 2012 (Act 852) — relevance for private top-up cover** — every Ghanaian employee is by default a member of the national health insurance scheme (NHIA) which is funded out of payroll at 2.5% via SSNIT (see Ch 19's NHIA split). Private `health_insurance` benefit plans in CIHRMS sit *on top of* NHIA cover — they are the gap-filler for non-NHIA services (specialist consultations, premium hospital networks, dental, optical). The chapter does not claim NHIA-equivalence; the plan description should make this distinction explicit. See Chapter 44.
- **National Pensions Act, 2008 (Act 766) — Tier-3 voluntary pension** — the `provident_fund` benefit type is CIHRMS's plug for Tier-3 voluntary pension scheme contributions (over and above the mandatory Tier-1 5.5% / 13% and Tier-2 5% in Ch 19). The lifetime-contributed view is the employee-facing surface for that accrual; the trustee-side balance call is roadmapped. See Chapter 19 and Chapter 44.
- **Labour Act, 2003 (Act 651) §68 — termination & end of employment entitlements** — on termination, the employee is entitled to settlement of accrued benefits. The MVP does not currently auto-net provident-fund balances or unsettled claims into the final settlement (Ch 10) — that's the off-boarding gap noted above. Phase 1 wires the netting. See Chapter 10 and Chapter 44.
- **ISO 30414 §4.4 — workforce skills & capabilities (benefits as part of total rewards)** — total compensation under ISO 30414 includes the employer-paid portion of every benefit plan (the 75% subsidy on a 25%-employee plan, the 100% subsidy on group-life cover). The MVP captures the `employee_contribution_percentage` per plan, so the analytics chapter (Ch 13) can compute the employer share at run-time; the disclosure itself is on the Ch 13 backlog. See Chapter 44.
- **Public Financial Management Act, 2016 (Act 921) §11 — internal controls on payments** — claim payments out of public funds require dual control. The MVP's `BenefitsPolicy::manageClaims` is single-permission (any user with `benefits.manage` can move a claim through every state including `paid`). Phase 1 adds a second-signer step on the `approved → paid` transition mirroring the `payroll.approve` 2FA gate. See Chapter 44.

## Reports

The `/benefits` page renders three computed projections that double as reports:

1. **Coverage snapshot** (per employee, on My Benefits) — active enrolments, monthly total, dependants count, claims pending, lifetime claimed. The same numbers, summed across the institute, would be the org-level "Total welfare load this month" report. The MVP does not yet have that org-level page — `BenefitsController` only renders per-employee. Phase 1 adds `/benefits/admin/overview` with one tile per institute-wide aggregate.
2. **Plan composition** (per employee, by type) — stacked-bar breakdown across the seven `BenefitType` cases. The same breakdown summed across the institute is the "Uptake by plan" report referenced in the spec — Phase 1 surfaces it on the same admin overview.
3. **Provident-fund lifetime contributed** (per employee) — gold-accented cards on the same page. Phase 1 adds a per-plan version on the plan-detail page: "Total contributed by all employees to this provident plan, by month".

What the MVP does **not** yet ship:

- A "costs per period" report (employer subsidy × month × plan) — would feed Finance's monthly accruals.
- An "uptake by department" report (which departments are over- or under-enrolled relative to headcount).
- A "claim-frequency by plan" report (which plans drive the most claims volume, by amount and by count).
- An export to CSV / XLSX of the claims queue or the enrolment list — the data is in the database, the UI does not surface a download.

All four are on the Phase 1 reports backlog.

## What's planned next

Phase 1 of the government-grade roadmap (see Chapter 46) closes the explicit gaps surfaced in this chapter and lifts Benefits from a self-service module to a payroll-integrated, audit-grade welfare desk. In sequence: (1) **Payroll-deduction bridge** — pattern A above, a `BenefitEnroled` listener that materialises a `Deduction` row of type `BenefitPremium` for each enrolment, automatically tracked, automatically floor-protected, automatically deferred when the take-home floor would otherwise be breached. (2) **Termination listener** — on `OffboardingCompleted` (Ch 10), walk every open enrolment, stamp `effective_to = last_working_day` and `status = terminated`, and provident-fund balances drop into the final settlement netting via `FinalSettlementCalculator`. (3) **Audit-log wiring** — add the five listeners on `BenefitPlanCreated`, `BenefitEnroled`, `DependantAdded`, `BenefitClaimSubmitted`, `BenefitClaimDecided` so every state change is persisted to the Ch 24 immutable audit chain. (4) **Dual-control on claim payments** — split `benefits.manage` into `benefits.decide` (approve / reject) and `benefits.pay` (mark paid), require a fresh 2FA challenge on `approved → paid`, and refuse the payment transition when the payer is the same user as the decider. (5) **Finance journals on claim payment** — a `BenefitClaimDecided` listener (status = paid) writes the AP invoice into the F2 pipeline so the GIFMIS JV picks it up at the next cycle. (6) **Sequence-service for claim references** — swap the random 8-char `CLM-` for the same `SequenceService::next('claim')` pattern Finance uses elsewhere, producing chronological `CLM-YYYY-NNNNNN`. (7) **Org-overview report** — `/benefits/admin/overview` with uptake by plan, uptake by department, monthly cost per period, claim frequency per plan, and CSV/XLSX export. (8) **Trustee-side balance call** for the provident-fund cards. (9) **QR-coded e-cards** for provider-side scan-to-verify. Phase 2 promotes column-level encryption on `claim_description` and `dependant.national_id` per the DPA §39 work pack, and adds plan-side rules for "child must be under N" and "dependant cap from the strictest active plan, not the most generous".
