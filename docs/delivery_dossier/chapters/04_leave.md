# Chapter 4 — Leave

> *In one paragraph.* Leave is how time off is asked for, decided on, and counted against an annual entitlement — Annual, Sick, Maternity, Paternity, Emergency, Study, and Unpaid, all priced against the Ghana Labour Act, 2003 (Act 651). Employees apply for themselves from the same page their balances are shown; line managers and HR see a separate "Approvals Desk" with a pending queue, a filterable archive, and a month calendar that shows who is off when. Approval increments a per-employee, per-year `leave_balances` row in a row-locked transaction, so two approvers acting on the same request at the same time cannot double-spend a balance.

## Where to find it

- **Sidebar location:** **People** group → **Leave** (calendar icon, amber accent in the app switcher because absence is a soft-alarm signal). The "Request leave" tile is also pinned in the Quick Actions menu — clicking it deep-links into `/leave-requests?new=1` which auto-opens the Apply panel.
- **Roles that see it:**
    - **super_admin** and **ceo** — every leave request, every employee.
    - **hr_admin** / **hr_officer** — every leave request, the approvals queue, the calendar across the whole organisation. Can also approve or reject anything (`leave.manage`).
    - **dept_head / manager** — requests for the employees whose department they head (or whose `manager_id` points at them) appear in their approvals queue. They can approve or reject those (`leave.approve`) but not anyone else's.
    - **employee** — their own requests and their own balances only. They see the "Leave Posture" view, not the Approvals Desk.
    - **finance_officer**, **it_support**, **auditor** — read-only via `leave.request` for their own slice; payroll uses the data downstream but does not edit it here.
- **Related modules:** Employees (Ch 3) — every leave request hangs off an `employee_id`, and the employee detail page surfaces a Leave History tab with balance tiles and the latest eight requests; Attendance (Ch 5) — approved leave days are what stop an unapproved-absence flag firing; Payroll engine (Ch 19) — reads approved leave per pay period and flips Unpaid Leave days into a deduction; Notifications (Ch 16) — manager DM on submit, employee notification on decide; Reports & Analytics (Ch 31) — `leave.requested` and `leave.status_updated` events feed the absenteeism KPIs.

## The screens

![Employee Leave Posture — balance tiles, "My Requests" table, Apply CTA](../assets/screenshots/04_leave/employee_index.png)

*Callouts: ❶ "Leave Posture" header — masthead chip, plain-English subtitle that names the Labour Act 651 by section, and a cobalt "Request Leave" CTA top-right. · ❷ Horizontally scrolling balance strip — one card per configured leave type with a progress ring (used / total), the icon coloured by type (cobalt for Annual, amber for Sick, magenta for Maternity, cyan for Paternity/Study, red for Emergency, slate for Unpaid), big "days remaining" number, and a "used / total" footnote. The strip scrolls on small screens rather than wrapping, so the most-asked-for tiles stay above the fold. · ❸ "My Requests" table — type chip, start, end, working-days pill, truncated reason, status badge, and applied-on date. Empty-state below the table prompts the first application with a second "Apply for Leave" button.*

![Leave Approvals Desk — pending queue with Approve / Reject buttons](../assets/screenshots/04_leave/approvals_pending.png)

*Callouts: ❶ "Leave Approvals Desk" header — same masthead chip but the title and subtitle change to reflect statutory-leave responsibility under Act 651. · ❷ Three-tab bar — **Pending Approvals** (with a count badge), **All Requests**, **Leave Calendar**. The badge is the live count of `LeaveRequest::pending()` for the whole organisation (HR) or scoped via the policy for a dept head. · ❸ Each pending row carries the requester's avatar (gradient initials), employee number, type chip, date range, working-days pill, a "waiting" cell that flips amber after two days and red after five, and two row actions — green "Approve" and red "Reject", each gated by `leave.approve` on this specific request.*

![All Requests — filter strip + paginated history](../assets/screenshots/04_leave/approvals_all.png)

*Callouts: ❶ Five-control filter strip — Employee (the picker is populated only for users with `leave.approve` or `leave.manage`), Leave Type, Status, From, To. "Apply" and "Clear" both navigate via Inertia with `preserveState`. · ❷ Table — same rows as the pending queue but with the Status column included and the action set conditional: still-pending rows expose Approve/Reject inline, anything else only the View affordance. · ❸ Pagination — 20 per page, the same Pagination component used elsewhere; hidden when there is only one page of results.*

![Leave Calendar — month grid with leave chips and Ghana public holidays](../assets/screenshots/04_leave/calendar.png)

*Callouts: ❶ Month navigation — chevrons either side of the title, "Today" is rendered as a filled cobalt pip on the day number. · ❷ Seven-column Mon→Sun grid — every cell shows the day number, a holiday name in slate if `GH_HOLIDAYS` matches the date (eight statutory days seeded — New Year, Constitution, Independence, Workers', Founders', Nkrumah, Christmas, Boxing), and up to three first-name chips colour-keyed by leave type. A "+N more" overflow chip surfaces when the day has more than three approved leaves. · ❸ Click any cell to open the Day Detail slide panel — full list of who is off, what type, and any public holiday tag.*

![Apply for Leave — slide panel with type, dates, policy hint, computed days, reason, attachment](../assets/screenshots/04_leave/apply_panel.png)

*Callouts: ❶ Leave Type select — one option per `LeaveType` enum value, each labelled with the statutory cap in parentheses where one exists ("Annual Leave (up to 15 days)", "Maternity Leave (up to 84 days)" …). · ❷ Cobalt-tinted policy hint card below the select — re-states the statute in plain English for the chosen type (e.g. for Annual: "15 working days per year (Labour Act 651)"; for Sick: "Up to 14 days with medical certificate"). · ❸ Start / End date inputs constrained client-side to today-or-later, with a live "Working days requested" pill that subtracts weekends. · ❹ Reason textarea (required client-side, soft-required server-side — see notes). · ❺ Optional supporting document — accepts PDF, DOC, DOCX, JPG, JPEG, PNG, up to 5 MB. · ❻ Footer: Cancel + cobalt "Submit Request" with a spinner state.*

![Leave Request detail page — period card, reason, requester sidebar, timeline](../assets/screenshots/04_leave/detail.png)

*Callouts: ❶ Type chip with full-bleed icon well — the icon colour, glow, and tint are pulled from the same `TYPE_META` map as Index.vue so a maternity request looks the same magenta everywhere. · ❷ Period card — start, end, and a computed Working Days tile in a coloured chip; reason renders below with whitespace preserved. · ❸ Approver decision card — only renders once `status !== 'pending'`, in green for Approved, red for Rejected, with the approver's name and the exact decision timestamp. · ❹ Right column — requester avatar card, vertical Activity timeline (Requested → Awaiting Decision → Approved/Rejected) with colour-coded pins, and a Quick Links card with two anchors: back to the index, and through to the employee's profile.*

![Approve / Reject modal — centred dialog with comment field](../assets/screenshots/04_leave/decision_modal.png)

*Callouts: ❶ Centred 28 rem dialog — green check-circle icon well for an approval, red cancel icon well for a rejection. · ❷ One-line confirmation header that names the employee, the leave type, and the working-days count. · ❸ Optional comment textarea — placeholder copy switches between "Any notes for the employee…" and "Reason for rejection…" depending on the action. · ❹ Footer — Cancel + a confirm button whose colour and label match the action (cobalt-green gradient for Approve, red gradient for Reject).*

> The seven screenshot files referenced above will be captured in Wave 1 (task W1.20). Until they exist the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Leave Posture (employee / self-service view)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Request Leave** (cobalt CTA, top right) | Opens the right-hand "Apply for Leave" slide panel. Same button repeats inside the empty-state. | `leave.request` (everyone with a login by default) | Self-service is the headline action — it earns the prime real estate, and a second copy inside the empty state to short-circuit first-time use. |
| **Balance card** (per leave type) | Read-only — progress ring (used vs total), icon coloured by type, big "days remaining" number, "X used / Y total" footnote. Cards are horizontally scrollable on narrow viewports. | Anyone viewing | Surfaces the single number an employee asks for most ("how many days do I have left?") before they have to read anything. |
| **Row click on "My Requests"** | Opens the Request Detail slide panel for that row. | Owner of the request | Whole-row hit target — easier to tap on mobile than a small chevron. |
| **Withdraw this request** (Detail panel, red) | Soft-deletes a still-Pending request after a browser confirm. Routed as `DELETE /leave-requests/{id}` → `LeaveRequestPolicy::cancel`. Approved or Rejected requests do not offer this — they go through approval-reversal instead. | The requester themself (own request, status `pending`) or anyone with `leave.manage` | Lets people fix a mis-submission in one click without leaving the page; once a decision has been recorded the audit trail is sealed and reversal lives elsewhere. |
| **Pagination** (bottom of table) | Standard Inertia pagination — 20 per page; only renders when there is more than one page. | Anyone viewing | Keeps the table responsive when an employee has years of history. |

> *Notes:* The employee view is rendered as the "default" branch — `Pages/Leave/Index.vue` switches between the self-service layout and the Approvals Desk on `user.role`, with `hr_admin / super_admin / ceo` taking the HR branch. Department heads and managers stay on the self-service template and reach the approvals queue via deep-link or notification — the canonical Approvals Desk for them is the Pending tab when their role flips them into the HR template (see policy). The leave-type palette is shared between Index, Show, and the calendar via `TYPE_META` / `LEAVE_TYPES` so a Maternity row looks identical in every view.

### Apply for Leave (slide panel)

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Leave Type** | `required`, one of the seven `LeaveType` enum values (`annual`, `sick`, `maternity`, `paternity`, `unpaid`, `emergency`, `study`) | `leave.request` | Each option carries its statutory cap in the label ("up to 15 days" / "up to 84 days" / …) so the cap is visible before the request is filed. |
| **Policy hint card** | n/a — read-only | n/a | A tinted card that restates the rule for the selected type in plain English. The wording maps directly to Act 651: Annual = 15 working days; Sick = up to 14 with a medical certificate; Maternity = 12 weeks (84 days), 14 weeks for multiple births; Paternity = 5 working days; Emergency = up to 3; Study = "as approved by supervisor"; Unpaid = case-by-case, no pay. |
| **Start Date** | `required`, valid date, `after_or_equal:today` | `leave.request` | Client-side `min` is bound to today's ISO date so the date picker won't even offer the past; the server-side rule catches anyone who bypasses the UI. |
| **End Date** | `required`, valid date, `after_or_equal:start_date` | Same | The client-side `min` rebinds to whatever Start Date is set to so it tracks. |
| **Working days requested** | Computed, read-only | n/a | Subtracts weekends from the inclusive date range. Public holidays are not yet subtracted in the MVP — the calendar shows them but the requested-days pill does not honour them yet (see "What's planned next"). |
| **Reason** | `nullable`, string, max 2000 chars — but the UI marks the field `required` so the form won't submit without one | `leave.request` | The model is permissive (so a manager pre-filling a leave for someone over the phone isn't blocked) but the standard UI insists, because a leave history with empty reasons is operationally useless. |
| **Supporting Document** | optional; PDF / DOC / DOCX / JPG / JPEG / PNG; ≤ 5 MB | `leave.request` | The MVP captures the upload widget but the storage hookup for leave attachments is delivered in the same Phase 2 wave as the leave-balance seeding work — until then the file is dropped after submit. The field is in the UI now to lock the user-facing contract. |
| **Submit Request** (cobalt CTA, panel footer) | POSTs to `/leave-requests`. On success the panel closes, the form resets, a "Leave request submitted successfully." toast fires, and the page reloads with the new row at the top. | `leave.request` | Spinner state on `leaveForm.processing` so double-clicks don't double-submit. |
| **Cancel** | Closes the panel without firing anything. | Anyone | Standard escape. |

> *Notes:* `StoreLeaveRequest::authorize()` is the gate — anyone without `leave.request` gets a 403 before any field is checked. The controller calls `LeaveService::request()` which inserts the row in `LeaveRequest::create($validated)` and immediately dispatches `LeaveRequested(leave, actor)` — three listeners react (see "How it talks to other modules" below). The request lands in `pending` status by default because the migration sets `status('default', 'pending')`.

### Approvals Desk — Pending Approvals tab

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Tab — Pending Approvals** | Filters the table to `status === 'pending'`. The tab label carries a count badge fed by `LeaveRequest::pending()->count()` on the controller. | `leave.approve` or `leave.manage` (HR / dept heads) | Default tab on landing — pending work is what an approver opened the page for. |
| **Approve** (green button per row) | Opens the decision modal pre-set to `approved`. | `leave.approve` on this request (i.e. HR with `leave.manage`, **or** the manager of the requesting employee's department) | Two-step confirmation prevents accidental click-throughs; the optional comment becomes the audit reason. |
| **Reject** (red button per row) | Opens the decision modal pre-set to `rejected`. | Same RBAC pair | Same confirmation flow; the comment placeholder switches to "Reason for rejection…". |
| **Waiting cell** | Days since the request was submitted, in monospaced text. Goes amber at two days, red at five. | Anyone viewing | A visual nudge to clear the backlog — backed only by `created_at`, so it costs nothing. |
| **Avatar** | Initials in a cool-family gradient seeded by a stable hash of the name, plus an outline ring. Hover scales it 5%. | n/a | The same avatar treatment used on the Employees directory, so faces are recognisable across modules. |
| **Empty state** | "No pending requests · All leave requests have been actioned." with a `task_alt` glyph. | Anyone viewing | Honest empty state — celebrates "inbox zero" without inventing data. |

### Approvals Desk — All Requests tab

| Field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Employee filter** | Drop-down populated only when the viewer has `leave.approve` or `leave.manage`. Self-service users get an empty array and so the picker disappears. | `leave.approve` / `leave.manage` | Department heads and HR need to find one person's history fast — search-by-name is the most-used filter in this module. |
| **Leave Type filter** | Drop-down of the seven `LeaveType` enum values. | Same | "Show me every maternity leave this year" is a real recurring question. |
| **Status filter** | `pending` / `approved` / `rejected` (the `LeaveStatus` enum is three-valued in the MVP — see "What's planned next" for the cancelled/expired states that arrive with bidirectional approval routing). | Same | Status is the second most-used filter. |
| **From / To** | ISO date inputs. From → `start_date >= v`; To → `end_date <= v`. | Same | Lets HR scope the table to a quarter without paginating through history. |
| **Apply** | Re-issues the Inertia GET with the chosen filters as query string; `preserveState: true` so scroll position is kept. | Same | One round-trip, server-side filtering — the JS never trims a list it's been handed. |
| **Clear** | Resets every filter and re-queries with no params. | Same | One-click undo. |
| **Approve / Reject** (per row, conditional) | Same flow as the pending tab — but only renders on rows with `status === 'pending'`. Approved or rejected rows show only the "View" button. | `leave.approve` on that row | Keeps the action density low on the historical view. |
| **View** | Opens the Request Detail slide panel; same panel as the row-click on the employee view. | Anyone with view-permission on that row | Quick read without leaving the table. |
| **Pagination** | 20 per page; hidden when there is only one page. | Anyone viewing | Same as everywhere else. |

### Approvals Desk — Leave Calendar tab

| Control | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Month chevrons** | Move the calendar one month back / forward. The header `MMMM YYYY` updates in step. | Anyone with calendar access | A 12-month picker is overkill for the day-to-day question of "who is out this month?". |
| **Day cell click** | Opens the Day Detail slide panel — list of every employee on approved leave on that day, plus the holiday name if `GH_HOLIDAYS` matches. | Anyone with calendar access | Faster than scanning the chips inside the cell when the day is busy. |
| **Today indicator** | The day number on `new Date().toISOString().slice(0,10)` renders as a filled cobalt pip. | n/a | Standard "you are here" affordance — keeps the eye on the right cell when you land on the tab mid-month. |
| **Holiday pip + name** | The 8 seeded Ghanaian statutory holidays (New Year, Constitution Day, Independence, Workers', Founders', Kwame Nkrumah, Christmas, Boxing) render with a slate-grey pip and the holiday name beneath. | n/a | Sets expectation that no leave should be approved on these days — and helps the requester see at-a-glance why a chosen date might not need a leave entry at all. |
| **Leave chips** | Up to three first-name chips per cell, colour-coded by leave type. `+N more` chip appears when the day has more than three approved leaves. | Anyone with calendar access | High signal at low cost — a glance is enough to spot a department-wide collision. |
| **Legend** | Bottom of the calendar — five type chips and the public-holiday pip. | n/a | The chips are colour-only on small screens; the legend is the accessibility floor. |

> *Notes:* The calendar pulls its data from the same paginated `leaves` collection the table uses — it does not make a second request. That means it shows leaves from the **current page only**, which on a well-populated institute can hide entries that span across page boundaries. The fix (a dedicated lightweight calendar endpoint) is on the Phase 2 list — see "What's planned next". The Ghana holiday map is hard-coded in the MVP; live integration with the official Public Holidays Calendar arrives with the Year-End Closeout pack in Chapter 31.

### Decision modal (Approve / Reject)

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Status** | `required`, one of the `LeaveStatus` enum values (`pending`, `approved`, `rejected`) | `leave.approve` on this row | The modal sends `approved` or `rejected` only — `pending` is the initial state, not a destination. |
| **Comment** | optional, free string | Same | The placeholder text switches between "Any notes for the employee…" and "Reason for rejection…" depending on `actionType`. The MVP forwards the comment in the PATCH body but does not yet persist it (a `leave_request_history` table arrives with the bidirectional routing — see "What's planned next"). |
| **Confirm Approval / Confirm Rejection** (gradient CTA) | PATCHes `/leave-requests/{id}` with `{ status, comment }`. On success: refreshes the page, fires the toast, decrements the pending badge. | `leave.approve` on this row | Spinner state on `actionLoading`; the button gradient flips green / red to match the action. |
| **Cancel** | Closes the modal without firing anything. | Anyone | Standard escape. |

> *Notes:* Approval is wrapped in a database transaction (`LeaveService::updateStatus`). Inside that transaction the service writes back `status` and `approved_by` on the request, then row-locks (`lockForUpdate`) the `LeaveBalance` for `(employee_id, type, year)` and increments `used_days` by the request's `durationInDays()`. A new balance row seeds at 21.0 days if one doesn't exist — that figure is a holding default that will be replaced by a per-grade, per-type entitlement schedule when Positions/Grades go live (see Chapter 19's establishment column on the Phase 1 work). The lock prevents two managers approving overlapping requests from oversubscribing the balance.

### Request Detail page (`/leave-requests/{id}`)

| Element | What it does | Who can see it | Why it exists |
|---|---|---|---|
| **Breadcrumb** ("Leave Management → Request #N") | Navigates back to the index. | Anyone with view on this request | Standard wayfinding. |
| **Type chip + icon well** | Read-only. The chip carries the type label and the icon glow tinted from `TYPE_META`. | Anyone with view | Identity at a glance — the same visual language used in the table and the calendar. |
| **Status badge** | The `LeaveStatus` enum rendered through `StatusBadge` — amber for pending, green for approved, red for rejected. | Anyone with view | Single source of truth for the workflow state, reused from the directory. |
| **Period card** | Start and end dates rendered twice — short (`08 Jun 2026`) and long (`Monday, 08 June 2026`) — plus a coloured Working Days tile keyed off the request type. | Anyone with view | A leave conversation is mostly about dates — they deserve a full card. |
| **Reason card** | The request reason with whitespace preserved (`whitespace-pre-line`). Empty-state copy is "No reason provided." in italics. | Anyone with view | Faithful to the submission — line breaks the requester typed survive the round-trip. |
| **Approver decision card** | Renders only when `status !== 'pending'` and an `approver` is loaded. Green-tinted for Approved, red-tinted for Rejected. Shows the approver's name and the decision timestamp to the minute. | Anyone with view | A single, unambiguous "who decided, when" — the auditor's question. |
| **Requester card** (right column) | Avatar (same gradient palette), name, employee number, position. | Anyone with view | Identity of the requester without leaving the page. |
| **Activity timeline** | Two-step vertical timeline — "Leave Requested" (cobalt pin, requester + submission time) → either "Approved" (green) / "Rejected" (red) / dashed "Awaiting Decision" (amber). | Anyone with view | Approval as a story, not as a status string. |
| **Quick Links card** | Two anchors — "All leave requests" (back to `/leave-requests`) and "View employee profile" (to `/employees/{id}`). | Anyone with view | The two most common follow-up questions, one click away. |
| **Approve / Reject buttons** (top-right, conditional) | Same decision modal as the Approvals Desk. Visible only when `isHR` and `status === 'pending'`. | `leave.approve` on this row | Lets the decision be made from either the queue or the detail page — both flows land the same PATCH. |

## The data behind it

CIHRMS keeps two tables under this module, both pinned to the `employees` row:

- **`leave_requests`** — one row per application. The columns: `employee_id` (the requester), `approved_by` (the deciding user, nullable until the request is approved), `start_date`, `end_date`, `type` (cast to `LeaveType`), `reason` (text, nullable, up to 2000 chars), `status` (cast to `LeaveStatus`, defaults to `pending`), the standard `timestamps()`, and `softDeletes()` for withdrawals. The `employee_id` is a cascade-on-delete foreign key, so removing an employee removes their requests — the audit trail relies on soft delete plus the analytics events, not on the row itself.
- **`leave_balances`** — one row per `(employee_id, type, year)` triple, with a unique compound index that enforces that constraint at the database level. The columns: `total_days` (decimal 5,1, defaults to 0) and `used_days` (decimal 5,1, defaults to 0). The model exposes `remainingDays()` as a computed accessor — it does not store the remaining figure, so it can never disagree with itself.

What every reader of the screen needs to keep in mind:

- **`durationInDays()`** is the inclusive day count between `start_date` and `end_date` — a Monday-to-Friday range counts as five days, not four. The Apply panel's "Working days requested" pill, on the other hand, **subtracts weekends client-side** before the request is sent. That gap is intentional in the MVP — the server stores the calendar-day count so the simplest possible joins with payroll work; the UI shows the working-day count because that is the number the employee actually cares about. The Phase 2 Attendance integration (see Chapter 5) reconciles the two.
- **Public holidays are recognised by the calendar but not by the engine.** The hard-coded `GH_HOLIDAYS` map gives the calendar its holiday pips, but neither `durationInDays()` nor the "Working days requested" pill subtracts them. The fix is in the same Phase 2 wave that introduces the official Public Holidays Calendar.
- **Balance seeding is a 21-day placeholder.** Until a balance row exists, the system invents one at 21.0 total days on first approval. The Positions/Grades work in Phase 1 (Chapter 31's establishment schedule) is what turns that into a per-grade, per-type entitlement read from the establishment table — see "What's planned next".
- **Approval is transactional.** `LeaveService::updateStatus` opens a `DB::transaction`, writes the request, row-locks the balance with `lockForUpdate()`, and increments `used_days`. Two managers approving overlapping requests at the same instant cannot oversubscribe the balance — the second one waits at the lock.
- **Soft delete means "withdrawn".** A row removed via `DELETE /leave-requests/{id}` is `deleted_at`-stamped, not erased. Restoring is a console action. Approved / rejected requests cannot be withdrawn at all — `LeaveRequestPolicy::cancel` returns `false` for any non-pending status.

## How it talks to other modules

- **`LeaveRequested` event** → fired after every successful submission. Two listeners pick it up: `RecordAnalyticsEvent` writes a `leave.requested` analytics row (consumed by Chapter 31); `NotifyManagerOfLeaveRequest` (queued on the `integrations` queue) DMs the requester's line manager across whichever channels they have configured — Slack DM, Teams card, WhatsApp template — and broadcasts to the configured HR Slack/Teams channel when the `slack_leave_approvals` feature flag is on.
- **`LeaveStatusUpdated` event** → fired after every approval or rejection. Three listeners react: `RecordAnalyticsEvent` writes `leave.status_updated` with the new status; `SendNotifications` notifies the requesting employee via Laravel's notification system (`LeaveStatusChanged` mail / database notification); the same messaging dispatcher used on submission can be wired for cross-channel decision broadcasts in Phase 2.
- **Employee (Ch 3)** is the parent — every leave request belongs to exactly one employee (`cascadeOnDelete`), and the employee detail page's Leave History tab loads the latest eight requests plus the four configured balance tiles via the same `LeaveBalance::where('employee_id', $id)->where('year', now()->year)` query the index uses.
- **Attendance (Ch 5)** consumes approved leave as the explanation for an otherwise-flagged absence — the unapproved-absence rule looks at whether the day is inside an approved `LeaveRequest` before raising a flag.
- **Payroll engine (Ch 19)** reads approved leave per pay period — Annual / Sick / Maternity / Paternity / Emergency / Study are all paid (the engine simply counts attendance days minus paid leave); `Unpaid` is converted into a per-day deduction at the employee's daily rate.
- **Notifications (Ch 16)** is the channel — the `MessagingDispatcher` and Laravel's built-in notification stack route every leave-status change and leave-request submission through the user's preferred mix (in-app, email, Slack DM, Teams card, WhatsApp template).
- **Reports & Analytics (Ch 31)** consumes the two events to compute the ISO 30414 absenteeism KPIs, the average days-pending-approval, the approval-rate-by-manager, and the top-of-funnel leave-mix-by-type donut on the HR dashboard.

## Standards touchpoints

- **ISO 30414 §4.5 (Productivity — Absenteeism)** — the absenteeism rate (Σ leave days / Σ scheduled days), broken down by leave type and by reason category, is one of the disclosures named by the standard. CIHRMS captures every leave application with its type and dates, which is what makes that calculation possible. The institute-wide rollup happens in Chapter 31's analytics export. See Chapter 44.
- **Ghana Labour Act, 2003 (Act 651) §20 — Annual Leave** — the Apply panel pre-states "15 working days per year" against the Annual Leave option; this is the statutory floor for any worker who has completed twelve months of continuous service. The 21-day balance seed in `LeaveService::updateStatus` is a temporary placeholder used until Positions/Grades go live with per-grade entitlements (which can be higher than the statutory floor for senior grades). See Chapter 44.
- **Ghana Labour Act, 2003 (Act 651) §24 — Sick Leave** — the panel reflects "Up to 14 days with medical certificate". The Supporting Document upload exists specifically so a medical certificate can be attached; the controller accepts it but the MVP's storage hookup ships with the same Phase 2 balance-seeding wave. See Chapter 44.
- **Ghana Labour Act, 2003 (Act 651) §57 — Maternity Leave** — the panel pre-states "12 weeks (84 days); 14 weeks for multiple births". The `Maternity` leave type maps to 84 calendar days in the policy hint; the multiple-births uplift is captured manually on the Reason field today and becomes a structured input in Phase 2. See Chapter 44.
- **Ghana Labour Act, 2003 (Act 651) — Paternity Leave** — paternity leave is not a statutory entitlement under Act 651 itself but is a common collective-bargaining-agreement add-on; CIHRMS recognises it as a discrete leave type and caps it at 5 working days in the policy hint, which is the institute's bargained standard. See Chapter 44.
- **Data Protection Act, 2012 (Act 843) §18 — Data Minimisation** — sick-leave reasons and any uploaded medical certificate are sensitive personal data. The MVP keeps the Reason field optional at the DB layer (so the requester can choose how much to disclose) and surfaces the request only to the requester, their manager, and HR — never on a department-wide list. The Supporting Document, when stored, will live under the same `storage/app/public/employee-documents/` tree as the Employees module and inherit the same access rules. See Chapter 44.
- **Data Protection Act §17 — Lawful Basis** — processing of leave-related personal data sits on the employment-contract basis; CIHRMS does not require additional consent for an employee to log a sick day. The right-to-erasure (Act 843 §40) path is the same soft-delete + redaction-job pattern used on the Employees module — withdrawing a leave request soft-deletes the row; full erasure for a departed employee is handled by the dedicated redaction job, not by clicking Withdraw. See Chapter 44.
- **National Pensions Act, 2008 (Act 766)** — Maternity leave is paid leave under Act 651, and remains pensionable; the Payroll engine (Chapter 19) treats Maternity days the same as worked days for SSNIT and Tier-2 contribution purposes. Unpaid Leave days do not accrue contributions. See Chapter 44.

## What's planned next

Phase 2 of the government-grade roadmap lands four things directly on this screen: (1) per-grade, per-type entitlement schedules sourced from Positions/Grades replace the 21-day placeholder in `LeaveService::updateStatus`, so a Grade 12 officer sees their bargained 22 days where a probationer sees the statutory 15; (2) a dedicated `leave_request_history` table records every decision with the comment string captured by the modal today, so the audit pack can reconstruct the full timeline; (3) the calendar moves to a dedicated lightweight endpoint and starts honouring the official Ghana Public Holidays Calendar, with both the engine and the "Working days requested" pill subtracting public holidays from the count; and (4) the Attendance integration in Chapter 5 begins reconciling biometric clock data with approved leaves so the unapproved-absence flag fires reliably across the institute — closing the last gap between "leave applied" and "leave actually taken".
