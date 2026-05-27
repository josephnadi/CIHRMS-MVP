# Chapter 3 — Employees

> *In one paragraph.* Employees is the master record of the workforce — every person in CIHRMS has exactly one. HR creates and edits the record, line managers and department heads view their slice of it, and the employees themselves see (and partly edit) their own copy through the Profile portal in Chapter 16. Everything downstream — leave entitlements, payroll runs, ticket ownership, performance cycles, benefits enrolment — hangs off this single row.

## Where to find it

- **Sidebar location:** **Workforce** group → **Employees** (top-level item, badge icon). The "Employees" tile is also pinned in the app switcher (the 3×3 grid in the top bar) so any role with `employees.view` or `employees.manage` can jump straight to the directory from anywhere.
- **Roles that see it:**
    - **super_admin** and **ceo** — every employee, every field.
    - **hr_admin** — every employee, every field, including salary.
    - **dept_head / manager** — the employees in the department(s) they head plus their own direct reports. They cannot see salary by default.
    - **employee** — only their own row (via the Profile portal in Chapter 16). They never reach `/employees` directly.
    - **finance_officer**, **auditor**, **it_support** — limited views, gated by separate permissions (`employees.view_salary`, `employees.view`).
- **Related modules:** Departments (Ch 15) — every employee belongs to one; Leave (Ch 4) — entitlements and balances are computed per employee; Tickets (Ch 5) — opens and assigns reference employee records; Payroll (Ch 8) — pulls salary, bank, SSNIT and Tier-2 trustee fields from here every pay run; Benefits (Ch 11) — plan enrolments hang off the employee row; Profile Portal (Ch 16) — the self-service face of the same data.

## The screens

![Employee directory — table, filters, workforce stats band](../assets/screenshots/03_employees/directory.png)

*Callouts: ❶ Workforce stats band — total headcount, active on roll, on-leave count, departments tally, plus twin donut breakdowns for status mix and department distribution. All numbers are scoped to what the signed-in user is allowed to see. · ❷ Filter strip — debounced free-text search across name, staff number, and position; department dropdown; status dropdown; one-click "Clear". · ❸ Table row — avatar (initials or photo), name, email, "reports to" line, monospace staff number, department, position, hire date, status badge, three row actions (view, change status, delete).*

![Employee detail — profile hero, identity cards, tabs](../assets/screenshots/03_employees/detail.png)

*Callouts: ❶ Profile hero — avatar with online-status dot, name, status badge, position, department chip, role chip, and three quick stats: staff ID, hire date, computed years of service. · ❷ Three contact cards (phone, email, system role) and the Ghana Compliance card showing masked Ghana Card / SSNIT / TIN with click-to-copy buttons. · ❸ Tab bar — Overview · Documents · Leave History · Tickets · Payroll · Benefits. Overview is loaded by default; the other tabs lazy-render to keep the first paint fast.*

![Add Employee — slide panel with login, department, benefits sections](../assets/screenshots/03_employees/create.png)

*Callouts: ❶ "Login account" section (cobalt tint) — full name, email, system role, temporary password, and the auto-generated staff ID. · ❷ Workforce details — department (required), employee number (auto), position, phone, hire date, status. · ❸ "Benefits enrolment" section (gold tint) — multi-select grid of active benefit plans. Each card shows the monthly premium and employee-contribution percentage so HR can see the cost impact before saving.*

![Edit Employee — narrower slide panel for the editable subset](../assets/screenshots/03_employees/edit.png)

*Callouts: ❶ Six fields only — department, employee number, position, phone, hire date, status. Personal, bank, and salary edits happen elsewhere: the employee edits their own personal/bank from the Profile portal (Ch 16); salary edits live behind `employees.view_salary` and are made through the dedicated compensation flow. · ❷ "Save Changes" sends a PATCH with only the fields you touched — every field is `sometimes` in the FormRequest, so partial updates are first-class. · ❸ The same panel can be reached from any row on the directory via the inline edit button, and from the profile page's "Edit Profile" button.*

> The four screenshot files referenced above will be captured in Wave 1 (task W1.19). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Employee directory

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Add Employee** (cobalt CTA, top right) | Opens the right-hand slide panel for creating a brand-new User + Employee record in a single transaction. | `employees.manage` (typically hr_admin, super_admin, ceo) | One-step onboarding — HR shouldn't have to create a login and then go find it to attach an Employee record. |
| **Add Department** | Opens a small slide panel to create a department on the fly (name, code, description). | `employees.manage` | Hires happen faster than org-chart updates; HR needs to be able to fix a missing department without leaving the page. |
| **Departments** | Navigates to the dedicated Departments page (Ch 15). | Same as above | Promotes the full department CRUD when the user wants more than a quick add. |
| **Search box** ("Search name, ID, position…") | Free-text filter that fires 380 ms after you stop typing. Matches `users.name`, `employees.employee_no`, and `employees.position` with a `LIKE` scan. | Anyone who can see the directory | Most lookups are by name or ID — a debounced search beats refresh-on-every-keystroke for both feel and database load. |
| **All Departments** dropdown | Restricts the table to one department. Re-fires the query immediately on change. | Anyone who can see the directory | Department heads land here looking for "my team" — a single click is the fastest path. |
| **All Statuses** dropdown | Filters by `active`, `on_leave`, `inactive`, or `terminated`. | Same | Hiding terminated staff is the most-asked-for view; this is how. |
| **Clear** (appears only when a filter is set) | Resets all three filters and re-queries. | Same | One-click undo when an experiment didn't pan out. |
| **Row click** (anywhere on the body) | Navigates to the employee's detail page. | `view` permission on that employee (RBAC-checked in `EmployeePolicy::view`) | Whole-row hit target is faster than chasing a small "view" button. |
| **View** (eye icon) | Same as row click — explicit affordance for screen readers and keyboard users. | Same | Accessibility — a row isn't naturally focusable in tab order; this button is. |
| **Change status** (swap icon) | Opens a small popover with the four statuses. Clicking one PATCHes that employee's `status` field only; the page refreshes in place. Selecting "Terminated" first asks for a browser confirm — that's a one-way change in practice. | `employees.manage` or department head over that employee | Status is the single most-changed field. Forcing a trip into the full edit form for "Active → On Leave" would be needless friction. |
| **Delete** (trash icon) | Opens a confirmation dialog. On confirm, soft-deletes the employee (`SoftDeletes` trait — the row stays in the DB and can be restored from the console). | `employees.manage` | Mistaken creates happen. Soft delete keeps the audit trail intact while removing the row from every list. |
| **Pagination** (bottom-right) | Standard Inertia pagination — 20 per page, current range and total shown at the bottom-left. | Anyone who can see the directory | Keeps the table responsive on institutes with thousands of employees. |
| **Workforce stats band** | Read-only — total headcount, hires in the last 30 days, active count + active rate %, on-leave count + share %, department count. Below: status-mix donut and top-6 departments donut. | Same RBAC scope as the table (a department head sees only their slice's numbers) | The first answer most stakeholders want when they land on the page — "how big is the team and what shape is it in?" — surfaced before they have to scroll or filter. |

> *Notes:* The stats band gets its numbers from `EmployeeService::stats()`, which honours the same `visibleTo()` scope as the table, so a dept_head's "Total Headcount" tile reflects their team, not the institute. The donut chart uses the brand cobalt → sky → magenta palette with grey for "other"; "terminated" intentionally keeps red as a severity signal. The "Change status" popover closes on any outside click via a single document-level listener that is removed on `onBeforeUnmount`, so navigating away never leaks a handler.

### Employee detail

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Back arrow** (top left) | Returns to the directory. | Anyone viewing | Standard back affordance — Inertia keeps the directory's scroll position. |
| **Edit Profile** (cobalt CTA, top right) | Opens the right-hand "Edit Employee" slide panel pre-filled with the current row. | `employees.manage`, department head over the employee, or the employee themself (limited to a subset of fields) | Edit is the most-clicked action on this page — it deserves the headline position. |
| **Profile hero** | Read-only display block: avatar + status dot, name + status badge, position, department chip, role chip, quick stats (staff ID, hire date, computed service). | Anyone viewing | Identity at a glance. Years-of-service is computed client-side from `hire_date` so it never lags. |
| **Phone / Email / Role contact cards** | Read-only cards with colour-coded icon wells (cobalt, cyan, magenta). | Same | Three highest-frequency lookups isolated above the fold. |
| **Ghana Compliance card** (Ghana Card, SSNIT, TIN) | Each number is masked except the last four digits. A copy-to-clipboard button next to each puts the full value on the clipboard (silent on failure). | Same | DPA-friendly default — full identity numbers stay off the screen until explicitly copied. |
| **Overview tab** (default) | Employment details, personal details, reporting (manager + direct reports), emergency contact, compensation (when set), and skills/certifications. The "Direct Reports" list links each report to their own profile page. | Anyone viewing | The single most-used view; everything you'd want from a paper personnel file in one place. |
| **Documents tab** | Lists every uploaded document (title, upload date, download, delete). Above the list sits an upload form (title + file picker). Files store under `storage/app/public/employee-documents/`. | `employees.manage` to upload or delete; anyone with view to download | A live document drawer replaces the manila folder. PDF, Word, Excel, and common images are all accepted (up to 10 MB). |
| **Upload** (Documents tab) | Posts to `POST /employees/{id}/documents`. Title and file are both required client-side. | `employees.manage` | Title is mandatory so the list stays scannable — no `IMG_4719.pdf`. |
| **Download** (per document) | Streams the file from public storage. | Anyone with view | Standard. |
| **Delete document** (per document) | Confirmation dialog → `DELETE /employees/{id}/documents/{doc}`. | `employees.manage` | Same one-way safeguard as employee delete. |
| **Leave History tab** | Four balance tiles (Annual, Sick, Maternity, Compassion) above a table of all leave requests scoped to this employee (latest 8 are pre-loaded). | Anyone with view on the employee | Single pane of glass for "what time has this person had off, and what's left?". The Leave module (Ch 4) is the authoritative editor. |
| **Tickets tab** | Table of service tickets raised by or assigned to this employee — id, title, priority (colour-coded), status, created date. Row click jumps to the ticket page. | Anyone with view | Quick check that an absence isn't tied to an open IT issue, for example. |
| **Payroll tab** | Last 8 payments — period, description, amount in GHS, status, paid date. | `employees.view_salary` to see amounts | Read-only — payments are created and reversed in Payroll (Ch 8). |
| **Benefits tab** | Each active benefit enrolment as a row — plan name and code, type, provider, effective-from date, monthly premium, status. | Anyone with view | Confirms what the employee is signed up for without opening the Benefits module (Ch 11). |

> *Notes:* The whole page hydrates from one Inertia render — `EmployeeController::show` returns the employee with department, user, manager + manager's user, the latest 8 leave requests, the latest 8 tickets, the latest 8 payments, all documents, all skills, reports, and benefit enrolments. There is no N+1 between the tabs. Salary is gated at the `EmployeeResource` layer — when the viewer fails `EmployeePolicy::viewSalary` the field is omitted entirely from the JSON, not just hidden in CSS.

### Create form (Add Employee slide panel)

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Full Name** | required, max 255 chars | `employees.manage` | Saved to `users.name`. |
| **Email** | required, valid email, unique on `users.email`, max 255 | Same | The employee's login email — must be globally unique. |
| **System Role** | required, must match the `UserRole` enum (employee, manager, dept_head, hr_admin, finance_officer, it_support, auditor) | Same | Drives the legacy enum role on the User; the database-backed Role pivot is attached in the same transaction. |
| **Temporary Password** | required, min 8 chars | Same | HR sets it, hands it to the new hire out-of-band; the new hire changes it on first login. There is no "send invite email" path in the MVP. |
| **Staff ID** | optional; max 50; unique on `users.staff_id` | Same | Auto-generated when blank as `SID-NNNNNN` (6-digit minimum, row-locked in a transaction so two concurrent HR creates can't collide). Manual entry is accepted for data imports. |
| **Department** | required (via UI), validated as `nullable | integer | exists:departments,id` server-side | Same | The UI requires it because every dashboard, RBAC scope, and chart pivots on department. Server-side `nullable` exists for legacy data imports. |
| **Employee No.** | optional; max 50; unique on `employees.employee_no` | Same | Auto-generated when blank as `CIHRM-NNNN` by `EmployeeIdentifierService::nextEmployeeNo()`. The widget shows the "AUTO" pill so HR knows not to type one. |
| **Position** | required, max 255 | Same | Free-text in the MVP; Phase 1 turns this into a foreign key to the `positions` establishment table. |
| **Phone** | optional, max 20 | Same | No format check yet — the MVP accepts any string. |
| **Hire Date** | required, valid date, must be on or before today | Same | Backdating is allowed (legacy imports); future-dating is not. Probation end is computed as hire date + 90 days on the detail page. |
| **Status** | optional; one of `active`, `inactive`, `on_leave`, `terminated`; defaults to `active` | Same | The Add panel only offers Active/Inactive — moves to On Leave / Terminated happen through the row-action menu or the edit panel once the employee exists. |
| **Benefits enrolment** (multi-select grid) | each id must exist on `benefit_plans` and the plan must be active | Same | Each selected plan is enrolled via `BenefitsService::enrol`, which computes the monthly premium from the plan's contribution % and the employee's salary in one go (Ch 11). |
| **Salary** *(not in this panel, but in scope of the FormRequest)* | optional, numeric, ≥ 0; **also** requires `employees.view_salary` permission | `employees.view_salary` | Hidden from the Add panel in the MVP UI; reachable only through the API. The custom validator returns "You do not have permission to set salary." for any user without the flag. |

> *Notes:* The whole create is wrapped in a database transaction. If anything fails — duplicate email, invalid department, benefit-enrolment exception — the new User, the new Employee, and any partial benefit rows all roll back together. On success the service fires `EmployeeCreated` (see below) before returning, and the panel closes with a toast.

### Edit form (Edit Employee slide panel)

| Field | Validation | Who can change it | Notes |
|---|---|---|---|
| **Department** | optional, must exist on `departments` | `employees.manage`; the dept head who owns the employee's current department; or the employee themself | A transfer in effect — Phase 1 will route this through a dedicated transfer workflow with effective dates and audit. |
| **Employee No.** | optional, max 50, unique excluding the current row | `employees.manage` only | Editable so data imports can be corrected, but in normal operation HR leaves it alone. |
| **Position** | optional, max 255 | Same RBAC trio | Free-text. |
| **Phone** | optional, max 20 | Same | |
| **Hire Date** | optional, valid date, on or before today | Same | Backdating allowed; future-dating rejected. |
| **Status** | optional, must be in the `EmployeeStatus` enum | Same | The full enum is offered here (Active / On Leave / Inactive / Terminated). |
| **Personal fields** (`gender`, `date_of_birth`, `national_id`, `address`) | `gender` must be one of `male / female / other / prefer_not_to_say`; DOB must be in the past; others are free strings up to 64–255 chars | Same trio — the employee can edit their own | These are not in the Edit panel UI today — they are edited from the Profile portal (Ch 16) but the same FormRequest accepts them so a future single-panel edit only needs UI work. |
| **Emergency contact** (`name`, `phone`, `relationship`) | optional strings | Same | Same as above — managed from the Profile portal in the MVP UI. |
| **Bank** (`bank_name`, `bank_account`) | optional strings | Same — the employee can edit their own bank details | Driven from the Profile portal in the MVP; the API accepts it on this endpoint too. |
| **Salary** | optional, numeric, ≥ 0; **plus** the `employees.view_salary` gate | `employees.view_salary` only | Same FormRequest rule as create — anyone else attempting it gets a clear "You do not have permission to edit salary." error. |
| **Save Changes** (cobalt CTA) | PATCH to `/employees/{id}`. Only the fields you touched are sent because every rule is `sometimes`. | The same RBAC trio | Partial-update semantics — the inline "Change Status" popover on the directory works because of this. |
| **Cancel** | Closes the panel without firing anything. | Anyone | Standard escape. |

> *Notes:* `UpdateEmployeeRequest::authorize()` is where the three-tier permission lives — HR > department head > self. Anyone else gets a 403 before validation even runs. Validation rules use `sometimes` everywhere, so a PATCH carrying only `{ "status": "on_leave" }` is treated identically whether it came from the inline popover or the full edit panel.

## The data behind it

CIHRMS stores three layers of information about each person, all pinned to the single `employees` row:

- **Employment basics** — staff number (auto-generated `CIHRM-NNNN`), department, position, line manager, hire date, status (`active`, `on_leave`, `inactive`, or `terminated`), and a soft-delete flag so dismissed staff can be recovered without losing the audit trail.
- **Personal information** — gender, date of birth, address, and three Ghana-specific identity numbers: `national_id` (Ghana Card PIN), `ssnit_number` (social security), and `tin_number` (tax). Every one of these is optional in the MVP — HR can capture them progressively rather than blocking onboarding.
- **Operational extras** — emergency contact (name, phone, relationship), bank details (name, account, sort code), salary (gated behind a separate permission), an uploaded avatar, a Tier-2 pension trustee reference, a disbursement-channel preference (cash, bank transfer, mobile money, or GhIPSS), an external CRM id for syncing with finance systems, and four "establishment" fields — current position, current grade, current step, and step anniversary date — which sit dormant in the MVP and become live in Phase 1's establishment-control work.

The employee row joins out to **one** `User` (the login account), **one** `Department`, **one** other Employee as `manager_id` (so reports-to is a self-reference), and **many** of just about everything else: documents, skills, leave requests, tickets, payments, allowances, deductions, identity verifications, benefit enrolments, dependants, and position-assignment history.

What every reader of the screen needs to keep in mind:

- **Staff ID** (on the User) and **Employee No.** (on the Employee) are different things. The first is the login identifier; the second is the workforce identifier. Both are auto-generated and both are unique, but they live on different tables for a reason — payroll cares about Employee No.; auth cares about Staff ID.
- **Soft delete** means "removed from every list, recoverable in the console". It does not mean "wiped". Right-to-erasure requests (DPA Act 843 §40) are handled by a separate redaction job, not by clicking Delete.
- **Salary visibility** is enforced at the API layer, not the UI. A user without `employees.view_salary` gets no `salary` key in the JSON at all — the field doesn't exist for them. The same gate decides whether they can set or change a value.
- **Visibility scope** is enforced server-side in `Employee::scopeVisibleTo()`. The frontend never trims a list it has been given; the backend simply doesn't return rows the user isn't entitled to. Department heads see their department + their direct reports; everyone else sees only themselves.

## How it talks to other modules

- **`EmployeeCreated` event** → fired at the end of every successful create. Three listeners pick it up: the Analytics module (Ch 13) records a `workforce.created` event; the Notifications module (Ch 14) sends the `NewEmployeeWelcome` mail; and the AI summary service (Ch 22) refreshes the employee's first cached profile summary.
- **`Department` foreign key** → every Employee belongs to at most one department. Deleting a department is blocked while any employee is still assigned to it (`EmployeeService::deleteDepartment` throws `DomainException`, surfaced as a flash error). See Departments (Ch 15).
- **Leave (Ch 4)** reads `employee_id` on every leave request and pulls the employee's manager for the approval routing.
- **Tickets (Ch 5)** opens against an `employee_id` and uses the same RBAC scope — a department head sees tickets for the same employees they see in the directory.
- **Payroll (Ch 8)** runs per pay period over `Employee::active()` and reads salary, bank details, SSNIT and Tier-2 trustee fields directly. A missing field on an active employee is what produces the "Payroll Readiness" warnings on the Payroll dashboard.
- **Benefits (Ch 11)** stores one `BenefitEnrolment` per (employee, plan) pair; the Profile portal renders them on the Benefits tab via the same `whenLoaded('benefitEnrolments')` relation.
- **Profile Portal (Ch 16)** is the employee-self-service view onto exactly the same Employee row. The portal's "Edit my details" form posts to the same `PATCH /employees/{id}` endpoint as the HR edit panel — the `UpdateEmployeeRequest` authorises self-edits to the limited subset (personal, emergency, bank).
- **Identity verification (Ch 22)** writes `IdentityVerification` rows linked to the employee; `Employee::hasUsableIdentity()` is the convenience check used by the off-boarding and high-risk approval flows.

## Standards touchpoints

- **ISO 30414 §4.1 (workforce composition)** — every reporting metric in the workforce stats band (headcount, status mix, department distribution, tenure breakdown, gender breakdown) is one of the disclosures recommended by the standard. The companion analytics chapter (Ch 13) collects these into the institute-wide report. See Chapter 27.
- **ISO 30414 §4.5 (leadership)** — the manager / reports relationship on this screen is the source of truth for span-of-control and leadership ratios used in the analytics export.
- **Data Protection Act, 2012 (Act 843) §17 — lawful basis for processing personal data** — the system relies on the employment-contract basis for processing employee personal data, and exposes Ghana Card / SSNIT / TIN with last-four-digit masking by default to honour data-minimisation expectations under §18 and §40. See Chapter 27.
- **Data Protection Act §40 — right to erasure** — soft delete is the operational default; a dedicated redaction job covers true erasure requests. The DPA-grade audit pack is built in Phase 2.
- **National Identification Authority Act, 2006 (Act 707) — Ghana Card integration** — the `national_id` field already captures the Ghana Card PIN; live NIA verification (biometric match, PIN check) is roadmapped in Phase 1.

## What's planned next

Phase 1 of the government-grade roadmap (8–10 weeks, see the gap analysis) lands three things directly on this screen: (1) Position/Grade/Step lookups replace the free-text `position` field, with the four dormant establishment columns (`current_position_id`, `current_grade_id`, `current_step`, `step_anniversary_date`) coming online behind a dedicated transfer/promotion workflow; (2) a Ghana Card adapter validates `national_id` against the NIA service synchronously on create/edit and asynchronously via `VerifyEmployeeIdentity`, writing an `IdentityVerification` row so the green "verified" tick can show on the profile hero; (3) every create/update/delete on this record starts writing to the tamper-evident audit log so the Auditor-General pack in Chapter 24 has full provenance.
