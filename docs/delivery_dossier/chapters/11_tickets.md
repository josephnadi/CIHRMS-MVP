# Chapter 11 — Tickets (Service Desk)

> *In one paragraph.* Tickets is the institute's internal service desk — the single front door for any request, fault, or incident that someone in the workforce needs another team to act on. An employee opens a ticket against IT, HR, facilities, or any other function; an IT-support agent (the primary handler in the MVP) triages it, assigns an owner, moves it through the open → in-progress → resolved → closed lifecycle, and the requester gets a notification the moment a terminal state is reached. Every record is pinned to an Employee (Chapter 3), every change writes a notification (Chapter 16), and every create-event feeds the institute analytics warehouse (Chapter 31) — so the same dashboard that shows leave throughput also shows desk throughput.

## Where to find it

- **Sidebar location:** **Workplace** group → **Service Desk** (top-level item, `support_agent` icon). The same destination is pinned in the app switcher (the 3×3 grid in the top bar) as **Tickets**, and on the Quick Actions sheet as **Open ticket** — the Quick Actions entry routes to `/tickets?new=1` so the create panel pops on arrival, then strips the flag from the URL so a browser refresh doesn't re-trigger it.
- **Roles that see it:**
    - **super_admin** and **ceo** — every ticket, every action. The `TicketPolicy::before` short-circuit grants super-admin a blanket pass; CEO permissions are seeded with both `tickets.create` and `tickets.manage`.
    - **it_support** — the primary handler. Holds `tickets.create` + `tickets.manage`; sees every ticket on the desk and is the default population on the "Assigned To" dropdown.
    - **hr_admin** — also seeded with `tickets.create` + `tickets.manage` so HR can run an HR-side desk for personnel queries that aren't IT-flavoured.
    - **manager** — also seeded with `tickets.create` + `tickets.manage`, intended for line managers who triage their own team's queue. They see every ticket the policy allows them to see; the assignee dropdown is restricted to the support-staff pool.
    - **dept_head** — seeded with the same `tickets.create` + `tickets.manage` pair so department heads can run a divisional desk.
    - **employee** — `tickets.create` only. Can open a ticket and see the ones they raised or that are assigned to them; they cannot reassign, change status, change priority, or delete.
    - **finance_officer**, **auditor**, **complaints_officer**, **recruiter**, **trainer** — all seeded with `tickets.create` only, so any role can raise a request against the desk.
- **Related modules:** Employees (Ch 3) — every ticket belongs to an `employee_id` and renders the requester's name and employee number; Notifications (Ch 16) — the channel for assignment and resolution pings; Reports & Analytics (Ch 31) — receives the `ticket.created` analytics event for every successful create; Audit Logs (Ch 24) — Phase 2 will write the create/update/delete trail here; Standards (Ch 44) — ITIL-aligned status taxonomy plus ISO/IEC 27001 Annex A.16 coverage notes; Roadmap (Ch 46) — SLA breach automation and a category taxonomy land in Phase 1.

## The screens

![Service Desk — Kanban board with swimlanes, quick filters, and a labeled-row card layout](../assets/screenshots/11_tickets/board.png)

*Callouts: ❶ Editorial-Sovereign masthead — Service Desk title, requests-in-flight subhead, and the Board / List view toggle plus the cobalt "New Ticket" CTA. The view choice persists in `localStorage` under `cihrms.tickets.view` (default: board) so the user lands wherever they left. · ❷ Jira-style toolbar — assignee chip (default narrows to "me"), quick-filter pills (All · Mine · Unassigned · Overdue · Critical) with live counts, a "/" hotkey-focused search box, group-by switcher (None · Priority · Assignee — the swimlane signature), and a Comfortable / Compact density toggle. All toolbar prefs except the search itself persist under `cihrms.tickets.boardPrefs`. · ❸ Filters strip — server-side filters (Status · Priority · Assigned-to · Overdue-only) that re-fire the Inertia request through `TicketService::list()`. · ❹ Four-column Kanban — Open · In Progress · Resolved · Closed. Each card carries a status pill, a priority pill, an issue key (`SD-NNN`), an assignee avatar stack with an "+ add" affordance, a due-date row with red overdue marker, and an age "freshness" pip (fresh → active → aging → stale → cold). Cards are drag-droppable only for users who can move them — the backend policy is the source of truth.*

![Ticket detail — description, update panel, activity timeline, and meta sidebar](../assets/screenshots/11_tickets/detail.png)

*Callouts: ❶ Breadcrumb and headline — back-link to Tickets, the ticket id, the title as the page heading, and "opened X days ago" in muted type. · ❷ Description card with a 5% gold hairline accent on the top edge — the body of the ticket as the requester wrote it. · ❸ "Update Ticket" panel (manager-only) — two selects (Status · Assigned To) and a single cobalt "Save Changes" button. PATCHes `/tickets/{id}` with whatever has changed. · ❹ Activity timeline — vertical rail with three entries today: Ticket opened, Assigned to X, and Resolved at HH:MM (the third only shows when `resolved_at` is set). · ❺ Right sidebar — Status · Priority · Requester · Assigned To · Due Date (overdue marker if past due) · Created — six labelled rows that mirror the database columns one-to-one.*

![New Ticket — slide panel with title, description, priority, and due date](../assets/screenshots/11_tickets/create.png)

*Callouts: ❶ Title (required, max 255 chars). · ❷ Description (required, max 5000 chars, multi-line textarea). · ❸ Priority select — Low · Medium · High · Critical, defaulting to Medium. · ❹ Due Date — optional `datetime-local` input; the FormRequest enforces `after:now`, so today-or-past dates are rejected client- and server-side. · ❺ "Submit Ticket" CTA (cobalt gradient with shimmer); cancel returns to the board without firing anything. On success the panel closes, the requester sees a green "Ticket created successfully" toast, and the new card lands in the Open column of the board.*

> The three screenshot files referenced above will be captured in Wave 1 (task W1.27). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Service Desk (Board view)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **New Ticket** (cobalt CTA, top right) | Opens the right-hand slide panel for creating a ticket. | `tickets.create` — every authenticated role holds this in the seed. | One-tap raise — the primary verb on the page. |
| **Board / List** toggle | Switches between the Kanban board (default) and a table view. Choice persists per browser in `localStorage` under `cihrms.tickets.view`. | Anyone who can see the page | Triage agents live on the board; auditors and reporters prefer the table. Both views read the same paginated list. |
| **Assignee chip** (left of the toolbar) | A circular profile chip; click to open a popover with a "Show only mine" shortcut and a search box that hunts `props.staff`. Selecting a person narrows the board to their tickets, client-side. The "x" inside the chip clears the filter. | Anyone who can see the page | The most-asked question on a busy board is "what's on my plate?" — the chip answers it in one click. |
| **Quick-filter pills** (All · Mine · Unassigned · Overdue · Critical) | Each pill applies a client-side filter on top of the server's paged data, with a live count badge. Overdue and Critical take warning tones (red / amber). | Anyone who can see the page | The five filters cover ~95% of triage views without a round-trip. |
| **Search box** ("Search board…") with "/" hotkey | Free-text search across title and description; debounced 380 ms after you stop typing. The "/" key focuses it from anywhere on the page (Jira-like). | Anyone who can see the page | Cross-page search — the server query is `LOWER(title) LIKE %q%` plus `LOWER(description) LIKE %q%`, cross-database safe (SQLite, MySQL, Postgres). |
| **Group by** (None · Priority · Assignee) | Splits the board into horizontal swimlanes by the chosen dimension. "Priority" orders critical → high → medium → low; "Assignee" buckets by user with "Unassigned" pinned at the bottom. | Anyone who can see the page | The swimlane is the signature board feature — it makes "who's overloaded?" and "what's burning?" answerable at a glance. |
| **Density** (Comfortable · Compact) | Comfortable shows full cards with description, due date, and age pip. Compact crops to one-line titles and tightens vertical spacing — more cards per screen. | Anyone who can see the page | Compact is for the agent running a 30-ticket queue; comfortable for the team-lead doing a weekly review. |
| **Filters strip** (Status · Priority · Assigned-to · Overdue-only) | Server-side filters that re-fire the Inertia GET with the chosen combination; "Clear" wipes everything and re-queries. The "Assigned-to" dropdown only renders for users with `tickets.manage`. | Anyone who can see the page; "Assigned-to" requires `tickets.manage` | Server filters trim what's paginated; the toolbar's client filters narrow the *visible* slice. The two layer together — server first, client second. |
| **Card drag (open → in_progress → resolved → closed)** | Optimistic move on the Kanban — fires a PATCH with the new `status` (keeping the current assignee) and reloads the column on success. The `draggable` flag is per-card: a user can only move a card when they hold `tickets.manage` or are the assignee. | `tickets.manage` or the card's current assignee | Drag is the fastest possible transition. The per-card flag keeps the UI honest — non-managers see locked cards instead of getting a 403 mid-drag. |
| **Card click** | Opens the right-hand detail drawer (`drawerTicket`) without leaving the board. The drawer carries the same inline status / priority / assignee editors as the page, plus an "Open full view" link to the detail page. | Anyone who can view the ticket | Triage-on-the-board pattern — keep the agent in the queue, surface enough context to act, escalate to the full page only when needed. |
| **Resolve** (drawer footer) | One-click shortcut that PATCHes `status: resolved` and closes the drawer. | `tickets.manage` | The single most-used transition; the drawer rewards it with a green button in the footer. |
| **Escape** anywhere on the board | Closes the drawer. | Anyone | Standard keyboard escape. |
| **"+" on a column header** | Opens the New Ticket panel. The column id is *not* passed — new tickets always land in Open, by server default. | `tickets.create` | Single source of truth for "where does a new ticket start". |

> *Notes:* The board reads `props.tickets.data` directly — there is no client-side dataset. Quick-filter and assignee-filter pass on top of the same paged array, so the displayed counts always match what's reachable. The keyboard handler is bound on mount and torn down on `onBeforeUnmount`, so navigating away does not leak a global keydown listener. The detail drawer uses the same priority-rail palette as the cards (`#dc2626` critical, `#ea580c` high, `#d97706` medium, `#12d9e3` low), so the eye stays calibrated when jumping between views.

### Service Desk (List view)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Row click** (anywhere on the body) | Navigates to the ticket detail page. | Anyone with view on that ticket | Whole-row hit target — same pattern as the Employee directory. |
| **View** (eye icon) | Same destination as the row click — explicit affordance for screen readers and keyboard users. | Same | Accessibility — a `<tr>` isn't naturally focusable, this button is. |
| **Priority pill** (clickable) | Opens an inline menu of the four priorities. Choosing one fires a partial PATCH carrying only `priority` — `status` and `assigned_to` are left untouched. | `tickets.manage` (the policy denies the PATCH otherwise; the UI shows the menu to everyone for consistency, the backend is the gate) | Priority changes faster than any other field on a desk; the pill makes it one click. |
| **Assigned-to select** (per row) | Dropdown of every support-staff user (`it_support`, `hr_admin`, `super_admin`, `ceo`, `manager`). Choosing a name fires a PATCH carrying `status` (unchanged) and `assigned_to`. Picking "Unassigned" sends `null`, which the FormRequest accepts explicitly. | `tickets.manage` only — the cell renders a static name for everyone else | Re-assignment is the second-most-common edit; doing it from the row is faster than opening the ticket. |
| **Mark resolved** (green check, row action) | One-click PATCH that flips status to Resolved. Hidden when the ticket is already resolved or closed. | `tickets.manage` | The fast-path for "this is done" — duplicates the drawer's Resolve button. |
| **Delete** (red trash, row action) | Opens a confirmation dialog. On confirm, soft-deletes the ticket (`SoftDeletes` trait — the row stays in the DB and can be restored from the console). | `tickets.manage` | Same one-way safeguard as Employee delete. Soft-delete keeps the audit trail. |
| **Pagination** (bottom-right) | Standard Inertia pagination — 20 per page (overridable via `per_page` query param), with "Showing X–Y of Z" on the left. | Anyone who can see the table | Keeps long lists responsive. |

> *Notes:* The "support staff" dropdown is server-built in `TicketController::supportStaff()` as a `whereIn('role', ['it_support', 'hr_admin', 'super_admin', 'ceo', 'manager'])` query — five role slugs. It is the same list that powers the assignee dropdown on the detail page and the drawer. The list view's inline priority menu closes on any outside click; the listener is bound on mount and removed on `onBeforeUnmount` so it never leaks across navigations.

### Ticket detail page

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Back** (top left) | Returns to `/tickets`. | Anyone viewing | Standard back affordance — Inertia preserves the board's scroll position. |
| **Delete** (top right, red) | Confirmation dialog → DELETE `/tickets/{id}`. Redirects to the index after success. | `tickets.manage` | Same soft-delete pathway as the list view's row action. |
| **Description card** | Read-only display of the ticket body, preserving line breaks. | Anyone with view | The actual problem statement — the most-read region on the page. |
| **Update Ticket panel** — Status select | Open · In Progress · Resolved · Closed. The choice is staged in local state; the request only fires on Save Changes. | `tickets.manage` | Two-field combined edit — status and assignee are the two fields that change most often together (e.g., assigning to someone *and* moving to In Progress). |
| **Update Ticket panel** — Assigned To select | Same support-staff list as the index. Includes an "Unassigned" option that sends explicit `null`. | `tickets.manage` | See above. |
| **Save Changes** (cobalt CTA) | PATCH to `/tickets/{id}` with `status` and `assigned_to`. The service then writes `resolved_at = now()` if the new status is Resolved, and dispatches a `TicketResolved` notification to the requester if either Resolved or Closed is reached for the first time. | `tickets.manage` | One write, two side-effects — see the service notes below. |
| **Activity timeline** | Vertical rail with one entry for "Ticket opened" (always shown), one for "Assigned to X" (when an assignee is set), one for "Resolved" (when `resolved_at` is set). Each entry shows the timestamp where available. | Anyone with view | The minimum-viable audit story for the MVP. A full keystroke-by-keystroke timeline lands in Phase 1 when the audit log integration is wired in. |
| **Sidebar — Status / Priority / Requester / Assigned To / Due Date / Created** | Six labelled rows that mirror the database columns. Overdue due-dates render in red with a "schedule" icon and an "Overdue" tag. | Anyone with view | Single-glance summary — the same six facts the list view shows, surfaced as a pinned card. |

> *Notes:* The detail page hydrates from one Inertia render — `TicketController::show` returns the ticket with `employee.user` and `assignedTo` eager-loaded, plus the same support-staff list used everywhere else. The "manager-only" Update panel hides cleanly for employees — they still see the description, the timeline, and the sidebar, just without the edit affordances. The `is_overdue` flag is computed at the resource layer via `Ticket::isOverdue()` — overdue means "due date in the past AND not Resolved AND not Closed", so a late-resolved ticket stops flashing red the moment it lands.

### Create form (Open New Ticket slide panel)

| Field | Validation | Who can set it | Notes |
|---|---|---|---|
| **Title** | required, string, max 255 chars | `tickets.create` | A short headline — the line that shows on the card and in every list. |
| **Description** | required, string, max 5000 chars | Same | Free-form body. Markdown rendering is not parsed in the MVP — line breaks are preserved with `whitespace-pre-line` and links remain plain text. |
| **Priority** | required, must be one of the `TicketPriority` enum (`low`, `medium`, `high`, `critical`) | Same | Defaults to Medium in the UI. The enum is the source of truth — adding a new priority is a one-file change. |
| **Due Date** | optional, valid date, **must be after now** (`after:now` rule on the FormRequest) | Same | Past dates and "now" are rejected — past-due-on-arrival doesn't make sense for a new ticket. Optional because not every request has a deadline. |
| **Employee** (`employee_id`) — not shown in the UI | optional; if absent, the service auto-fills with the signed-in user's `employee_id` | Same | The MVP always opens tickets against the current user. The API accepts an explicit `employee_id` for future "raise on behalf of" flows that will surface in Phase 1's category work. |

> *Notes:* The Add panel does not collect `assigned_to` — assignment happens after creation, on the board or detail page. New tickets always land in Open by server default (the migration sets `status` default to `open` and the model doesn't override it on create). On success the service fires `TicketCreated($ticket, $request->user())` before returning, and the panel closes with a "Ticket created successfully" toast.

### Update transitions (board drag, drawer, detail, inline pills)

| Field | Validation | Who can change it | Notes |
|---|---|---|---|
| **Status** | `sometimes`, must be one of the `TicketStatus` enum (`open`, `in_progress`, `resolved`, `closed`) | `tickets.manage` | Transitioning to Resolved sets `resolved_at = now()` on the same write. Transitioning *away* from a terminal state does not clear `resolved_at` — the column is the historical timestamp, not a live flag. |
| **Priority** | `sometimes`, must be one of the `TicketPriority` enum | Same | Changeable at any point in the lifecycle — including after Resolved/Closed, so an auditor can re-classify a ticket post-mortem. |
| **Assigned To** | `sometimes`, `nullable`, must exist on `users` (any active user — not just support staff) | Same | The UI restricts the dropdown to the five support roles, but the API accepts any user id. Sending explicit `null` unassigns. |
| **(At least one of)** | `withValidator`'s `after()` rule adds an error if no status, priority, or assigned_to is supplied | Same | Empty PATCHes are rejected with a clear "At least one of status, priority, or assigned_to must be supplied." validation error — prevents accidental no-ops from the inline editors. |

> *Notes:* `UpdateTicketStatusRequest::authorize()` checks `tickets.manage` *only*. The board card's draggable flag also honours "is the assignee" as a relaxation, which the policy backs up via `TicketPolicy::update`. That double-gate is intentional — the assignee can move their own ticket through the lifecycle (`open → in_progress → resolved`) without needing the full management permission, but they cannot reassign it to someone else (the assignment field is `tickets.manage`-only). The service-layer `updateStatus()` carefully uses `has('assigned_to')` not `filled('assigned_to')` so that an explicit `null` unassign is honoured rather than ignored.

## The data behind it

CIHRMS stores a deliberately small set of fields per ticket — the MVP optimises for a fast triage loop, not a full ITSM record:

- **Lifecycle columns** — `id` (auto-increment), `title` (string, max 255), `description` (text, max 5000), `priority` (string-backed `TicketPriority` enum), `status` (string-backed `TicketStatus` enum, defaulting to `open`), `due_at` (nullable datetime), `resolved_at` (nullable datetime, set only when status transitions into Resolved), and standard `created_at` / `updated_at` timestamps plus a soft-delete `deleted_at`.
- **Identity columns** — `employee_id` is the requester (foreign key to `employees`, `nullOnDelete` so deleting an employee soft-orphans rather than cascade-deletes their tickets); `assigned_to` is the owner (foreign key to `users`, nullable — "Unassigned" is a valid state).

The Ticket row joins out to **one** `Employee` (the requester, with their `user` eager-loaded for name/email), and **one** `User` (the assignee). There is no separate `categories` table in the MVP — Phase 1's category work will introduce one and migrate priority away from the single-pill "Critical" semantic into a fuller (category × impact × urgency) matrix.

What every reader of the screen needs to keep in mind:

- **Soft delete** means "removed from every list, recoverable in the console". `Ticket` uses Laravel's `SoftDeletes` trait, so deletes set `deleted_at` rather than removing the row. Right-to-erasure requests follow the same redaction-job pathway as Employees.
- **`resolved_at` is one-way in the UI**. The service sets it the first time the ticket reaches Resolved; subsequent transitions back to Open or In Progress leave the timestamp in place. That is intentional — the column is the audit fact "when was this first resolved?", not "is it resolved right now?". The current state is in `status`.
- **`assigned_to` is a User, not an Employee.** Service desks routinely route to people who aren't on the payroll — vendors with a system login, contractors, or platform admins who don't carry an `employees` row. Keeping the column on `users` means the desk handles all of them with the same dropdown.
- **Visibility scope** is enforced server-side in `TicketPolicy::view` — anyone with `tickets.manage` sees every ticket; the assignee sees their own; the requester sees their own. There is no department-scoped visibility in the MVP. A department head with `tickets.manage` therefore sees the whole desk, not just their team's tickets. Phase 1 will introduce a department gate that mirrors the Employees scope.
- **Server filters and client filters layer.** The Inertia request narrows the paginated dataset (status, priority, assigned-to, search, overdue-only); the toolbar's quick-filters and assignee-chip narrow the *visible slice* of that page. The two are designed to compose — you can server-filter "Critical only" and then client-filter "Mine" without a second round-trip.

## How it talks to other modules

- **`TicketCreated` event** → fired at the end of every successful create. One listener picks it up in the MVP: `RecordAnalyticsEvent` writes a `ticket.created` row to `analytics_events` with the ticket id and priority as payload, which the Reports & Analytics dashboard (Ch 31) groups, plots, and exports. The listener is queue-aware (`ShouldQueue`) so analytics writes never block the user-facing response.
- **`TicketResolved` notification** → fired on the transition into Resolved or Closed (and only on the transition itself — re-firing is suppressed when the ticket was already in a terminal state). The requester's `User` is notified via the `database` channel; the Notifications module (Ch 16) renders it in the bell drawer with the message `"Ticket #{id} \"{title}\" has been {resolved|closed} by {Resolver Name}"` and a `kind: ticket.completed` discriminator so the dashboard can theme it. The resolver is suppressed if they happen to be the requester.
- **`TicketAssigned` notification** → defined and ready to use (database channel, queued, same shape as the resolved notification). The MVP does *not* yet dispatch it from the service — the wiring lands with Phase 1's "notify on assignment" toggle. The class is in `app/Notifications` so tests against it pass and the contract is locked.
- **`Employee` foreign key** → every ticket points at an `employee_id` with `nullOnDelete`. Deleting an employee (soft-delete in practice) leaves their tickets in the system with a null requester rather than cascade-deleting them — the audit trail wins over the foreign-key purity. The Employee detail page's Tickets tab (Ch 3) reads the inverse relation to render the latest 8 tickets per employee.
- **`SequenceService` (not used yet)** → ticket ids are still auto-increment. Phase 1's category work introduces a per-category prefix (`IT-NNNNN`, `HR-NNNNN`) which will move generation onto `SequenceService::next()` to follow the canonical pattern (see [Finance SequenceService convention](project_finance_sequences.md)).
- **`TicketSlaExport`** → an `Excel` export class that streams every Resolved ticket with title, priority, opened/due/resolved-at columns, and an "SLA Met" Yes/No flag. There is no UI button for it in the MVP — it is invoked from the artisan console as a stop-gap until the Phase 1 SLA dashboard ships.

## Standards touchpoints

- **ITIL 4 — Incident & Request Management** — the four-state lifecycle (`open → in_progress → resolved → closed`) is the ITIL-aligned status taxonomy. We do **not** claim ITIL certification; we mirror the canonical states so an ITIL-trained team is at home, and so the analytics dashboard can be benchmarked against ITIL-flavoured industry reports. The "Resolved → Closed" two-step is the ITIL convention: resolved means "agent believes it's done", closed means "the requester has confirmed (or the auto-close timer elapsed)". The auto-close timer is roadmapped — in the MVP a Resolved ticket only moves to Closed by hand. See Chapter 44.
- **ISO/IEC 27001:2022 Annex A.5.24–A.5.26 (Information security incident management)** — the ticket record provides the minimal evidence trail an A.5.24 information-security incident-management process requires: a structured record per incident, an assigned owner, a resolution timestamp, and a separable taxonomy (priority today, category in Phase 1). A.5.25 (assessment) maps to the priority field; A.5.26 (response) maps to the assignment + status updates. The audit trail itself lands in Phase 2 with the tamper-evident log integration. See Chapter 44.
- **ISO/IEC 20000-1:2018 §8.6 (Incident management)** — the same four-state lifecycle satisfies the ISO 20000 incident-management clause's "definition, recording, prioritization, and resolution" expectations. Where 20000-1 expects a documented major-incident pathway, the MVP currently surfaces "Critical" priority as the proxy; Phase 1's category work introduces an explicit "major incident" flag with its own escalation rules.
- **Data Protection Act, 2012 (Act 843) §17 — lawful basis** — ticket descriptions are processed under the employment-contract basis (the same as the rest of the Employee record). The description field's 5000-character limit is a soft data-minimisation gate; the upcoming "category" picker will tag tickets that carry sensitive personal data so they can be redacted at the resource layer when surfaced to non-managers. See Chapter 26.

## What's planned next

Phase 1 of the government-grade roadmap (8–10 weeks; see the gap analysis) lands four upgrades directly on this screen:

1. **SLA matrix + breach escalations** — a per-priority response and resolution target (Critical = 1h / 4h, High = 4h / 1d, Medium = 1d / 3d, Low = 3d / 5d in the seed default; overridable per institute). A scheduled job sweeps the desk every five minutes and dispatches a `TicketSlaBreached` notification to the assignee, the requester, and the requester's department head when either timer pops; a second alert at 200% of target carries an "escalated" tag. The `TicketSlaExport` already in `app/Exports` is the data shape the dashboard will plot.
2. **Categories + assignment routing** — a `ticket_categories` table with code, name, default-priority, and default-assignee-group. A category picker lands in the create panel and the API accepts the foreign key. Assignment becomes a routed default — pick "IT · Hardware" and the ticket auto-assigns to the IT-support pool with a round-robin; pick "HR · Leave query" and it routes to HR — without touching the per-user dropdown.
3. **Comments + attachments** — a `ticket_comments` table (one row per turn, with author and body), and a `ticket_attachments` table (one row per file, with the same `storage/app/public` pattern as employee documents). The detail page's timeline grows from three system entries to a full back-and-forth thread, and the create panel grows a file dropzone. The MVP's "Activity" rail is the placeholder.
4. **Audit log integration** — every create, update, and delete writes to the tamper-evident audit log (Ch 24) so the Auditor-General pack has full provenance on every desk transaction. The current Activity timeline becomes the user-facing surface of that same log.

Phase 2 picks up the long tail: customer-confirmation auto-close (Resolved → Closed after N business days of silence), a public-facing ticket portal for non-staff requesters (vendors, applicants), and a "merge duplicates" flow for the inevitable triplicate raises on a critical outage.
