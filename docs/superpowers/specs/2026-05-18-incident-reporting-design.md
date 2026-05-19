# Incident Reporting — Governance Sub-Module Design

**Date:** 2026-05-18
**Status:** Draft, pending implementation
**Owner:** CIHRMS team

## 1. Purpose

Give every employee a private channel to submit grievances, improvement suggestions, workplace-safety reports, and other concerns, routed to a small group of senior reviewers (the CEO and a handful of executives). The feature differs from the existing modules in three deliberate ways:

- **Not anonymous** like the `Whistleblower` channel — the author is identified, accountable, and can be replied to.
- **Not pooled to HR investigators** like the `Complaints` module — only users explicitly granted `incidents.review` can be assigned; HR by default cannot see incident content.
- **Strictly private** — only the submitter and currently-assigned reviewers can read the report, the thread, or any attachment. Not even `super_admin` is exempted.

## 2. Module placement

A new sub-page under the existing Governance module, surfaced in the sidebar as a child of `Governance`:

```
Governance
  ├─ Overview
  ├─ Manage
  ├─ Certifications
  └─ Incident Reports   ← new
```

Routes nest under `governance/incidents`. Vue pages live at `resources/js/Pages/Governance/Incidents/{Index,Show}.vue`.

## 3. Data model

Four new tables, all in a single migration file.

### 3.1 `incident_reports`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `employee_id` | FK → `employees.id` | Submitter, resolved from the auth user's employee row |
| `category` | enum | `grievance`, `improvement`, `safety`, `other` |
| `title` | varchar(180) | |
| `body` | text | Initial composition |
| `status` | enum | `open`, `in_review`, `closed` — default `open` |
| `closed_at` | timestamp NULL | |
| `closed_by_id` | FK → `users.id` NULL | |
| `resolution_note` | text NULL | Posted on close |
| `created_at`, `updated_at`, `deleted_at` | | Soft-deletes (audit) |

Indexes: `(employee_id, status)`, `(status, created_at)`.

### 3.2 `incident_report_assignees`

Pivot enabling multi-assignment with audit trail.

| Column | Type | Notes |
|---|---|---|
| `incident_report_id` | FK CASCADE | |
| `user_id` | FK → `users.id` | Must hold `incidents.review` at assignment time |
| `assigned_at` | timestamp | |
| `assigned_by_id` | FK → `users.id` | Submitter on first assignment, assignee on later reassignments |
| `removed_at` | timestamp NULL | Soft-remove preserves trail; access revoked the moment this is set |

Primary key: composite `(incident_report_id, user_id)`. A user "is currently an assignee" iff `removed_at IS NULL`.

### 3.3 `incident_report_messages`

Thread between submitter and current assignees. Append-only.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `incident_report_id` | FK CASCADE | |
| `author_id` | FK → `users.id` | Must be submitter OR a current assignee at write time |
| `body` | text | |
| `created_at`, `updated_at` | | |

Index: `(incident_report_id, created_at)`.

### 3.4 `incident_report_attachments`

Polymorphic — attaches to either an `IncidentReport` or an `IncidentReportMessage`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `attachable_type` | string | `IncidentReport` or `IncidentReportMessage` |
| `attachable_id` | bigint | |
| `file_path` | varchar(255) | Path on the private `incidents` disk |
| `original_name` | varchar(255) | Persisted separately from disk path |
| `mime_type` | varchar(120) | |
| `size_bytes` | int | |
| `uploaded_by_id` | FK → `users.id` | |
| `created_at` | | No `updated_at` — immutable |

Index: `(attachable_type, attachable_id)`.

### 3.5 Relationship summary

- `IncidentReport hasMany IncidentReportMessage`
- `IncidentReport belongsToMany User` through `incident_report_assignees`
- `IncidentReport morphMany IncidentReportAttachment`
- `IncidentReportMessage morphMany IncidentReportAttachment`
- `IncidentReport belongsTo Employee` (submitter)

## 4. Permissions & policy

### 4.1 New permission

| Slug | Label | Description |
|---|---|---|
| `incidents.review` | Review incident reports | Can be assigned to and view confidential incident reports submitted by employees |

Granted to no role by default. Super-admin or HR-admin (via the existing RBAC UI) grants it explicitly to the CEO, Compliance Officer, etc. Per project memory, the project also supports per-user grants via `users.permissions` JSON column — that path remains available for ad-hoc grants and is the path the test suite uses.

Submission is **not** gated by any permission. Every authenticated employee can submit.

### 4.2 `IncidentReportPolicy`

Single class, every controller action authorises through it. The privacy invariant is enforced in one place.

```
viewAny(user)             → any auth user (index controller filters rows per circle)
view(user, report)        → user is submitter OR user is a current (removed_at IS NULL) assignee
create(user)              → user has an employees.id row
update(user, report)      → user is submitter AND status === 'open' AND assignees().count() === 0
close(user, report)       → user is a current assignee
assign(user, report)      → user is submitter OR user is a current assignee
postMessage(user, report) → view(user, report) AND status !== 'closed'
downloadAttachment(user, attachment)
                          → defers to view(user, attachment->reportRoot())
```

### 4.3 Invariant

> Privacy circle = `{ submitter } ∪ { current assignees with removed_at IS NULL }`. Nobody outside this circle can read the report body, the thread, or any attachment — including `super_admin`. No override exists.

The service layer additionally verifies, at assignment time, that the target user holds `incidents.review`. Failure returns a 422 validation error. This is where "CEO + a few" enforcement lives — the candidate pool is everyone with the permission.

## 5. Backend API

### 5.1 Routes

All nested under `governance/incidents`, all behind `auth + audit` middleware.

```php
Route::middleware(['auth', 'audit'])->prefix('governance/incidents')->name('incidents.')->group(function () {
    Route::get('/',                                  [IncidentReportController::class, 'index'])    ->name('index');
    Route::get('/{report}',                          [IncidentReportController::class, 'show'])     ->name('show');
    Route::post('/',                                 [IncidentReportController::class, 'store'])    ->name('store');
    Route::patch('/{report}',                        [IncidentReportController::class, 'update'])   ->name('update');
    Route::post('/{report}/assign',                  [IncidentReportController::class, 'assign'])   ->name('assign');
    Route::delete('/{report}/assign/{user}',         [IncidentReportController::class, 'unassign']) ->name('unassign');
    Route::post('/{report}/messages',                [IncidentReportController::class, 'postMessage'])->name('messages.store');
    Route::post('/{report}/close',                   [IncidentReportController::class, 'close'])    ->name('close');
    Route::post('/{report}/reopen',                  [IncidentReportController::class, 'reopen'])   ->name('reopen');
    Route::get('/attachments/{attachment}/download', [IncidentReportController::class, 'downloadAttachment'])->name('attachments.download');
});
```

### 5.2 Controller

`IncidentReportController` — thin, delegates to `IncidentReportService`. Each read/write action begins with `$this->authorize('<ability>', $report)`.

### 5.3 Service — `IncidentReportService`

All business logic + DB transactions live here. Mirrors the `EmployeeService` style.

| Method | Behaviour |
|---|---|
| `list(Request)` | Returns paginated reports. Privacy scope: `whereHas(submitter where user_id = me) orWhereHas(assignees where user_id = me AND removed_at IS NULL)`. Optional filters: `category`, `status`, `q` (title search). |
| `create(StoreIncidentReportRequest)` | Transactional. Persists report, attaches initial files, fires `IncidentReportSubmitted`. |
| `assign(report, userId, actor)` | Verifies target holds `incidents.review`; inserts pivot row; fires `IncidentReportAssigned`. First assignment transitions status `open → in_review`. |
| `unassign(report, userId)` | Sets `removed_at = now()` on the pivot. Fires `IncidentReportUnassigned`. |
| `postMessage(report, author, data)` | Persists message + attachments. Fires `IncidentMessagePosted`. |
| `close(report, actor, ?note)` | Sets `closed_at`, `closed_by_id`, `resolution_note`; status → `closed`. Fires `IncidentReportClosed`. |
| `reopen(report, actor)` | Status → `in_review`, clears `closed_at`. Fires `IncidentReportReopened`. |

### 5.4 Form Requests

- `StoreIncidentReportRequest` — `category in [...]`, `title 6..180`, `body 20..10000`, `attachments[]` optional, each `file|mimes:pdf,png,jpg,jpeg,doc,docx|max:10240`.
- `UpdateIncidentReportRequest` — `title` and `body` only.
- `AssignIncidentReportRequest` — `user_id` exists in `users` AND holds `incidents.review` (custom rule).
- `StoreIncidentMessageRequest` — `body 1..10000`, attachments optional same as report.
- `CloseIncidentReportRequest` — `resolution_note` nullable string max 5000.

### 5.5 Enum

`App\Enums\IncidentCategory` with cases `Grievance`, `ImprovementSuggestion`, `WorkplaceSafety`, `Other`, plus `label()`. Same shape as the existing `EmployeeStatus` enum.

### 5.6 Resource

`IncidentReportResource` returns:
- All report fields except internal pivot timestamps.
- `whenLoaded` assignees (name, role label — no `assigned_by_id` exposed to the submitter view).
- `whenLoaded` messages (id, author name+id, body, created_at, attachment summaries).
- `whenLoaded` attachments (id, original_name, size_bytes, mime_type, and a per-request signed download URL via `URL::signedRoute`).

## 6. Notifications

All in-app, via the existing `notifications` table polled by `AnnouncementTicker`. No email/SMS.

| Event | Recipients | `kind` | Message |
|---|---|---|---|
| `IncidentReportAssigned($report, $newAssignee, $actor)` | `$newAssignee` | `incident.assigned` | `"You've been assigned an incident report: '{title}'"` |
| `IncidentReportUnassigned($report, $removedAssignee)` | `$removedAssignee` | `incident.unassigned` | `"You no longer have access to incident: '{title}'"` |
| `IncidentMessagePosted($message)` | every other current circle member (exclude author) | `incident.message` | `"New reply on incident: '{title}'"` |
| `IncidentReportClosed($report, $actor)` | submitter | `incident.closed` | `"Your incident report '{title}' has been resolved"` |
| `IncidentReportReopened($report, $actor)` | submitter + current assignees except actor | `incident.reopened` | `"Incident '{title}' was reopened for further review"` |

Notification rows carry a `data` JSON column with `{ incident_report_id, route: 'incidents.show' }` so the bell deep-links to `/governance/incidents/{id}`.

`NotificationBell`'s sound mapping (via the existing `useSound` composable):

| kind | sound preset |
|---|---|
| `incident.assigned` | `assigned.you` |
| `incident.message` | `notification` |
| `incident.closed` | `task.completed` |
| `incident.reopened` | `notification` |
| `incident.unassigned` | _silent_ |

The unassigned notification is written **before** the pivot's `removed_at` is set, so the recipient reads the title once. On the next request, `/governance/incidents/{id}` returns 403 from the policy — by design.

## 7. Frontend

### 7.1 Sidebar

A new entry `Incident Reports` is added as a child of the existing Governance expandable group in `AuthenticatedLayout.vue`. Icon: `report`. Visibility: `true` for every auth user (the index controller filters per privacy circle).

### 7.2 Page — `Governance/Incidents/Index.vue`

Layout: `AuthenticatedLayout` via `defineOptions`. Header teleports into `#page-header-mount`. Layout pattern matches the rest of the migrated pages.

- **Header:** eyebrow `INSTITUTIONAL VOICE`, H1 `Incident Reports`, subtitle dynamic (counts depend on whether the viewer is a submitter, an assignee, or both), action `[+ New Report]`.
- **Left rail:** category and status filter chips, plus a title search input.
- **Main:** card list, newest first. Each card: category badge, title, 2-line body preview, status pill, submitter avatar+name (visible only to assignees — submitters viewing their own list see no name), `posted X ago`, assignee avatar stack (max 3 + `+N more`).
- **Empty state:** institutional illustration + `"No reports in this view"`.

### 7.3 Page — `Governance/Incidents/Show.vue`

- **Header:** eyebrow `INCIDENT · {category_label}`, H1 title, subtitle author+date+status, action row `[Assign]` / `[Close]` / `[Reopen]` (each gated by policy).
- **Body, 3:2 split on lg+:**
  - **Left:** report body card, then the thread. Messages render as `MessageBubble.vue` with alternating alignment (submitter left, assignee right). Sticky reply composer at the bottom hides when status is `closed`.
  - **Right:** stacked panels — Status timeline, Assignees list (with `Remove` per row), Initial-report attachments.

### 7.4 SlidePanels

- **Assign Reviewer.** Reuses the existing `SlidePanel` component. Body: searchable list of every user holding `incidents.review`; each row has `[Assign]` or `[Remove]` depending on current state.
- **New Report.** Triggered by `[+ New Report]` on the Index page. Fields: category radio chips, title, body, attachments drag-drop (1–3 files). Footer Cancel/Submit. On success: toast `"Your report has been submitted privately"`, redirect to the new report's Show page.

### 7.5 Components

Four new files under `resources/js/Components/Incidents/`:

- `CategoryBadge.vue` — pill, color-keyed (gray=Other, cobalt=Improvement, magenta=Grievance, red=Safety).
- `StatusPill.vue` — same shape as the existing `StatusBadge`, three states.
- `MessageBubble.vue` — single thread message.
- `AttachmentChip.vue` — file chip; download-only in posted state, removable in upload-queue state.

### 7.6 Quick Action

Add `Compose Incident` to the existing Quick Action dropdown in the top bar. Visible to all auth users. Links to `/governance/incidents?new=1` — uses the existing `?new=1` URL-strip pattern (the SlidePanel-stuck-backdrop fix from this codebase's prior incident applies: `onMounted` strips the flag, the form submit uses `preserveState: true, preserveScroll: true`).

### 7.7 Aesthetic

Sovereign Precision direction. Navy/cobalt dominant. Magenta accent reserved for the Grievance category only (people-side 5% accent rule). One gold hairline at the top of the page header. Editorial typography: `font-black tracking-tight` for H1, `uppercase tracking-[0.18em]` for eyebrows, `tabular-nums` for all counts.

## 8. Attachment storage

A new private disk, `incidents`, defined in `config/filesystems.php`:

```php
'incidents' => [
    'driver' => 'local',
    'root'   => storage_path('app/incidents'),
    'visibility' => 'private',
    'throw' => true,
],
```

Path layout: `storage/app/incidents/{report_id}/{uuid}-{original_filename}`. The UUID prefix prevents enumeration; `original_name` is preserved separately in the DB row.

**Validation** (applied in both `StoreIncidentReportRequest` and `StoreIncidentMessageRequest`):
- `attachments` → `array|max:3`
- `attachments.*` → `file|mimes:pdf,png,jpg,jpeg,doc,docx|max:10240`

**Download flow.** `GET /governance/incidents/attachments/{attachment}/download` resolves the parent report via `attachable_type`+`attachable_id`, authorises through `IncidentReportPolicy::view`, and returns `Storage::disk('incidents')->download(...)`. URLs are server-generated per request — revoked access takes effect on the next click.

**Cleanup.** Soft-deleted reports keep attachments on disk (audit). Force-delete (tinker only, no UI) triggers an `Observer::deleting` hook that removes the directory.

## 9. Tests

Pest, per the project's `project_test_patterns` memory: Feature/Unit base classes, per-user JSON `permissions` column for test grants.

### 9.1 `tests/Feature/Governance/IncidentReportTest.php`

- `it_lets_an_employee_submit_an_incident_report`
- `it_rejects_submission_without_a_category`
- `it_rejects_an_attachment_over_10mb`
- `it_persists_attachments_on_the_private_disk`
- `submitter_can_view_their_own_report`
- `unrelated_employee_cannot_view_a_report`
- `super_admin_without_assignment_cannot_view_a_report`
- `assignee_can_view_an_assigned_report`
- `removed_assignee_can_no_longer_view_the_report`
- `only_users_with_incidents_review_can_be_assigned`
- `first_assignment_transitions_status_to_in_review`
- `assignee_can_post_a_message`
- `submitter_can_post_a_message`
- `non_member_cannot_post_a_message`
- `assignee_can_close_a_report`
- `submitter_cannot_close_their_own_report`
- `assignee_can_reopen_a_closed_report`
- `closing_locks_the_thread`
- `attachment_download_requires_view_permission`
- `assigning_a_user_fires_assigned_notification`
- `posting_a_message_notifies_other_circle_members_but_not_author`

### 9.2 `tests/Unit/Policies/IncidentReportPolicyTest.php`

Matrix coverage for each policy method: `view`, `create`, `update`, `close`, `assign`, `postMessage`, `downloadAttachment`. Subject matrix: submitter / current-assignee / removed-assignee / unrelated-employee / super_admin-not-assigned.

### 9.3 Test data convention

Granting `incidents.review` to a fixture user uses the per-user JSON column path:

```php
$reviewer->update(['permissions' => ['incidents.review']]);
```

— consistent with the existing patterns memory.

## 10. Out of scope (deferred to later work)

- Email or SMS notifiers for incidents.
- Auto-close on inactivity timer.
- Public unauthenticated submission (the Whistleblower channel already covers anonymous external reporting).
- HR/super_admin "audit-view" override that bypasses the privacy invariant — the invariant is the feature; if added later, it needs its own design and threat-model review.
- Attachment virus scanning — Laravel storage hooks for ClamAV exist but aren't wired in this codebase yet. Suggest as an institute-wide later task.
- Bulk reassignment, saved filters, or any reporting/analytics export.

## 11. Done criteria

- Every test in §9 passes.
- `npx vite build` clean.
- The four migrations apply and roll back cleanly (`php artisan migrate:rollback`).
- The new `incidents.review` permission appears in the existing RBAC management UI.
- An employee can submit a report, an assigned reviewer can read it and reply, the submitter can read the reply, and closing the report locks the thread — verified manually end-to-end in the dev server.
- Privacy invariant holds: a super_admin who is not an assignee receives 403 on every read endpoint (verified via the dedicated feature test).
