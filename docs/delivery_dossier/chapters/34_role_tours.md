# Chapter 34 — Role-by-Role Tours

> *In one paragraph.* Nine roles use CIHRMS. Each one's daily experience of the product is different — the sidebar they see, the page that loads when they sign in, the buttons that exist on the screens they reach, the records they're allowed to read, and the actions they can complete without a second pair of eyes. Up to this point the dossier has walked the modules one at a time; this chapter inverts the axis and walks the *people*. For each role we describe who they are, where they land at login, what they see in the left rail, the three to five things they do every day, where they spend the bulk of their working time on the system, and which earlier chapters they effectively live inside. The role catalogue is the one enumerated in `app/Enums/UserRole.php` and grounded by the seed in `database/seeders/RolePermissionSeeder.php`; the sidebar shape per role is what `resources/js/Layouts/AuthenticatedLayout.vue` actually renders. There is no marketing here — just an honest day-in-the-life per role, with the cross-references to the module chapter that owns each surface.

## How to read this chapter

Each of the nine roles gets its own short section, in roughly descending order of breadth of access — from super_admin and CEO at the top (who see everything) down to the narrow operational lanes (auditor, dept_head). Every section uses the same six-beat shape: *who they are*, *where they land*, *what their sidebar looks like*, *their top daily actions*, *where their working time concentrates*, and *the chapters they live inside*. A closing section then traces the three cross-role handoffs that knit the roles together: leave (employee → manager → hr_admin), tickets (employee → it_support), and onboarding (hr_admin → manager → employee).

A note on the tenth role. The `UserRole` enum carries a `marketing` case for a future dedicated communications role, but the role is not seeded into `ROLE_PERMS` and there is no permission set attached to it today. It is reserved for Phase 5 and intentionally absent from this chapter — anyone created with the `marketing` role today behaves as a base employee until the permission set is filled in.

## 1. super_admin

**Who they are.** Technically the institute's IT or development lead — the one or two people who hold the keys to integrations, role definitions, identity provider configuration, and the audit register. In a small institute this might be a single named engineer; in a larger setup it's a small team. The role exists to administer the platform itself, not to do HR work — though a super_admin can do any HR work, the canonical posture is "configure once, then step back and let HR run the operation."

**Login destination.** Lands on the cobalt-rich **Dashboard** at `/dashboard` (Chapter 1). The dashboard renders the full executive band — workforce headcount, payroll status, ticket queue depth, recent audit log spike, attendance overlay — because super_admin holds the wildcard `*` permission and every dashboard tile asks "can the viewer see this?" before rendering. 2FA is required on this role per the seeder (`two_factor_required = true` for super_admin, ceo, hr_admin, finance_officer), so the very first login of a fresh super_admin account routes through the `/two-factor/enroll` page before any other surface paints.

**Sidebar.** Everything. The layout's `navSections` computed treats super_admin (alongside ceo and hr_admin) as the *privileged nav* shell — three top-level groups (no title, Organization, Departments) plus a fourth **System** group that only super_admin and ceo see. That System group is the super_admin's own neighbourhood: **User Management**, **Notice Board**, **Integrations**, **Whistleblower**, **DPA Requests**, **Messaging**, **SSO Providers**, **API Tokens**, **Webhooks**, **API Docs**, **Settings**, and **Audit Logs**. Every module from Chapter 3 through Chapter 33 is reachable; no item is filtered out by the `visible` flag because `can('*')` returns true for every check. The 248-pixel obsidian rail is dense — twenty-plus items — which is why super_admin is the one role most likely to use Ctrl+B to collapse the sidebar for screen real estate.

**Top daily actions.** Five things, in rough order of frequency:

1. **Skim the audit log** at `/audit-logs` (Chapter 24). The chain verifier ran at 03:00; super_admin glances at the latest fifty rows for any DELETEs, any actions outside business hours, any IP addresses that don't match the office netblock. The "Verify Chain" button is one click away; the AG Export button is two.
2. **Grant or revoke a permission** at `/admin/users` (Chapter 25). A new finance hire needs `gateway.refund` added to their per-user permission JSON; a departing IT support person needs their wildcard removed cleanly. Both writes are themselves audit-logged.
3. **Configure or rotate an integration** at `/admin/integrations` and at `/sso-admin` — adding the Paystack live key, rotating the SSNIT API token, swapping the OIDC client secret with NITA after a regulatory rotation.
4. **Add or rotate an API token** at `/api-tokens` and a webhook at `/webhooks` — for the mobile app, the payroll bureau partner, the cabinet office's monthly ingest, the bank's reconciliation feed.
5. **Triage a whistleblower escalation** — but only when designated as an investigator. The wildcard grants visibility but the chapter's intent (per `WhistleblowerReportPolicy`) is that super_admin reads case content explicitly and rarely, not casually.

**Where they spend most time.** The **System** group — Audit Logs, User Management, Integrations, API Tokens, Webhooks, SSO. The HR and finance modules are visible to super_admin but they are not the workshop; the workshop is the platform configuration surface. A well-run super_admin spends most of any given week *not* in CIHRMS at all, because the platform is doing its job. They appear when there's an integration outage, a permission grant request, or a quarterly chain-verification review.

**Chapters they live inside.** **Ch 24 (Audit Logs)** — daily, sometimes hourly when an incident is in flight. **Ch 25 (Identity & Ghana Card)** for SSO and user management. **Ch 28 (Governance)** for policy publication. **Ch 33 (Settings)** for the per-tenant configuration surfaces. **Ch 27 (Whistleblower)** only when designated as an investigator.

## 2. ceo

**Who they are.** The Chief Executive Officer of the institute — the named office-holder who signs the financial statements, signs the year-end Auditor-General sign-off, and is constitutionally the final escalation for material decisions. The role is held by exactly one person at any given time. Unlike super_admin (who is technical), CEO is institutional — the role exists for organisational accountability, sign-off authority, and unblocked visibility into every corner of the operation.

**Login destination.** Same dashboard as super_admin — `/dashboard` with the full executive band. The two roles paint the same first surface; the divergence is in how they spend the rest of the day. 2FA is also required on this role, and for the same reason as super_admin: executive sign-offs (`payroll.approve`, `loans.approve`, `gateway.refund`) carry material financial weight and warrant a second factor.

**Sidebar.** **Identical to super_admin.** Per PR #38 in the V2 audit cycle, the CEO permission set was promoted to mirror super_admin's wildcard `*` so the chief executive never hits a permission wall on any module. The seeder explicitly comments this: *"CEO mirrors super_admin permission-wise (full access). Kept as a distinct role for org-chart / audit / reporting reasons; the chief executive must not hit a permission wall on any module."* The layout's `FULL_SYSTEM_NAV_ROLES` const lists both `super_admin` and `ceo`, so the System group with its twelve admin items is visible to both. The roles are kept separate at the enum level so that an audit row can truthfully say *"the CEO approved this payroll run"* rather than *"the super-admin approved this payroll run"*; the distinction matters for the Auditor-General pack and for the audit timeline.

**Top daily actions.** Five things, in rough order of frequency:

1. **Approve a payroll run.** The hr_admin calculates the monthly run; the CEO (or the finance_officer — both hold `payroll.approve`) supplies the second pair of eyes and the fresh 2FA challenge required by Chapter 19's approval lane. Without this click, the GhIPSS file does not materialise.
2. **Approve a large loan.** Loans above the executive threshold route through CEO sign-off on the Loans module (Chapter 21).
3. **Read the Auditor-General Report Pack** before the annual board meeting (Chapter 24's sibling export). The CEO holds `statutory.export` and is the named recipient of the chain-verification email if a tamper-evident break is detected overnight.
4. **Read the dashboard's executive band** — workforce snapshot, payroll status, open escalations — usually first thing in the morning and last thing in the afternoon.
5. **Review and publish a governance policy** at `/governance/manage` (Chapter 28). The CEO is typically the named publisher for institute-wide policies; the acknowledgement flow then chases every employee.

**Where they spend most time.** The **dashboard** and the **approval inboxes** — payroll, loans, off-boarding settlements, large vendor invoices. The CEO's CIHRMS day is shaped by what needs a signature, not by what needs configuration. They are the consumer of every module's "needs your approval" surface and a comparatively rare producer of any module's primary records.

**Chapters they live inside.** **Ch 19 (Payroll Engine)** for the approval lane. **Ch 21 (Loans)** for large-loan approvals. **Ch 10 (Off-boarding)** for the dual-control settlement approval. **Ch 24 (Audit Logs)** for monthly review and for any executive escalation. **Ch 28 (Governance)** as publisher. **Ch 20 (Finance)** for the executive view of the General Ledger and the AP/AR ageing.

## 3. hr_admin

**Who they are.** The HR director and their delegates — the operational owners of the workforce master, the leave register, the recruitment pipeline, the onboarding-offboarding choreography, the performance cycle, the learning catalogue, and the benefits enrolments. In a small institute this is one or two people; in a larger one it is a HR team of half a dozen, all carrying the same role. HR is the role that *makes the institute go* on the people side, and accordingly hr_admin is the broadest non-executive role in the system.

**Login destination.** Same dashboard as super_admin and CEO — but with a slightly different mental model: the executive band is informational, while the **Quick Actions** dropdown in the top bar is where the day actually starts. "Request leave," "Open ticket," "Add employee" — the third one is HR's most-pressed button on any new-joiner Monday. 2FA is required on this role.

**Sidebar.** The same *privileged nav* shell as super_admin and CEO, but with one important subtraction: the **System** group is rendered in its *narrower* form because hr_admin does not hold the union of admin permissions that super_admin and ceo hold. The branch in the layout reads `else if (can('announcements.manage') || can('integrations.manage') || …)`, and hr_admin does hold `announcements.manage`, `integrations.manage`, `users.manage`, and `messaging.view` (plus `messaging.send`, `messaging.manage`, `sso.manage`, `sso.audit_view`). So the System group renders with User Management, Notice Board, Integrations, Messaging, SSO Providers, API Docs, and Settings — but **Audit Logs is absent**, and **Whistleblower** and **DPA Requests** are absent. This is deliberate (see "Honest gaps" below). Everything else — Employees, Attendance, Leave, Payroll, Loans, Finance (if `finance.hub` were granted; it is not by default — see gaps), Off-boarding, Performance, Recruitment, Chat, Service Desk, Learning, Governance, Assets, Documents, Reports — is right there.

**Top daily actions.** Five things, in rough order of frequency:

1. **Approve or reject leave requests** at `/leave` (Chapter 4). hr_admin holds `leave.approve` and `leave.manage`, so they see every department's queue. On a typical morning there will be a dozen pending requests; the approval is one click per row with an optional comment.
2. **Add an employee** at `/employees?new=1` (Chapter 3 — the slide-panel opens automatically thanks to the `?new=1` query parameter the Quick Action dropdown wires up). A new joiner gets created, an initial password and `password_must_change=true` is set, the welcome notification fan-out fires, and the employee row is immediately editable for the personal-details capture call HR will do with the new hire on day one.
3. **Run payroll** at `/payroll-runs` (Chapter 19). Once a month: create the draft run, calculate it, eyeball the lines and skipped rows, then hand off to the CEO or finance_officer for the dual-control approval. HR cannot approve their own runs; the system enforces it.
4. **Open an off-boarding case** at `/offboarding` (Chapter 10). HR initiates, drives the clearance, calculates the final settlement, and routes it through the dual-control approval lane.
5. **Send a one-off SMS or moderate the notice board** — `/messaging` for SMS, `/announcements` for the ticker that runs across every authenticated page (Chapter 17).

**Where they spend most time.** **Employees (Ch 3)**, **Leave (Ch 4)**, **Performance (Ch 6)**, and **Recruitment (Ch 9)** — the four modules that, taken together, constitute HR's working surface. The HR dashboard tile (the band of stats at the top of the dashboard) is also where they monitor open leave requests, pending offboarding clearances, headcount drift, and the recruitment pipeline at a glance.

**Honest gaps.** Two are worth naming. First, hr_admin is **not** granted `audit.view`, by design (see Chapter 24): the auditor sits outside the HR chain so that an HR officer who could erase the trace of their own action is not the control posture the Auditor-General will sign off on. Second, hr_admin is **not** granted any whistleblower permission, for the same segregation-of-duties reason (Act 720 explicitly: HR must not be in the routing path). HR can be the *subject* of an audit row or a whistleblower case but not the reader of either register.

**Chapters they live inside.** **Ch 3 (Employees)**, **Ch 4 (Leave)**, **Ch 6 (Performance)**, **Ch 7 (Learning)**, **Ch 9 (Recruitment)**, **Ch 10 (Off-boarding)**, **Ch 19 (Payroll Engine)** — every working day. **Ch 5 (Attendance)** when an exception is raised. **Ch 17 (Announcements)** and **Ch 15 (Messaging)** when something needs broadcast.

## 4. manager

**Who they are.** A line manager — someone who has direct reports but is not the head of a whole department. The role exists to handle the everyday people-leadership tasks for a small team: approving the team's leave, opening and triaging the team's tickets, running the team's performance reviews. In a typical institute a third of all employees might also hold the manager role (anyone who has at least one direct report). Unlike hr_admin, manager does not see the whole organisation — they see only their own team.

**Login destination.** The **slim shell** dashboard at `/dashboard` (no privileged nav). The dashboard renders a manager-shaped band — their team's leave coverage for the week, their open tickets, their team's recent attendance — rather than the executive band. The Quick Actions dropdown is shorter ("Request leave," "Open ticket," "Set a goal" but not "Add employee" or "Record payment"). 2FA is not required on this role by default.

**Sidebar.** The slim shell. Top group: **Dashboard**, **Tasks** (alias for Service Desk / `tickets.index`), **Documents** (if `documents.view` is held), **Leave & Time-Off**, **Benefits**, **Learning & Dev**. No Finance band (`finance.hub` not held). Then a **Support** group with **My Profile** and **Settings**. Six items in the main rail plus two in support — that's it. The compact density is intentional: a line manager doesn't need twenty navigation items, they need the four or five surfaces they touch every day, big and obvious.

**Top daily actions.** Four things, in rough order of frequency:

1. **Approve leave requests for direct reports** at `/leave` (Chapter 4). Manager holds `leave.approve` but `Employee::scopeVisibleTo` filters the queue to only people the manager is `reports_to_id` for. On a Monday after a holiday weekend, this might be three or four requests; on a quiet Wednesday, none.
2. **Triage and resolve tickets** at `/tickets` (Chapter 11). Manager holds `tickets.manage` so they can assign, comment, and close — but only on tickets raised within their team or assigned to them. Most days the manager is closing a couple, commenting on a few, and assigning one or two to IT support.
3. **Approve attendance corrections** at `/attendance/corrections` (Chapter 5). When a team member forgot to clock out, the manager confirms or rejects the correction request. Manager holds `attendance.approve` and `attendance.correct`.
4. **Set a goal or write a performance review** at `/performance/goals` and `/performance/reviews` (Chapter 6). Quarterly, this is the dominant activity for a week; the rest of the year it is intermittent.

**Where they spend most time.** **Leave (Ch 4)**, **Tickets (Ch 11)**, and **Performance (Ch 6)** when it's review season. On a slow week the manager may spend less than an hour total in CIHRMS — the system is intentionally not the centre of a manager's working day, it is the place where the people-management *paperwork* of their team gets done.

**Chapters they live inside.** **Ch 4 (Leave)** and **Ch 11 (Tickets)** — these are home base. **Ch 5 (Attendance)** for correction approvals. **Ch 6 (Performance)** in cycles. **Ch 32 (Profile Portal)** for their own self-service.

## 5. employee

**Who they are.** Everyone on staff who is not in any of the other roles. The largest population in the system; the role exists to give a working employee everything they need to administer their own employment without ever bothering HR for a routine matter. There is no "user-tier" hierarchy below this — employee is the floor, and the Profile portal is the front door.

**Login destination.** The slim shell dashboard at `/dashboard`. The dashboard renders the employee band — a "My Day" card with their leave balance, upcoming approvals, their attendance for the week, an open-tickets count. If they are a new hire (`password_must_change=true` on their User row), the auth middleware redirects them to `/profile#security` before any other surface paints — they cannot reach the dashboard until they change their starter password.

**Sidebar.** The slim shell. **Dashboard**, **Tasks** (tickets), **Documents** (if they hold `documents.view`), **Leave & Time-Off**, **Benefits**, **Learning & Dev**. No Finance, no System group, no Audit, no Whistleblower admin. The **Support** group at the bottom carries **My Profile** and **Settings**. The whole sidebar is six items plus the support pair — clean, scannable, finger-friendly on a tablet.

**Top daily actions.** Five things, in rough order of frequency:

1. **Clock in or out** at `/attendance/me` (Chapter 5). Twice a day, every working day. The employee holds `attendance.clock_self`. The Kiosk module (Chapter 29) covers the case where attendance is recorded by a shared device at the front desk; the self-service URL covers the desk-based case.
2. **Request leave** at `/leave` via the Quick Action ("Request leave," which lands on `/leave?new=1` and opens the slide-panel) (Chapter 4). A few times a month. The employee holds `leave.request` but not `leave.approve` — their request enters the manager's queue.
3. **Open a service ticket** at `/tickets?new=1` (Chapter 11). Occasionally — IT, HR, facilities. The employee holds `tickets.create`.
4. **Chat with a colleague** at `/chat` (Chapter 14). The directory is one click; the chat thread opens inline. This is one of the most-used surfaces by raw click count.
5. **View their payslip** at `/profile` → My Pay tab (Chapter 32 → Chapter 19 read-only). Monthly, right after payroll runs. The salary card is omitted server-side because the employee does not hold `employees.view_salary`, but the payment-by-payment list is right there.

**Where they spend most time.** **Profile portal (Ch 32)** by raw page-view count — that's where their leave balance lives, their payslip list, their bank details, their personal details, their security settings. **Chat (Ch 14)** by raw time-on-page. **Leave (Ch 4)** by transactional weight. **Tickets (Ch 11)** by escalation weight. The dashboard is a quick-glance landing pad; the working day happens on these four surfaces.

**Chapters they live inside.** **Ch 32 (Profile Portal)** above all. **Ch 4 (Leave)**, **Ch 11 (Tickets)**, **Ch 14 (Chat)**, **Ch 16 (Notifications)**. **Ch 5 (Attendance)** for the daily clock. **Ch 19 (Payroll)** read-only via the Profile My Pay tab. **Ch 23 (Benefits)** for enrolment. **Ch 28 (Governance)** when a policy acknowledgement chase lands in their notification bell.

## 6. finance_officer

**Who they are.** The accountant or finance manager — the operational owner of the General Ledger, the Chart of Accounts, the organisational bank accounts, the Accounts Payable lane, the Accounts Receivable lane, the bank reconciliation, the payment gateway, and the disbursement file. They also hold the loan approval and loan disbursement clicks, the dual-control payroll approval, and the off-boarding final-settlement approval. Finance is the second broadest role in the system after HR, and it owns more *money-touching* surfaces than any other role.

**Login destination.** The dashboard at `/dashboard` (slim shell — finance_officer is *not* in `PRIVILEGED_NAV_ROLES`), but the dashboard renders a finance-shaped band with cash position, open AP invoices, open AR invoices, upcoming payroll obligations, and any unreconciled bank lines. The Quick Actions dropdown includes "Record payment." 2FA is required on this role per the seeder, because every action a finance officer takes is materially financial.

**Sidebar.** The slim shell — but with a substantial difference: a dedicated **Finance** group materialises in the sidebar because `can('finance.hub')` returns true. The Finance group renders thirteen items in their own band: **Finance Hub**, **Chart of Accounts**, **Bank Accounts**, **Vendors**, **AP Invoices**, **AP Payments**, **Customers**, **AR Invoices**, **AR Receipts**, **Statements**, **Payment Links**, **Reconciliation**, **Journal**. That's the F1–F5 build-out from Chapter 20. Above the Finance band sits the slim-shell main group (Dashboard, Tasks, Documents, Leave & Time-Off, Benefits, Learning & Dev) and below it the Support group. There is no System group because finance_officer does not hold `users.manage`, `whistleblower.investigate`, `privacy.fulfill`, or any of the other system-tier permissions.

**Top daily actions.** Five things, in rough order of frequency:

1. **Record an AP payment** at `/finance/ap-payments` (Chapter 20 F2). Vendor invoice is approved; finance posts the payment, the journal entry materialises, the disbursement row is queued. This is the *core* daily action.
2. **Record an AR receipt** at `/finance/ar-receipts`. A customer paid; finance applies the receipt against the invoice, the receivable is reduced, the journal posts.
3. **Approve a payroll run** at `/payroll-runs/{id}` (Chapter 19). Once a month. Fresh 2FA challenge; finance is the alternative approver alongside the CEO. The approval materialises the disbursement file and the statutory schedules.
4. **Reconcile the bank statement** at `/finance/reconciliation` (Chapter 20 F5). Daily or weekly. Upload the statement file (CSV / OFX / MT940), let the three-tier matcher do the heavy lifting, hand-match the residue, post bank-fee or interest adjustment journal entries.
5. **Approve or disburse a loan** at `/loans` (Chapter 21). Loan applications stage through finance for approval; once approved, finance disburses (which generates the repayment schedule and materialises the AP-side journal entry).

**Where they spend most time.** **Finance Hub (Ch 20)** above all — the AP and AR lanes alone account for the bulk of any finance day. **Payroll (Ch 19)** at month-end. **Loans (Ch 21)** when the application queue is moving. **Disbursements (Ch 22)** for the GhIPSS dispatch monitoring.

**Chapters they live inside.** **Ch 19 (Payroll Engine)**, **Ch 20 (Finance Hub)** — all five F-tiers (Chart of Accounts, AP, AR, Paystack, Reconciliation), **Ch 21 (Loans)**, **Ch 22 (Disbursements)**. **Ch 10 (Off-boarding)** for the final-settlement approval (`offboarding.settle`, `offboarding.approve`). **Ch 32 (Profile Portal)** for their own self-service, including viewing their own salary (they hold `employees.view_salary`).

## 7. it_support

**Who they are.** The institute's IT desk — the people who triage and resolve technology service tickets, manage the asset registry, and respond to "the printer is broken / my laptop won't boot / I can't log in" requests. A small role by permission breadth but a high-throughput one by daily ticket count. In a typical institute, one to three people hold this role.

**Login destination.** The slim shell dashboard, but with a different lived destination: the IT support person's working day starts at `/tickets`, not `/dashboard`. The dashboard's "open tickets" tile is one click away; for it_support, that link gets clicked first thing in the morning and then the dashboard is rarely seen again until tomorrow. There is no 2FA requirement on this role.

**Sidebar.** The slim shell. **Dashboard**, **Tasks** (which *is* Service Desk for them — same route `modules.tickets`), **Documents**, **Leave & Time-Off**, **Benefits**, **Learning & Dev**. No Finance, no System group, no Audit. The Support group at the bottom carries My Profile and Settings. Identical to a base employee's sidebar in shape — the difference is what `tickets.manage` and `assets.manage` enable inside those pages.

**Top daily actions.** Four things, in rough order of frequency:

1. **Triage and resolve tickets** at `/tickets` (Chapter 11). Most of the day. it_support holds `tickets.manage` for the IT-category tickets and is the default assignee. Open the ticket, comment, assign to themselves, work the issue, close with a resolution note.
2. **Register, assign, return, or retire an asset** at `/assets` (Chapter — referenced in seeder under `assets.view, assets.manage, assets.assign`). When a new laptop comes in, when an employee leaves, when a phone is reassigned.
3. **Open their own service ticket on someone else's behalf** when an employee phones in rather than using the system — it_support holds `tickets.create`.
4. **Request an attendance correction** for themselves when they forgot to clock — `attendance.correct`.

**Where they spend most time.** **Tickets (Ch 11)** — overwhelmingly. **Assets** when there's an asset event. **Profile portal (Ch 32)** for the same self-service every other employee uses. it_support does not see HR, finance, payroll, or audit surfaces.

**Honest gaps.** it_support does **not** hold `users.manage` (that's super_admin / hr_admin), does **not** hold `audit.view` (that's auditor / super_admin / ceo), and does **not** hold any `integrations.manage` (super_admin / hr_admin). When an employee says "IT, please reset my password," it_support refers them to either super_admin / hr_admin (who can do it via `/admin/users`) or to the password-reset self-service flow on the Security tab of the Profile portal. This is deliberate — segregation between *help-desk triage* (it_support) and *account administration* (super_admin / hr_admin).

**Chapters they live inside.** **Ch 11 (Tickets)** above all. **Ch 32 (Profile Portal)** for their own self-service. Assets module when applicable.

## 8. auditor

**Who they are.** The internal auditor — by design positioned *outside* the HR chain so that they can read the audit register, the whistleblower lane, the DPA fulfilment queue, and the calibration apply-side without ever being the subject of a control they themselves oversee. In Ghana's institutional context the auditor role is also the **Data Protection Officer (DPO)** — the seeder grants `privacy.fulfill` to auditor specifically so that data-subject erasure requests don't flow through HR (who would otherwise be asked to fulfil requests *against their own employees*). The role is small in population (typically one named auditor plus possibly an assistant) but unusually broad in read access — auditor sees more rows than any role except super_admin and CEO.

**Login destination.** The slim shell dashboard. Auditor does not hold `finance.hub`, so the finance band does not render; instead the auditor's working destination is **Audit Logs** at `/audit-logs`, which is reached from the dashboard's "Audit" app-switcher tile or from the slim sidebar's Support → … hmm, actually the slim shell does *not* surface Audit Logs in the rail directly. Auditor navigates via the **App Switcher** (top-bar 3×3 grid), which surfaces the Audit tile when `can('audit.view')` is true. (This is the one role for whom the App Switcher is the primary navigation surface rather than the sidebar.) 2FA is not currently required on this role by the seeder; the gap analysis flags it as a Phase 5 hardening item.

**Sidebar.** The slim shell, with the System group rendered in its *narrower* form. Auditor holds `whistleblower.view_all`, `whistleblower.investigate`, and `privacy.fulfill`, so the layout's `else if (can('whistleblower.investigate') || …)` branch fires and the System group renders with **Whistleblower** and **DPA Requests** visible. **Audit Logs** is reached through the app switcher tile rather than a primary sidebar item — a minor inconsistency that pushes auditor toward the App Switcher more than other roles. **API Docs** is visible to everyone. **Settings** is visible to everyone.

**Top daily actions.** Five things, in rough order of frequency:

1. **Browse the audit register** at `/audit-logs` (Chapter 24). Daily. Looking for DELETEs, looking for actions outside business hours, looking for the user-id of the just-departed officer. The "Verify Chain" button confirms the integrity of the SHA-256 hash chain.
2. **Triage a whistleblower case** at `/admin/whistleblower` (Chapter 27). Auditor is the default investigator out of the box. Open the case, run interviews, attach documents, message the submitter through the tracking-code thread, decide on substantiation, refer onward to CHRAJ / Auditor-General / Police if needed.
3. **Fulfil a DPA request** at `/privacy/admin` (Chapter 26). DPA Act 843 §38 data-subject access / correction / erasure requests land here; auditor as DPO is the named fulfiller. The decision is logged on the request's audit_trail JSON and also into the cross-module audit chain.
4. **Apply locked calibration adjustments** at `/performance/calibration` (Chapter 6). Dual-control: the performance facilitator (HR) records the calibration adjustments and locks the session; the auditor (holding `performance.calibrate_apply`) is the second pair of eyes who commits the locked adjustments to the underlying reviews.
5. **Generate and download the Auditor-General Report Pack** at `/ag-reports` (Chapter 24's sibling). Quarterly or on demand. The pack bundles every statutory CSV plus the chain verification output as a sealed ZIP.

**Where they spend most time.** **Audit Logs (Ch 24)** as a daily morning routine. **Whistleblower (Ch 27)** when a case is in flight. **DPA (Ch 26)** when a request lands. **Reports & Analytics (Ch 31)** for the read-only reporting surfaces — auditor holds `reports.view`. The five F-tier finance modules are visible to auditor on a *read-only* basis (the seeder grants `accounts.view`, `bank_accounts.view`, `vendors.view`, `ap_invoices.view`, `journal.view`, `customers.view`, `ar_invoices.view`, `statements.view`, `gateway.view`, `reconciliation.view` but no `manage`, `create`, `approve`, or `pay`), so the auditor can reconcile the finance side without ever being able to alter a row.

**Chapters they live inside.** **Ch 24 (Audit Logs)** above all. **Ch 27 (Whistleblower)** as primary investigator. **Ch 26 (DPA & Privacy)** as DPO. **Ch 31 (Reports & Analytics)**. **Ch 6 (Performance)** on calibration days. **Ch 20 (Finance Hub)** read-only.

## 9. dept_head

**Who they are.** The head of a department — distinct from a line manager in that a department head has visibility across the *whole* department they head, not just their direct reports. In a typical institute the heads of HR, IT, Finance, Marketing, Membership, PCP, CPD, and Administration each hold this role (alongside any other role they may carry; the role system supports multi-role assignments). The role exists to give a department head an unblocked view of their department's people, leave, tickets, and assets, scoped by `User::headedDepartments()` rather than by the manager's direct-reports relationship.

**Login destination.** The slim shell dashboard. The dashboard renders a department-shaped band — the dept_head sees the headcount, attendance, leave coverage, and ticket queue for *their* department, not the whole organisation. The Quick Actions dropdown is the slim manager-shaped one. 2FA is not required on this role by default.

**Sidebar.** The slim shell. **Dashboard**, **Tasks**, **Documents**, **Leave & Time-Off**, **Benefits**, **Learning & Dev**, plus the **Departments** group — and this last group is the one place where the slim shell branches: the layout's portal-children logic surfaces the specific department portal (`/departments/{slug}`) for which the user holds the matching `portal.*` permission. A dept_head of HR sees the HR portal tile; a dept_head of IT sees the IT portal tile; and so on. Eight portal slugs are wired: IT, HR, Marketing, Finance, Membership, PCP, CPD, Administration.

**Top daily actions.** Five things, in rough order of frequency:

1. **Approve leave for anyone in the department** at `/leave` (Chapter 4). The leave queue is scoped via `Employee::scopeVisibleTo($user)` so dept_head sees every leave request from anyone whose `department_id` is in `User::headedDepartments()->pluck('id')`. This is broader than a line manager's view (which is restricted to direct reports) and is what makes dept_head a meaningful role separate from manager.
2. **Triage tickets raised inside the department** at `/tickets` (Chapter 11). dept_head holds `tickets.manage`; the visible queue is filtered to tickets where the requester is in their department.
3. **Approve attendance corrections** for anyone in the department at `/attendance/corrections` (Chapter 5). Same scope rules — anyone in `User::headedDepartments()` is in the queue.
4. **Transfer an employee between departments** — dept_head holds `employees.transfer` (which manager does not), so they can initiate a transfer from their department to another, subject to the usual HR sign-off.
5. **Read the department's report band** at `/reports` (Chapter 31), scoped to their department.

**Where they spend most time.** **Leave (Ch 4)** and **Tickets (Ch 11)** for the daily flow. **Reports (Ch 31)** for the monthly read. **Departments portal (Ch 30)** for the department-specific surface (which carries a department-specific header band, a department-specific announcements list, and links to the department's open cases). Less time in any one module than HR or finance but a broader oversight read across more rows than a line manager.

**Chapters they live inside.** **Ch 4 (Leave)**, **Ch 11 (Tickets)**, **Ch 5 (Attendance)** for corrections, **Ch 30 (Departments)** for the portal, **Ch 31 (Reports)** for the analytics. **Ch 3 (Employees)** as a read-only surface for the department's roster.

## What happens at handoffs

The roles do not live in isolation — every working day in CIHRMS is a series of *handoffs* between them. Three handoffs in particular knit the whole platform together and are worth walking explicitly because they account for the majority of the cross-role interactions in any given week.

**Leave: employee → manager (or dept_head) → hr_admin.** The employee opens `/leave?new=1`, fills the slide-panel, submits. The request enters the manager's queue (or the department head's, if the requester reports directly to a dept_head rather than a line manager); the manager approves or rejects, optionally with a comment. The approval fires a notification to the employee through the bell (Chapter 16) and to hr_admin's leave register, where it becomes a row in the org-wide leave history that payroll will read at the next run-calculate. If the manager rejects, the row goes back to the employee with the rejection reason visible on the row. Edge cases: a manager who is themselves on leave — the request escalates to hr_admin after a configurable timeout (Phase 5 hardening). A manager-less employee (the institute's own CEO, for example) — the request goes straight to hr_admin. A request that spans two departments because of a recent transfer — both dept_heads see it and either can approve.

**Tickets: employee → it_support (or manager, or HR).** The employee opens `/tickets?new=1`, picks a category (IT / HR / Facilities / Other), submits. The router uses the category to set a default assignee: IT category → it_support queue; HR category → hr_admin queue; Facilities → admin or it_support; Other → triage queue. The assignee picks the ticket up, works it, comments back, closes with a resolution note. The employee gets a notification on every status change. If the wrong category was chosen, the assignee can reassign to another category; the audit log carries the reassignment so a "ticket pinball" pattern is visible. Tickets raised by an employee against their own manager's team don't get a separate escalation lane — managers see their own team's tickets and the it_support queue, and the routing is by category not by hierarchy.

**Onboarding: hr_admin → manager → employee.** HR creates the employee via `/employees?new=1`, the row materialises with `password_must_change=true` and a temporary password. HR emails the new hire their login. The new hire signs in, is redirected to `/profile#security` by middleware, sets a real password — at which point they are released into the rest of the system. Over the next few days the manager (or HR) walks them through Profile (personal details capture, emergency contact, bank details), Benefits enrolment, Learning catalogue assignment, and the first leave-request dry run. The employee's first payroll run picks them up automatically when their `hire_date` falls inside the calculate window.

These three handoffs are visible at the role level in the audit register: any row authored by an employee role whose path begins with `/leave`, `/tickets`, or `/profile` is by definition the start of one of the three patterns above. The audit register is therefore not just the auditor's reading surface — it is also the cross-role observability surface for how the institute actually works.

That is CIHRMS, walked by who walks it.
