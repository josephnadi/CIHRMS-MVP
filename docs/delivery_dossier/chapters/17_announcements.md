# Chapter 17 — Announcements

> *In one paragraph.* Announcements is the institute's loudspeaker — one short table, one admin page, and one piece of UI that everybody sees. HR and the executive write notices ("office closes 1pm Friday for the retreat", "all-hands at 09:00 in the Atrium", "new tax form in payroll"); the system then streams those notices across the top of every authenticated page as a live ticker, layered on top of three streams it generates itself (upcoming birthdays, approved-leave events, freshly assigned tasks). The Notifications module in Chapter 16 is for *you-shaped* messages — this is for *everyone-shaped* ones. The Messaging admin in Chapter 15 is for outbound email and SMS to the public — this one stays inside the building.

## Where to find it

- **Sidebar location:** **System** group → **Notice Board** (top of the System section, megaphone icon `campaign`). It lives in the System group rather than Workforce because composing notices is an administrative act, not a workforce one — the same group that contains Integrations, Audit Logs, DPA Requests, Whistleblower, and User Management. The same `announcements.manage` permission gates both the sidebar item and the route.
- **Roles that see it:**
    - **super_admin**, **ceo** — full administrative access (composing, editing, removing notices) and the sidebar item is always visible.
    - **hr_admin** — the canonical author. `announcements.manage` ships in the seeded role permission set.
    - Every other authenticated role — `employee`, `manager`, `dept_head`, `finance_officer`, `it_support`, `auditor`, `marketing` — does **not** see the admin page or the sidebar item, but does see the ticker at the top of every page. Read-only consumers, never authors.
    - Guests (unauthenticated) — the ticker does not load on the login screen at all; the deferred prop is gated on `$request->user()` in `HandleInertiaRequests`.
- **Related modules:** Notifications (Ch 16) — same screen real estate's spiritual sibling, but per-user and click-to-read instead of broadcast; Messaging admin (Ch 15) — external broadcast (email/SMS to contacts and the public) vs. this chapter's internal broadcast (in-app ticker to staff); Audit Logs (Ch 24) — every create/update/destroy is sealed into the tamper-evident log; Leave (Ch 4) — the source of the auto-generated "event" entries on the ticker; Performance (Ch 6) — the source of the "task" entries (new goal assignments); Employees (Ch 3) — the source of the birthday entries.

## The screens

There are only two surfaces in this module — the admin **Notice Board** at `/announcements` (one page, no detail view), and the **ticker** rendered at the top of every authenticated page by `AnnouncementTicker.vue`. We document both here because they are two halves of the same module: one is where you write, the other is where the institute reads.

![Notice Board admin — hero banner, KPI tiles, register, compose drawer](../assets/screenshots/17_announcements/index.png)

*Callouts: ❶ Hero banner — a single navy gradient block declaring how many notices are live on the ticker right now, with a cobalt accent count, the pinned count in gold, and "queued for future" / "expired" counters in indigo. The "Communications · ticker" eyebrow tells the reader which module they are in without consulting the breadcrumb. · ❷ KPI strip — four tiles (Live now, Pinned, Scheduled, Expired) that mirror the hero numbers in tile form for screen-reader users, each with a coloured `icon-tile` matching the donut palette below. · ❸ Composition band — a 180px donut of notices by type (Notice · Event · Birthday · Task · System) on the left, a horizontal-bar severity mix on the right, plus three quick-filter pills (Pinned / Scheduled / Expired) under the bars. Clicking any legend item or quick-filter pill drives the table below. · ❹ Notice register — filter bar (search, type, severity, status, clear) on top of a divider-separated list. Each row carries a coloured left rail keyed to type, the type tile, title, pills (Pinned / Inactive / Scheduled / Expired / severity), a two-line body, and a meta row (type · audience · start · end · author · external link).*

![Compose drawer — title, body, type pills, severity, audience, window, preview](../assets/screenshots/17_announcements/compose.png)

*Callouts: ❶ Title (required, 180 chars max) and Details (optional, 2000 chars max). The body is intentionally lightweight — long-form announcements belong on the intranet behind a `link_url`. · ❷ Type pill row — five pills with brand-keyed accents (Notice cobalt, Event cyan, Birthday magenta, Task gold, System sky-indigo) that snap to a high-contrast selected state when clicked. · ❸ Severity / Audience / Link — three side-by-side fields. Severity has three values (Info, Important, Urgent), Audience defaults to "Everyone" and offers each system role as a scoping target, Link is an optional `https://…` URL that turns the ticker item into an anchor. · ❹ Start / End / Pin / Active — the publish window plus two toggles. Empty start = "publish immediately"; empty end = "never expires". · ❺ Live preview band — a dark gradient block at the bottom showing exactly how the notice will look on the ticker, including the pin icon, the severity pill, and the type accent. The preview re-renders on every keystroke so the author never has to publish-and-check.*

![Ticker — leading label, scrolling rail, controls bar](../assets/screenshots/17_announcements/ticker.png)

*Callouts: ❶ Leading label — a gold-tinted "NOTICE BOARD" chip pinned to the left edge with a pulsing "live" dot. The dot turns cyan and spins while a refresh is in flight, so users get unambiguous feedback that the ticker is talking to the server. · ❷ Scrolling viewport — two cloned tracks scrolling left at a speed proportional to the item count (32 s minimum; longer rails run slower). Hovering the rail pauses both tracks; clicking the pause button does the same. Items with a `link_url` render as `<a>` tags; the rest are inert spans. · ❸ Controls — synced-ago timestamp ("synced 42s ago"), pause, refresh-now, dismiss. Dismiss is local to the tab only; refreshing the page restores the ticker.*

> The three screenshot files referenced above will be captured in Wave 1 (task W1.32). Until then the build substitutes a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Notice Board admin

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **New notice** (cobalt CTA, top right) | Opens the inline compose drawer. The drawer toggles in-place above the donut band; there is no slide-over panel. Clicking the same button (now labelled "Cancel") closes the drawer without firing anything. | `announcements.manage` | One-button publish — composing a notice is the only action on this page, so it gets headline placement. The label flips between "New notice" and "Cancel" so the user always knows what the button will do next. |
| **Live on ticker chip** (top right, next to "New notice") | Read-only pill showing the live count with a pulsing cyan dot. | Anyone viewing the admin page | Mirrors the chip the rest of the institute sees on the ticker — confirms the admin's view matches what staff are reading. |
| **Hero banner** | Read-only block: live count (cyan), pinned count (gold), scheduled, expired, plus the explanatory phrase "scrolling on every authenticated page top". | Same | "How loud is the loudspeaker right now?" answered in one glance. |
| **KPI tiles** (Live now, Pinned, Scheduled, Expired) | Read-only tiles repeating the hero numbers, each with its own icon and a one-line subtitle. | Same | Hero is decorative; KPI tiles are the screen-reader path. |
| **Type donut** (left of the composition band) | SVG donut keyed to the five types with the brand accent colours. The centre repeats the live count. Each legend row is clickable — clicking a legend filters the register below by that type, clicking again clears the filter. | Same | "What kind of notices do we run?" — at-a-glance composition that doubles as a one-click filter. |
| **Severity mix bars** (right of the composition band) | Three horizontal bars (Info cyan, Important gold, Urgent magenta) with the per-severity counts and a percentage on the right. Clicking a bar toggles the severity filter on the register below. | Same | The guardrail prompt: "Urgent should be rare". When the urgent bar starts dominating, the chart calls it out before HR has to. |
| **Pinned / Scheduled / Expired quick-filter pills** (under the severity bars) | Three pills wired straight to the status filter. Toggling sets/clears the filter. | Same | The three states most worth isolating get a one-tap path. |
| **Search box** ("Search title or body…") | Client-side substring filter across title and body. No debounce — the list is in memory because the controller already returned the page. | Same | The Notice Board never paginates past 20 in practice, so a client-side filter is faster than a round-trip. |
| **Type dropdown** (`All types` ▾) | Filters by `notice`, `event`, `birthday`, `task`, or `system`. | Same | The five values map 1:1 to the `AnnouncementType` enum. |
| **Severity dropdown** (`All severities` ▾) | Filters by `info`, `important`, or `urgent`. | Same | Same idea, `AnnouncementSeverity` enum. |
| **Status dropdown** (`All statuses` ▾) | Filters by computed lifecycle state — `active`, `pinned`, `scheduled`, or `expired`. Status is computed in the page from `is_active`, `starts_at`, and `ends_at` against the current clock, not stored on the row. | Same | The user thinks "show me the queued ones"; the database thinks "rows where `starts_at > now`". This dropdown bridges the two vocabularies. |
| **Clear** (appears only when at least one filter is set) | Resets all four filters at once. | Same | One-click escape hatch after an exploratory filter run. |
| **Row (whole row)** | Read-only display — there is no detail page. To act on a notice, use the Edit or Remove icons on the right. | Same | Notices are short — title + body + window is everything the row already shows. A detail page would be an empty repeat. |
| **Edit** (pencil icon, per row) | Opens the compose drawer pre-filled with that notice. The form below the drawer becomes a `PATCH`. | Same | Same drawer, same fields, same validation — only the verb differs. |
| **Remove** (trash icon, per row) | Confirms ("Remove this notice?") and then `DELETE`s. There is no soft delete on this table — the row is gone. The audit log keeps a record. | Same | A notice is ephemeral by design — once it's off the ticker, it's off. The audit log is the long-term memory. |
| **Footer count** (bottom of the register) | "Showing N of M notices" + a "Filters active" indicator when relevant. | Same | Standard table-foot — confirms that "no rows" means "no matches", not "no data". |

> *Notes:* The page hydrates from a single Inertia render — `AnnouncementController::index` returns `announcements` (paginated 20 per page, pinned first, then most-recent first), plus three small aggregate payloads (`stats`, `typeBreakdown`, `severityBreakdown`). The aggregate payloads are computed with `selectRaw('… COUNT(*)') ->groupBy(…) ->pluck()` against the full table — not the current page — so the donut and KPIs always reflect the whole register, not just what's on screen. There is no N+1: the row's `author` relation is eager-loaded with `with('author:id,name')`.

### Compose drawer

| Field | Validation | Notes |
|---|---|---|
| **Title** | required, string, max 180 chars | Saved verbatim to `announcements.title`. This is the line that scrolls on the ticker — keep it short and concrete. |
| **Body** | optional, string, max 2000 chars | Not shown on the ticker — only visible on the admin register and in the audit trail. Use it for context that the author wants future readers (or auditors) to see, not for content the staff need to read in real-time. |
| **Type** (pill row) | required; must be one of `notice`, `event`, `birthday`, `task`, `system` (the `AnnouncementType` enum) | Defaults to `notice`. `birthday` and `task` are kept on the pill row even though the system also generates them automatically — an HR officer can manually add a one-off birthday card or a system-level "everyone has a new mandatory task" notice. |
| **Severity** | required; must be one of `info`, `important`, `urgent` (the `AnnouncementSeverity` enum) | Defaults to `info`. `urgent` styles the ticker item magenta — the compose drawer warns the author with "use sparingly" copy. |
| **Audience** | optional; free-string up to 40 chars on the server, but the UI offers the seven seeded role slugs (`hr_admin`, `manager`, `employee`, `finance_officer`, `it_support`, `marketing`, plus the empty value for "Everyone") | Null = visible to everybody. Set to a role = visible only to users whose `users.role` enum matches. Audience scoping happens server-side in `AnnouncementService::manual()` via the `Announcement::scopeForRole` scope, so a hidden notice is never sent down the wire. |
| **Link** (`link_url`) | optional, valid URL, max 500 chars | When set, the ticker item renders as `<a target="_blank" rel="noopener">`; when empty, the item is an inert span. Use it to link to the intranet post that has the full story. |
| **Starts at** | optional, valid date(time); must be ≤ `ends_at` (validator runs `after_or_equal:starts_at` on the end side) | Empty start = "publish immediately". The Vue form binds the `<input type="datetime-local">` to the local clock; the controller stores it through Eloquent's `datetime` cast which normalises to UTC. |
| **Ends at** | optional, valid date(time), must be `after_or_equal:starts_at` | Empty end = "never expires" (the notice stays on the ticker until an admin removes it or sets `is_active = false`). |
| **Pin to front** | boolean checkbox styled as a chunky toggle pill | Pinned notices float to the top of the ticker rail regardless of severity — the sort is *pinned first, then severity weight, then chronological*. Use sparingly for the same reason as Urgent. |
| **Active** | boolean checkbox, same styling as Pin | When `false`, the notice is "saved as draft" — visible on the admin register with an "Inactive" pill, but absent from the ticker. The state is independent of the start/end window — a draft scheduled for next Tuesday is still a draft. |
| **Live preview band** | read-only | Rerenders on every input event so the author sees the final ticker styling — title, pin, type icon, severity pill — before publishing. |
| **Publish notice** (cobalt CTA) | submits | `POST /announcements` on create, `PATCH /announcements/{id}` on edit. Both use the same `StoreAnnouncementRequest`. On success the drawer closes, a flash toast fires ("Notice published." / "Notice updated."), and the page partial-reloads. |
| **Cancel** | closes drawer | No fields are reset on close — re-opening the drawer in the same session preserves whatever you typed (Vue's `useForm` state). |

> *Notes:* The whole compose flow is gated at three layers — the sidebar item is gated on `can('announcements.manage')`; the route is gated by the `permission:announcements.manage` middleware; the `StoreAnnouncementRequest::authorize()` re-checks the permission server-side before validation even runs. A user without the permission gets a 403 in three places before the row touches the database. The `created_by` field is stamped from `$request->user()->id` in the controller, never accepted from the request body — so an attacker who somehow reached the endpoint cannot impersonate another author.

### The ticker (`AnnouncementTicker.vue`)

| Element | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Leading label** ("NOTICE BOARD" + live dot + count badge) | Read-only chip pinned to the left of the rail. The live dot pulses gold; while a refresh is in flight, the dot turns cyan and the chip's ring animates. The count badge shows the number of items currently in the rail. | Every authenticated user | Establishes "this is the institute's loudspeaker", not "this is a banner ad" — the live dot is the trust signal. |
| **Item** (one per notice / birthday / event / task) | Anchor or span containing an icon, the title, and an optional pin marker. Items with a `link_url` are anchors that open in a new tab with `rel="noopener"`; the rest are inert spans. The left-rail accent colour is keyed to the type; the title colour is keyed to the severity. | Same | The single highest-traffic UI in CIHRMS — it appears on every page after login. The styling difference between Info / Important / Urgent is the only thing distinguishing routine notices from things that need attention. |
| **Pause** (pause icon) | Pauses the marquee scroll. Hovering the rail also pauses it via CSS — the button is the explicit affordance for keyboard / screen-reader users. | Same | Vestibular-safe — anyone reading a long title needs to be able to stop the rail to finish. |
| **Refresh** (refresh icon) | Triggers an Inertia partial-reload of the `announcementTicker`, `notifications`, and `notificationCount` props. The icon spins while the request is in flight. | Same | Sometimes the 60-second poll isn't fast enough — for instance, the user just acknowledged a goal and wants to confirm the task item left the ticker. |
| **Dismiss** (close icon) | Hides the rail for the current tab session only. Reloading the page restores it. | Same | A non-destructive escape — staff who need a few minutes of distraction-free screen can hide the rail without filing a ticket. |
| **Synced X ago** (monospace timestamp) | Shows how long since the last successful refresh; updates every 10 seconds. | Same | Diagnoses "is the ticker actually live?" — if the count says "synced 6h ago" the user knows the tab was backgrounded and the data is stale. |
| **Auto-poll** (background, no UI) | Every 60 seconds the ticker partial-reloads its three deferred props. The poll pauses when the tab is hidden (`document.visibilitychange`) and resumes when it returns. | Same | Reasonable freshness without hammering the server — the average reader doesn't need sub-minute resolution on a notice that's been on the rail for an hour. |
| **Chime on new item** (background, no UI) | When the poll returns an item that wasn't in the previous rail, the matching sound plays from the sound-pack composable — `announcement` for notices and birthdays, `assigned.you` for tasks, `event.created` for events, `warning` for urgent severity. Cold-load is silent (the first poll seeds the known-id set without firing). | Same | Audible cue for the cases where the visual scroll might miss the user — a notice arrives while they're reading email in another column. |
| **Reduced-motion fallback** | The marquee, the pulse ring, and the spinner all suspend animation when `prefers-reduced-motion: reduce` is set. | Same | WCAG 2.1 §2.3.3 — motion can be a barrier; this respects the user's OS-level preference automatically. |
| **Mobile collapse** | Below 640px the leading-label text and the synced-ago timestamp drop out; only the icon, count badge, and item rail remain. | Same | Phone users still need the loudspeaker; the chrome doesn't fit. |

> *Notes:* The ticker is mounted once in `AuthenticatedLayout.vue` at the very top of the page, above every Inertia page. It receives its data through the Inertia `announcementTicker` shared prop, populated by `HandleInertiaRequests::share()` via `Inertia::defer(...)`. The defer is important — the initial Inertia render does not block on the three database queries the ticker needs (aggregate, scope, sort); the page paints first, then the ticker fills in on the follow-up request. The same defer carries the bell-badge notifications, so one round-trip refreshes both UIs.

## The data behind it

A notice is one row in `announcements` and nothing else. The table has 14 columns plus the standard `timestamps` pair:

- **Content** — `title` (≤ 180 chars, required), `body` (≤ 2000 chars, optional), `link_url` (≤ 500 chars, optional), `icon` (≤ 40 chars, optional — falls back to the type's default Material Symbols glyph).
- **Categorisation** — `type` (enum: `notice` / `event` / `birthday` / `task` / `system`), `severity` (enum: `info` / `important` / `urgent`).
- **Targeting** — `audience_role` (free-string ≤ 40, null = everyone). Indexed.
- **Lifecycle** — `is_active` (boolean, default true), `pinned` (boolean, default false), `starts_at` (nullable timestamp), `ends_at` (nullable timestamp). The composite index `(is_active, starts_at, ends_at)` is the one that makes `Announcement::activeNow()` a single index scan even on tables of thousands of rows.
- **Provenance** — `created_by` (foreign key to `users`, `nullOnDelete` so that removing an author user leaves the historical notice intact), plus the Eloquent timestamps.

There are no associated tables — no attachments, no acknowledgement receipts, no per-user view counters, no comments. Announcements are deliberately a *flat* concept in the MVP. Anything richer (acknowledgement workflows, mandatory read-receipts, document attachments) is roadmapped behind specific compliance triggers and described in *What's planned next* below.

What every reader of the screen needs to keep in mind:

- **Lifecycle is computed, not stored.** A notice's effective status — Active / Scheduled / Expired / Inactive — is derived in real time from `is_active`, `starts_at`, and `ends_at` against `now()`. There is no `status` column. Scheduling a notice for next Tuesday does not flip a "scheduled" flag; it sets a `starts_at` in the future. The ticker's `activeNow()` scope (and the admin index's status filter) re-evaluates this on every read.
- **Audience scoping is enforced server-side.** The Vue ticker never receives notices outside its audience — `AnnouncementService::manual()` filters with `Announcement::scopeForRole` before mapping into the response payload. Browser tools cannot reveal scoped notices the user isn't entitled to see.
- **The ticker is composite, not a one-to-one mirror.** Only the *Notice / Event / System* rows the admin sees on this page come from the `announcements` table. The Birthday and Task entries on the ticker are computed at request time from `employees.date_of_birth` (next 7 days) and `goals.created_at` (last 7 days, the viewer's own goals). Removing a manual "birthday" notice from this page does not stop the system from auto-broadcasting actual upcoming birthdays.
- **Hard delete by design.** Unlike Employees (Ch 3) or Tickets (Ch 11), there is no `SoftDeletes` trait on `Announcement`. Removing a notice is irreversible — the row leaves the database. The audit log (Ch 24) records the create, update, and destroy actions including the full payload of the deleted row, so the institutional memory of *what was announced* survives even after the row is gone.
- **No "draft" status — use `is_active = false`.** A notice with `is_active = false` is the canonical draft state. It appears on the admin register with an "Inactive" pill but does not stream on the ticker. The Vue compose drawer surfaces this as the `Active` toggle.

## How it talks to other modules

- **`HandleInertiaRequests::share()`** — the middleware that injects shared props into every Inertia response wires `announcementTicker` to `AnnouncementService::ticker($request->user())`. This is the only path by which a notice reaches a user's screen; there is no public route, no broadcast channel, no email fan-out. The shared prop is wrapped in `Inertia::defer(...)`, so the ticker hydrates after the page paints rather than blocking the first byte.
- **Notifications (Ch 16)** — sibling, not parent. The two modules sit side-by-side under the same defer block in the Inertia share, refresh on the same 60-second poll, and share a UX language (icon-tile, severity, pinned). They are functionally distinct: a Notification is *for one user, has a read state, and demands action*; an Announcement is *for everyone (or everyone-in-a-role), has no read state, and is informational*. Nothing currently fans an announcement out into per-user notifications, but the doors are wired for it (see roadmap below).
- **Messaging admin (Ch 15)** — the *external* twin. Messaging admin pushes to email, SMS, and the public website; Announcements pushes to the in-app ticker. They share no tables; their only relationship is that the same author often uses both — a single "office closes 1pm Friday" message typically goes out through Announcements (in-app) and Messaging admin (SMS) at the same time.
- **Employees (Ch 3)** — read-only consumer. `AnnouncementService::birthdays()` queries `employees.date_of_birth` for the next seven days to layer auto-generated birthday items onto the ticker. Birthdays are computed in PHP rather than SQL so the same code runs identically across SQLite, MySQL, and PostgreSQL (MONTH/DAY math differs between engines).
- **Leave (Ch 4)** — read-only consumer. `AnnouncementService::events()` queries `leave_requests` for approved leave starting in the next fourteen days; each one becomes an `event`-type ticker item. The link is purely visual — the ticker links nowhere; staff who want detail open the Leave module separately.
- **Performance (Ch 6)** — read-only consumer. `AnnouncementService::tasks()` queries the viewer's own `goals` created in the last seven days (excluding completed and cancelled) and surfaces them as `task`-type items. The ticker item links to `route('performance.goals.index')` so a click jumps straight to the goals page.
- **Sound packs (Ch 23, see project memory)** — the ticker calls into `useSound` from `@/composables/useSound` when a new item arrives, mapped per-type. A user who has chosen "musical" / "cinematic" / "gamified" hears the right sound for that pack; a user who muted the app hears nothing. All packs ship a `announcement`, `assigned.you`, `event.created`, and `warning` sound, so the mapping never falls off an edge.
- **Audit Logs (Ch 24)** — write-only consumer. Every successful create, update, and destroy is captured into the tamper-evident audit log by the global audit observer; the deleted-row payload is preserved verbatim so a removed notice is fully reconstructible from the audit trail.

## Standards touchpoints

- **WCAG 2.1 AA (Web Content Accessibility Guidelines)** — the ticker is constructed to honour four specific success criteria. **§1.4.3 (Contrast)** — the navy-gradient shell, the gold leading-chip text, and the per-severity item colours all clear the 4.5:1 ratio against the cobalt-to-indigo background. **§2.2.2 (Pause, stop, hide)** — the marquee can be paused by hover, by the explicit pause button, and by dismissing the rail; the dismiss is keyboard-reachable and announced via `aria-label`. **§2.3.3 (Animation from interactions)** — the marquee animation, the pulse ring, and the refresh spinner all suspend under `prefers-reduced-motion: reduce`. **§4.1.2 (Name, role, value)** — the rail carries `role="status"` and `aria-live="polite"`, every control has an `aria-label`, and the pause/resume button's label flips with state so assistive tech narrates "Pause scrolling" / "Resume scrolling" correctly. See Chapter 44 for the institute-wide accessibility audit.
- **WCAG 2.1 §3.1.5 (Reading level)** — the 180-character title cap is deliberate. It forces authors to lead with the action ("Office closes 1pm Friday for the retreat") rather than burying the news under bureaucratic preamble. Longer prose belongs in the optional body field or behind a `link_url`.
- **Data Protection Act, 2012 (Act 843) §17 — lawful basis** — the institute relies on legitimate-interest grounds for processing employee personal data in the auto-generated birthday and task layers. The system minimises this exposure by (a) showing only the employee's name and the relative day of their birthday (not the year of birth), (b) excluding birthdays beyond a seven-day horizon, and (c) running the entire layer behind the `prefers-reduced-motion` and dismiss controls so any individual user can opt out for the session. An institute-level opt-out (per-employee preference) is roadmapped (see below).
- **Data Protection Act §18 (data minimisation)** — birthday items expose name plus relative day only. Date of birth, age, and year are never sent to the browser.
- **Data Protection Act §40 (right to erasure)** — when an employee row is redacted (the institute's erasure procedure), the redaction job also nullifies `date_of_birth`, which removes that employee from the auto-generated birthday layer on the next ticker poll. Manual notices stamped with `created_by = <that user>` keep the title and body (the institutional record); the foreign key nulls out under the `nullOnDelete` constraint so the row stands without an author rather than dangling.
- **National Communications Authority — accessibility advisory for state institutions** — the live-region pattern (`aria-live="polite"`), the pause control, and the reduced-motion fallback together satisfy the NCA's 2024 accessibility advisory for institute-wide announcement channels.

## Audience scoping in detail

There are exactly three audience targets the admin can compose against:

- **Everyone** (the empty `audience_role`). The notice streams on every authenticated user's ticker regardless of role. This is the default and the most common choice — "office closes Friday" is for everyone.
- **A single system role** — one of `hr_admin`, `manager`, `employee`, `finance_officer`, `it_support`, or `marketing`. The notice streams only on the ticker of users whose `users.role` enum matches. A notice scoped to `manager` reaches department heads and people-managers; a notice scoped to `finance_officer` reaches finance staff alone.
- **A single department** (roadmapped, Phase 1). The MVP does not scope to departments — the seven role values are the entire vocabulary today. The schema is ready for a future `audience_department_id` column; the UI will gain a second selector at that point.

The scoping is enforced in the `Announcement::scopeForRole` query scope on the model — `whereNull('audience_role')->orWhere('audience_role', $role)`. The scope runs inside `AnnouncementService::manual()` before the rows are mapped into the ticker payload, so a notice scoped to a different role *is never sent to the browser at all* — there is no client-side filter that could be defeated by DevTools.

There is no "audience excludes" scoping (no way to write a notice for "everyone except marketing"), and no multi-role scoping (no way to write a notice for "HR + finance only"). The product position is that if a notice cuts across roles, it usually applies to everyone; if it doesn't, it should be split into two notices.

## Publish workflow

Announcements have no formal review or approval workflow — they go live the moment the author clicks "Publish notice". The implicit workflow is encoded in the lifecycle states the user sees on the admin register:

- **Draft** — `is_active = false`. The author saved the notice without making it public. Appears on the admin register with the grey "Inactive" pill; absent from the ticker. There is no second-author approval step in the MVP — drafts are a tool for the author, not a control over the author.
- **Scheduled** — `is_active = true` and `starts_at > now()`. The notice will appear on the ticker automatically when `starts_at` falls behind the clock. There is no scheduler job; the `Announcement::activeNow()` scope re-evaluates the windows on every read. Appears with the cyan "Scheduled" pill.
- **Active** — `is_active = true`, `starts_at IS NULL OR starts_at <= now()`, `ends_at IS NULL OR ends_at >= now()`. On the ticker right now. The default visible state.
- **Expired** — `is_active = true` and `ends_at < now()`. The notice has passed its end window. Appears on the admin register with a grey "Expired" pill; absent from the ticker. Expired rows are retained in the table until an admin explicitly removes them — the institute keeps them so authors can clone "we did this last year" notices into next year's equivalents.

The four states are computed on the fly from three columns (`is_active`, `starts_at`, `ends_at`) — the admin UI re-derives them client-side in the `noticeStatus()` helper to populate the per-row pill; the controller re-derives them server-side to populate the KPI counts. There is no synchronised "status" column to drift out of step.

Two control surfaces let the author transition between states without re-opening the drawer:

- **The `Active` toggle in the compose drawer** — flips `is_active`. Use it to take a notice off the ticker without deleting it.
- **The `Remove` (trash) row action** — hard-deletes the row. Use it when the notice is genuinely no longer relevant.

There is no separate "archive" verb — the convention is that an expired notice can be left in place (becomes a future template) or removed (genuinely irrelevant). Archive-on-write is Phase 1 work, alongside the acknowledgement-receipt feature.

## Engagement metrics

The MVP does **not** track per-user view counts, click-throughs, or acknowledgements on announcements. This is a deliberate scope decision: the ticker is a broadcast medium, not an engagement medium. The metrics the admin sees on the index page are aggregates of the register itself, not of the audience:

- **Live now** — count of rows currently satisfying `is_active = true AND starts_at IS NULL OR <= now AND ends_at IS NULL OR >= now`.
- **Pinned** — count of `pinned = true AND is_active = true` rows. Excludes inactive pins on the assumption that the author meant to publish.
- **Scheduled** — count of `is_active = true AND starts_at > now` rows.
- **Expired** — count of `ends_at < now` rows (regardless of `is_active`).
- **Type breakdown** — `GROUP BY type` over the full table; feeds the donut and the legend.
- **Severity breakdown** — `GROUP BY severity` over the full table; feeds the horizontal bars and the percentage labels.

The aggregates are recomputed on every page load — there is no cache. With a table that typically sits in the tens-to-low-hundreds of rows even after a year in production, the `COUNT(*) GROUP BY` queries return in single-digit milliseconds; there is no need for materialised summaries.

Real engagement metrics (per-user view counts, click-through rates, acknowledgement workflows for mandatory-read notices, audit-grade evidence that "every member of staff has acknowledged the new code of conduct") are deferred to Phase 1 as part of the *Compliance Announcements* feature described below.

## What's planned next

Phase 1 of the government-grade roadmap lands four enhancements on this module, in priority order:

1. **Department-level audience scoping** — a second selector on the compose drawer ("scoped to which departments?") backed by a new `announcement_departments` pivot table. A notice can target any combination of {everyone, role, department}; the query scope expands to handle the union. This unblocks the "for the Finance department only" use case that today has to fall back to the role scope, which is too broad.
2. **Acknowledgement receipts (Compliance Announcements)** — an optional flag on a notice ("requires acknowledgement"), backed by a new `announcement_acknowledgements` table with one row per (user, announcement). Required notices sit on the ticker until the user clicks an explicit "I acknowledge" button, after which the row leaves their ticker but stays on everyone else's. The admin register gains an "Acks: 142 of 198" column that drives the auditor's evidence pack. This is the prerequisite for the Auditor-General compliance requirement that policy updates be demonstrably read by every member of staff.
3. **Attachments and richer body** — a notice can attach a PDF (the policy itself, the timetable, the seating plan) through the same documents pipeline used by Employees (Ch 3) and Tickets (Ch 11). The body field grows a Markdown-lite renderer so authors can format short policy text without falling back to the link-to-intranet pattern.
4. **Cross-channel fan-out** — a "broadcast to email + SMS as well" checkbox on the compose drawer that, when set, queues the notice through the Messaging admin (Ch 15) pipeline at the same time it publishes to the ticker. This collapses the current two-step "announce in-app, then announce externally" choreography into a single act, with one audit-log row covering both channels.

See Chapter 46 (Roadmap) for the full schedule; the four items above are sized at roughly two engineering weeks combined and gated behind the Auditor-General compliance trigger.
