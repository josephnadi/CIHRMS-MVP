# Chapter 35 — Cross-Cutting Features

> *In one paragraph.* Some capabilities are not pinned to one module — they show up everywhere or quietly underpin everything. Eight of those run through this chapter: the way Role-Based Access Control behaves from the user's side rather than the database's; the three swappable sound packs; the animation grammar that gives every page its sense of motion; the WCAG AA work that turns "we built it" into "everyone can use it"; the state of search today and the global-search gap that is still open; the AI Assistant, which is further along than the roadmap implies but still narrow in surface; the public, pre-login pages that the institute's outside world meets first; and the realtime story — which is, honestly, polling. Each of these gets a page below. The hard, schema-level chapter on RBAC is Ch 39; this chapter is the lived experience.

## RBAC in everyday use

CIHRMS uses three layers of authorisation that the user never sees as three layers. The lowest layer is the legacy enum `users.role` — `employee`, `manager`, `dept_head`, `hr_admin`, `finance_officer`, `it_support`, `auditor`, `ceo`, `super_admin` — which is what every controller fell back on before the database-backed RBAC was introduced in May 2026. The middle layer is the `roles` / `permissions` / `role_permission` / `role_user` tables — proper many-to-many wiring, seeded with the institute's standard role catalogue, and overridable per tenant. The top layer is the per-user `users.permissions` JSON column added in `2026_05_12_083600_add_permissions_to_users_table.php`, which lets a single user be granted (or denied) a single permission without inventing a new role.

What a user actually feels:

- **The sidebar adjusts to who you are.** Every nav group and every link in `Sidebar.vue` is wrapped in a `v-if` against a permission check. A finance officer doesn't see the Disbursements admin entry at all; a dept_head's Payroll group collapses to just "My Team Payslips"; an employee sees the Workforce group reduced to "My Profile". This is enforced again on the server — the route middleware re-checks `permission:employees.view` before rendering anything — but the UI doing its half of the job is what makes the system feel coherent instead of forbidden.
- **CEO mirrors super_admin.** Per PR #38, the CEO role gets every permission super_admin gets via a single `Gate::before` callback that returns `true` for `$user->isRole('ceo') || $user->isRole('super_admin')`. We did this because the executive role kept hitting "you do not have permission" walls during demos for the most ordinary administrative actions; rather than maintain two parallel permission lists forever, the policy fall-through keeps them in sync by construction. This is referenced in Ch 24 (Audit Logs) and Ch 33 (Settings).
- **Department-head scope shapes Leave, Tickets, and Reports.** `Employee::scopeVisibleTo($user)` returns the user themselves, plus — if the user is a dept_head — every employee in any department the user heads, plus their direct reports. The Leave module's approvals queue, the Tickets module's "Assigned to my team" filter, and the Reports module's department-scoped exports all stack on top of that one scope. The same dept_head landing on the Employees directory sees their team's twenty rows; the same dept_head landing on the institute-wide HR Analytics dashboard sees only the slice of charts their permissions allow.
- **The per-user JSON overlay is a real, tested grant.** When a test wants to grant exactly one permission to one user without seeding a whole role, it writes `$user->update(['permissions' => ['employees.manage' => true]])` and the `User::hasPermission()` check honours it. This is the canonical test pattern (see `project_test_patterns.md` in the working memory) and it doubles as the operational pattern when HR needs to give a contractor a single capability for a single project without spinning up a bespoke role.
- **403s are explicit, not silent.** When an action is denied, the user sees a flash error ("You do not have permission to set salary.") rather than a button that just doesn't work. `UpdateEmployeeRequest::authorize()` is the canonical example — the three-tier check happens once, in `authorize()`, before validation even runs, and the error string is written for a human.

The combined effect: the same `/dashboard` URL paints a different page for every role, but the user is never aware of the layers underneath. The "why does my sidebar look different from my colleague's?" support question essentially does not arrive.

## Sound packs

CIHRMS ships with three sound packs and lets the user pick one from a small popover next to the avatar menu. The pack choice is stored in `localStorage` (key `sfx.pack`), so it is per device, not per account — log into the same account from a borrowed laptop and you get the laptop's default until you reach for the popover.

The three packs:

- **`musical`** — the original CIHRMS palette: pleasant abstract tones synthesised on the fly via the Web Audio API. No files on disk; entirely procedural.
- **`cinematic`** (the seeded default) — real-world sound approximations: a doorbell for `notification`, a glass clink for `success`, a distant train horn for `warning`, a cash-register ka-ching for `task.completed`. The procedural fallback is implemented in `resources/js/utils/cinematic-synth.js`; the curated drop-in files live under `public/sounds/cinematic/`.
- **`gamified`** — arcade / chiptune flavour: coin pickups, level-up jingles, "stage clear" horns. Recommended source is the Kenney UI Audio + Sci-Fi Sounds packs (both CC0), per `docs/sound_pack_sources.md`.

The file-override architecture, documented in detail in `public/sounds/README.md` and exercised by `useSound.js`, is the part that matters operationally: when a file exists at `/sounds/<pack>/<event-key>.mp3` (or `.ogg`, or `.wav`), `useSound` plays the real audio file in preference to the synth fallback. So an institute that wants production-grade audio drops the Kenney files into `public/sounds/gamified/`, refreshes the browser, and the next `play('task.completed')` plays the licensed file instead of the chiptune synth. No code change. No deploy. No registry to update.

In the box today, the three pack directories are tracked in git via `.gitkeep` placeholders and contain no audio files — every sound is synthesised. The `cinematic` pack is the default because the institutional feel (doorbells, train horns, station chimes) tested better with HR than the original musical tones. Per memory `project_sound_pack.md`, the three packs landed in PR #25 (open 2026-05-23) with full Kenney CC0 audio available for production drop-in.

Three guard-rails the system enforces regardless of pack:

- **Mute is honoured everywhere.** The `sfx.muted` `localStorage` key short-circuits every `play()`. A user who mutes once stays muted across sessions and tabs.
- **Volume defaults to 0.45.** Loud enough to register, quiet enough to share a desk.
- **Identical events within 600 ms are throttled.** A burst of three success toasts plays one sound, not three. The throttle key is the event name, not the source — so two different events in 600 ms both play.

## Animations

Motion in CIHRMS is loud on the marketing-grade landing surfaces (the dashboards, the Workforce stats band, the hero cards) and silent on the workhorse surfaces (the audit log, the payroll register). The grammar is small enough to memorise and is concentrated in `tailwind.config.js` under `theme.extend.animation` / `theme.extend.keyframes`.

The patterns that recur across every module:

- **`animate-reveal-up`** — the standard "wrapper" animation. Applied to the outermost container of almost every page, with a cubic-bezier easing curve (`cubic-bezier(0.22, 1, 0.36, 1)`) and a 900 ms duration. The first paint lifts up 50 px and fades in. The pattern is so ubiquitous that "no reveal-up wrapper" is a visible bug — pages without it feel like they jumped on screen rather than arrived.
- **Stat-card RGB-triplet glow.** Every stat tile across Employees, Leave, Tickets, Payroll and the dashboards uses the same idiom: an emerald, cobalt, gold, or magenta tile carries a `box-shadow: 0 0 30px rgba(R, G, B, 0.15)` glow whose colour is parameterised by the tile's semantic role (positive number = emerald, count = cobalt, accent = gold, warning = magenta). The glow uses RGB-triplet syntax so the alpha modifier varies but the colour stays in lockstep with the brand palette tokens.
- **`animate-slide-up-fade` stagger.** Lists and grids enter with a 16-px slide-up and a fade, staggered by `animation-delay: ${idx * 0.06}s` so the second card animates 60 ms after the first, the third 60 ms after that, and so on. Six items take 360 ms to finish — enough to feel intentional, fast enough that a scrolling user is never waiting for the page to "settle".
- **Shimmer loaders.** The `animate-shimmer` keyframe drives the placeholder bars during data fetches — a 2.5-second linear gradient sliding from left to right (`backgroundPosition: -200% 0 → 200% 0`). The colour is two shades lighter than the surrounding surface so the shimmer never competes with the eventual content.
- **Reduced motion is respected.** The `@media (prefers-reduced-motion: reduce)` block in `app.css` zeroes out transition and animation durations for users who have asked the OS to settle. This is per the WCAG checklist's note that no inline `transition:` declarations are allowed to bypass that block.

Why bother. Animations buy *perceived* performance. The dashboard's heaviest queries (workforce stats, payroll readiness, the announcement ticker) take 200-600 ms server-side. Without the reveal-up cascade, the user sees a blank page for 600 ms and then a finished one — which reads as "slow". With the cascade, the user sees the page start arriving immediately, even though the data behind it is still on the wire. The total time is identical; the felt time is half.

## Accessibility (WCAG 2.1 AA)

The working WCAG 2.1 AA checklist lives at `docs/wcag_aa_checklist.md` and is treated as merge-gate, not aspiration. The Ghana Persons with Disability Act, 2006 (Act 715) plus the public-sector procurement guidance both reference WCAG AA as the baseline for government service delivery — meeting AA is a procurement gate, not a nice-to-have. What is in place today, and what is still to come:

**Met today:**

- **Colour contrast.** The CIHRM palette is pre-verified to ≥ 4.5:1 for body text and ≥ 3:1 for UI components against every surface it sits on. The `:focus-visible` ring (`2px solid #1d4ed8`) clears 3:1 on white, and the `.sidebar :focus-visible` override switches to white on the obsidian sidebar where the same ring would disappear.
- **Focus states.** Every interactive element has a visible focus ring; `outline: 0` is never set without a `:focus-visible` replacement, and `tabindex > 0` is banned outright.
- **Keyboard navigation.** Every action — every button, every row click, every sound-pack switch, every leave-approval — works from the keyboard alone. Modals and slide-panels use `useFocusTrap(containerRef, openRef, { onEscape })` (see `resources/js/composables/useFocusTrap.js`) so focus stays inside the dialog while it is open and returns to the triggering element on close.
- **Skip-to-content link.** Every layout renders `<SkipLink />` as its first focusable child, targeting `<main id="main-content" tabindex="-1">`. The standard demo: open any page, hit Tab once, hit Enter — focus jumps past the entire sidebar to the page heading.
- **Screen-reader announcements.** `<AriaLiveAnnouncer />` is mounted globally; saves, navigation, and success messages call `announce(text)` (polite), and errors call `announce(text, 'assertive')`. Form validation pairs `aria-describedby` to the error text and sets `aria-invalid="true"` on the failed field.
- **Semantic HTML.** `<header>`, `<nav>`, `<main>`, `<aside>`, `<footer>` landmarks are real elements, not divs; tables use `<th scope="col">` / `<th scope="row">`; icon-only buttons get explicit `aria-label`s.
- **Status colours are not load-bearing.** Green/red badges pair with a ✓ / ✕ icon so colour-blind users never have to guess.
- **High-contrast theme.** Setting `html[data-theme="high-contrast"]` swaps the design tokens to a 7:1 AAA palette for low-vision users.
- **Reduced motion.** Honoured per the animation section above.
- **Automated audit.** `php artisan a11y:audit` is the static sweep run pre-commit and in CI; `--severity=warning` is the stricter mode for release branches.

**Roadmapped:**

- A full third-party audit (axe + manual screen-reader passes via NVDA and VoiceOver) covering every screen, with a report appended to the dossier.
- An "Accessibility statement" public page declaring conformance level, contact channel for accessibility issues, and the date of the last audit — required by most government procurement schedules.
- Caption tracks on the recorded training videos in the Learning module (the `learning_assets` table already carries a `caption_track_path` column; the upload form requires it for video assets).

## Search

Search in CIHRMS today is per-module, not global. Every list view that exceeds a screen of content carries its own search box, hand-wired to the controller's filter logic:

- **Employees** — debounced (380 ms) free-text across `users.name`, `employees.employee_no`, `employees.position`, plus department and status dropdowns.
- **Documents** — title + tag search, restricted to documents the user can see.
- **Tickets** — title, body, requester, and ticket number; combined with the status, priority, and assignment filters.
- **Leave** — employee name, status, leave type.
- **Audit Logs** — user, action, target, free text against the diff blob; date range; severity.
- **Disbursements / Payroll** — period selector plus employee filter.
- **Whistleblower (admin)** — case number, status, assigned investigator; the case body is *not* searchable from the list because the contents are sealed until the case is opened.

What is intentionally absent: a global, system-wide search bar in the top chrome. There is no `Cmd+K` palette, no spotlight, no "search everything" endpoint. The omission is deliberate — every module's results are RBAC-scoped at the query level, and reconciling those scopes into a single ranked result set without leaking rows the user shouldn't see is a small project of its own. Phase 2 picks it up as a dedicated work-stream: a Scout-backed index (probably Meilisearch, possibly Typesense) with per-document RBAC filters baked into the index time, fronted by a `Cmd+K` palette in the global chrome.

Honest gap: today, if HR wants to find "that employee whose Ghana Card I saw flagged last Tuesday", the search journey is "open Employees, search by name; if I don't remember the name, open Audit Logs, filter by date, find the entry, click through". That's a four-step path. The Phase 2 global search collapses it to one.

## AI Assistant

The AI Assistant is further along than the original placeholder line in `project_cihrms.md` implied — and narrower than that maturity suggests. What exists:

- **`AiAssistantController`** at `app/Http/Controllers/AiAssistantController.php` exposes one endpoint: `POST /ai/employee-summary` (web) and `POST /api/ai/employee-summary` (API), both gated by the standard auth middleware. The endpoint accepts `employee_id` (required) and an optional free-text `prompt` (max 1000 chars), and returns a JSON envelope with the generated summary plus token usage.
- **`EmployeeSummaryService`** at `app/Services/Ai/EmployeeSummaryService.php` builds the prompt as three blocks: a stable SYSTEM instruction block, a stable CACHED context block describing the payload schema, and a per-call USER block carrying the redacted employee JSON. The two stable blocks are explicit Anthropic cache breakpoints, so a second call for a different employee scores `cache_read_input_tokens > 0` and bills only the user-message delta.
- **`PiiRedactor`** strips a hard list of fields before any payload leaves the server: `national_id`, `ssnit_number`, `tin_number`, `bank_*`, `salary`, `phone`, `emergency_contact_*`, `address`, `date_of_birth`, `tier2_trustee_id`, `external_crm_id`. The blocklist lives in `config/ai.php` under `pii_blocklist`. Tightening is cheaper than loosening; the default is strict.
- **Two providers.** `AnthropicLlmProvider` calls Claude Haiku 4.5 via the official PHP SDK; `FakeLlmProvider` returns a deterministic, redacted template. `AppServiceProvider` binds the `LlmProvider` interface to one or the other based on `config('ai.enabled')` and `config('ai.driver')`. The fake is what runs in tests; the fake is also what runs in production whenever `AI_ENABLED=false`, so the controller never blows up just because a tenant hasn't configured an API key.
- **Tests.** `tests/Feature/Ai/AiAssistantTest.php` covers the happy path with the fake provider, the unconfigured-provider fall-through, and the 503 envelope when the upstream provider throws.

What does not exist:

- **A user-facing button.** There is no "Summarise this employee" button anywhere in the UI today. The endpoint is reachable, the service is wired, the redactor is enforced — but the Vue front-end has no caller. The first cross-module surface that will use it is the Employee detail page's profile hero, slated for the next sprint.
- **Anything beyond the employee summary.** No policy drafting, no leave-request triage, no chat-summarisation, no transcript-of-the-meeting helper. The Anthropic provider was built to be reusable (the `LlmProvider` contract is generic, the cache pattern is generic) but nothing else calls it yet.
- **A multi-tenant key vault.** `ANTHROPIC_API_KEY` is per-environment in `.env`. Per-MDA isolation (each ministry's calls bill its own account) waits on the integrations vault in Phase 2.

The implementation plan's line — "ML / AI features beyond the `AiAssistantController` placeholder" — remains accurate as a roadmap statement; the controller is no longer a stub but a working endpoint with one job, and the rest of the AI surface is genuinely Phase 4.

## Public-facing surfaces

Almost every CIHRMS surface sits behind authentication. The exceptions are the ones the institute's outside world meets first:

- **Careers** (`GET /careers/{job}`, `POST /careers/{job}/apply`) — the public job-board surface for the Recruitment module (Ch 9 has the full module chapter). `RecruitmentController::showPublic()` renders the open requisition; the `apply` endpoint accepts CV upload, contact details, and the answers to any custom application questions. The form sits behind the standard CSRF and request-size limits; no authentication.
- **Whistleblower submit** (`/whistleblower`, `/whistleblower/confirmation`, `/whistleblower/track`) — the anonymous reporting channel mandated by the Whistleblower Act, 2006 (Act 720). `WhistleblowerPublicController` handles `submit`, `confirmation`, `track` (read-only status check via case number + secret), and `track.reply` (anonymous reply to investigator messages). The whole group is rate-limited to `throttle:6,1` (six requests per minute per IP) to keep the channel usable without being a spam vector. The companion admin surface lives at `/admin/whistleblower/...` under `permission:whistleblower.investigate`. Full module is Ch 27.
- **DPA submit** (`/dpa-request`, `/dpa-request/confirmation`, `/dpa-request/verify`, `/dpa-request/track`) — public submission of Data Protection Act subject-access, rectification, erasure, and portability requests. `PublicDpaController::form` renders the form; `submit` accepts it; `verify` is the email-link confirmation step that proves the requester controls the address; `track` lets the requester check status with the case reference. Full module is Ch 26.
- **Static pages** — `StaticPageController` serves the small set of legally-required public pages: privacy notice, terms of service, cookie policy, accessibility statement (when written), and the department portals reachable at `/portal/{slug}`. The pages are stored in the database (not file-baked) so a non-engineer can edit the privacy notice from the admin without a deploy.
- **Login surface.** `/login` itself is the common entry. Authentication is by staff ID (not email by default — see `project_stack.md`); the login form is the same regardless of role; the post-login redirect routes through the dashboard, which then mirrors the user's RBAC scope. There is no separate "candidate portal login" or "employee portal login" — the same form lets every role in.

Everything else under `routes/web.php` lives behind `auth` (or `auth + permission:...`) middleware. The public surface is intentionally small.

## The realtime story

CIHRMS does not, today, run a websocket layer. The realtime story is polling, and the design is honest about it.

What runs in production:

- **Chat polls every 4 seconds.** `resources/js/Pages/Chat/Show.vue` runs a `setInterval(pollNow, 4000)` while the tab is visible (the interval is paused via `document.visibilitychange` when the tab is hidden, so a backgrounded chat tab is silent). Each poll requests only ids greater than the highest id already on screen, so the second-to-Nth poll is a delta query, not a full re-fetch. The watcher-vs-poll race that would otherwise double-render a freshly sent message is guarded by a post-send id dedupe (see PR #24 in `project_chat_redesign.md`).
- **Notifications use Inertia deferred props.** `HandleInertiaRequests.php` declares `notifications`, `notificationCount`, and `announcementTicker` as `Inertia::defer(fn () => ...)` — these load in a follow-up request after the page paints, so the bell and the ticker update a moment later instead of blocking every navigation on three extra DB queries. The bell does not push; it re-fetches on every navigation. A user sitting on the dashboard for ten minutes without clicking anything sees the same notification count for ten minutes. Acceptable for the MVP; not acceptable forever.
- **Auditor-General pack has no realtime layer.** The Auditor-General audit logs (Ch 24) are append-only; there is no live tail, no push. The admin reloads the page to see new entries.
- **Broadcast driver is `log`.** `.env.example` sets `BROADCAST_CONNECTION=log`. Any `broadcast()` call in PHP writes the payload to `storage/logs/laravel.log` and goes no further. This means the wiring exists (events that *would* broadcast already do `->broadcastOn(...)`) but nothing reaches a browser today.

What Phase 2 picks up:

- **Reverb + Echo** for chat (private channel per conversation, presence channel per directory cohort) and for notifications (private channel per user). Switching from polling to broadcast is a `.env` change plus a small JS bootstrap edit on the Echo side — the controllers and events are already shape-compatible.
- **Live audit-log tail** for the Auditor-General console, on the same Reverb backbone.

Why we are publishing the MVP on polling rather than waiting for the websocket layer: polling delivers acceptable freshness for chat (4 s) and for notifications (per-navigation), it adds zero new infrastructure dependencies (no Reverb process, no Redis pub-sub, no sticky-session load-balancer config), and it gives the MVP the property that the system runs on a single PHP-FPM + a single MySQL + a single queue worker. Government IT departments inheriting the deployment understand that shape. The Reverb upgrade in Phase 2 is additive — the polling path stays as a fallback for clients that can't hold a websocket open.

## Standards roll-up

- **WCAG 2.1 AA** — cited above; the working checklist is `docs/wcag_aa_checklist.md`, the static audit is `php artisan a11y:audit`, and the Ghana Persons with Disability Act, 2006 (Act 715) is the local statute that makes AA the procurement floor for government delivery. Forward to Chapter 44.
- **Data Protection Act, 2012 (Act 843) §17 / §18** — RBAC is how the system enforces "lawful basis for processing": every view query is permission-gated at the route, scoped at the model (`scopeVisibleTo`), and re-checked at the resource layer (`EmployeeResource` omits `salary` for any viewer without `employees.view_salary`). The DPA submit public surface (Ch 26) plus this RBAC enforcement together cover the data subject's controllable lifecycle.
- **Whistleblower Act, 2006 (Act 720)** — the public, throttled, authentication-free submission channel is the operational expression of the statute. Anonymous case tracking, anonymous reply to investigators, and the sealed-until-opened admin queue are all derived from the Act's protections.
- **ISO/IEC 27001 — Annex A.5.15 (Access control)** — the three-layer RBAC (legacy enum, DB role/permission pivot, per-user JSON overlay) is the control implementation, with `EmployeePolicy`, the `permission:*` middleware, and the resource-layer field gating as the supporting evidence.
- **ISO/IEC 27001 — Annex A.13 (Communications security)** — the polling-based chat plus the `BROADCAST_CONNECTION=log` default are the current control posture; the Phase 2 Reverb upgrade adds TLS-terminated websocket channels with per-channel authorisation, closing the loop.
- **Anthropic API responsible use** — the `PiiRedactor` blocklist plus the prompt-prefix cache strategy are how the AI Assistant honours both Anthropic's terms (no PII unless necessary) and the institute's own data-minimisation expectations. The 503 envelope when the upstream is unavailable is how the rest of the UI stays intact when the AI layer is degraded.

Forward to **Chapter 44 — Standards & Compliance Roll-up** for the full register of statutes, standards, and the per-control evidence map.
