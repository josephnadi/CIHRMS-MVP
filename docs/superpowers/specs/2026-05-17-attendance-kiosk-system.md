# Attendance Kiosk System — Design Spec

**Date:** 2026-05-17
**Status:** Draft (design phase). Pending sign-off before implementation plan.
**Author:** CIHRMS team via Claude Code

---

## 1. Problem & Goal

CIHRMS today supports clock-in two ways:

1. **In-portal self-clock** at `/attendance/me` — each staff member logs into their own session, clicks Clock In, optionally captures GPS.
2. **External biometric ingest** at `POST /webhooks/biometric` — a physical fingerprint/face device POSTs HMAC-signed punches.

Neither fits the "shared device at the gate" scenario: a single tablet or terminal at reception where every employee taps in on arrival. Forcing each employee to sign into their session on the shared device leaks credentials and is slow.

**Goal:** A dedicated kiosk surface at `/kiosk` that runs on one shared device with no per-user session, identifies the employee per-clock (Staff ID + Name in v1; face match in v2), records the punch through the existing `AttendanceService` with `source = web_kiosk`, and surfaces the result in the employee's own portal — all on the same data path as biometric hardware so the physical device drop-in later is a no-op.

## 2. In Scope (v1)

1. **Public kiosk page** at `/kiosk` — full-screen, no chrome, no sidebar, branded
2. **Two-step identify → confirm flow** — Staff ID + Name match → confirmation panel showing photo + last punch + suggested direction → big Clock In/Out button
3. **No session retained** — every clock starts fresh; the page auto-resets after a confirmation moment
4. **Throttled & device-aware** — `throttle:60,1` already in place; spec adds an optional `attendance_devices` table for per-kiosk identity in production
5. **Punch routes through `AttendanceService`** with `source = AttendanceSource::WebKiosk` so daily summaries / lateness / overtime work identically to any other source
6. **Live clock display** — large WCAG-AA time/date so employees can see what they're punching at
7. **Result moment** — 5-second "Welcome, Ama. Clocked in at 08:02" confirmation, then auto-return to the lookup screen
8. **Today's wall** — optional rolling list of the last N kiosk punches (read-only ticker) for social-proof feedback that the device is "live"

## 3. Out of Scope (deferred to v2+)

| Feature | Reason |
|---|---|
| Face recognition match | Spec'd in §11 but implementation lands in v2 (route + 501 stub already exists) |
| Per-kiosk admin dashboard | A future Admin/Kiosks index for pairing/decommissioning devices |
| Anti-tailgating photo capture | Snap-and-store a photo on every punch as a v2 hardening step |
| PIN authentication | Considered then rejected — not enough security gain to justify slowing the line; face-rec is the next hardening step |
| Offline queue | Kiosk is online-only in v1; offline support is a v2 PWA story |
| Hardware fingerprint plug-in | Out — the existing `BiometricWebhookController` already handles real hardware via HMAC-signed POST |
| Geofence on the kiosk itself | The kiosk is by definition fixed-location; no GPS needed |

## 4. User Flows

### 4.1 Clock-in flow (lookup → confirm → punch)
1. Employee walks up to the kiosk on `/kiosk`.
2. Screen shows the live clock, ambient hero, and two large input fields: **Staff ID** (`GH-HR-001`) and **First or last name**.
3. Employee types ID + a portion of their name, taps **Continue**.
4. Front-end POSTs to `/kiosk/verify` — server runs the loose name match (already implemented in `KioskController::matchEmployee`).
5. On match: front-end shows the **confirmation panel** — employee's photo, full name, position, last punch today, and a suggested direction (In if no prior punch today or last was Out; Out otherwise). Two buttons: **Clock In** / **Clock Out** (the suggested direction is highlighted; the other is available as an override).
6. Employee taps one. Front-end POSTs `direction` to `/kiosk/clock`.
7. Server records via `AttendanceService::record(..., source: WebKiosk)`. Returns the new record.
8. Front-end shows **result moment** — large "✓ Clocked in · 08:02" with the employee's name; auto-dismisses after 5 s back to the lookup screen.
9. On no-match: lookup screen shakes, error toast "Employee ID or name did not match"; lookup fields keep focus.

### 4.2 Today's wall (passive feedback)
- Below the lookup form, a short rolling list of the last 8 kiosk punches today (first name + direction + time only). Pure read-only; refreshes every 15 s via Inertia partial reload of a single `recentPunches` prop.
- Doubles as device-liveness indicator — if HR notices the wall hasn't updated all morning, the kiosk is offline.

### 4.3 Employee's own portal
- No flow change. The new `AttendanceRecord` rows show up in `/attendance/me`, daily summaries, and any reporting that reads `attendance_records` — `source = web_kiosk` is the only difference.

### 4.4 Admin oversight
- Read-only filter on `/attendance` for `source = web_kiosk` so HR can audit kiosk-originated punches.
- Audit-log entry on every `/kiosk/clock` POST (already covered by the `AuditTrail` middleware if applied; spec verifies and adds it if not).

## 5. Schema

### 5.1 Tables touched

**No new tables required for v1.** All punches land in the existing `attendance_records` table with `source = 'web_kiosk'`.

**Optional v1.5 / v2:** `attendance_devices` table for per-kiosk identity:

```
attendance_devices
  id                  bigint pk
  uuid                uuid    unique           -- exposed as the kiosk identifier
  name                string                   -- "Main Gate Tablet"
  location            string  nullable         -- "Block A reception"
  paired_at           timestamp nullable
  paired_by           bigint  fk users.id nullable
  paired_token_hash   string  nullable         -- sha256 of the bearer token issued at pairing
  last_seen_at        timestamp nullable
  last_seen_ip        string  nullable
  is_active           bool    default true
  created_at, updated_at, deleted_at
  index (uuid), index (is_active)
```

When the table exists, the kiosk middleware checks the `X-Kiosk-Device` header against `paired_token_hash` and rejects if invalid. **In v1 the table is not created** — `/kiosk/*` is rate-limited public; the device-pairing layer is a v1.5 hardening step that can land without breaking the page.

### 5.2 No status enum changes

`AttendanceSource::WebKiosk` already exists ([app/Enums/AttendanceSource.php](../../../app/Enums/AttendanceSource.php)) and is recognised by `AttendanceService::record()`. Daily summaries, lateness rules, and overtime calculation already key off `attendance_records.event_at` regardless of source.

## 6. Routes

Already declared in [routes/web.php](../../../routes/web.php) lines 140–148:

```php
Route::prefix('kiosk')->name('kiosk.')->middleware('throttle:60,1')->group(function () {
    Route::get('/',        [KioskController::class, 'show'])->name('show');
    Route::post('/verify', [KioskController::class, 'verify'])->name('verify');
    Route::post('/clock',  [KioskController::class, 'clock'])->name('clock');
    Route::post('/face',   [KioskController::class, 'clockByFace'])->name('face');
});
```

Spec adds **one route** for the today's-wall partial reload (so it stays inside the same group + throttle):

| Verb | Path | Action | Notes |
|---|---|---|---|
| GET | `/kiosk/recent` | `KioskController@recent` | Returns the last 8 kiosk punches today as JSON; called every 15 s by the page. First names + direction + event_at only — no PII beyond what the device's own user can already see by looking at the wall. |

When the device-pairing layer lands (§9), the group's middleware gains an `EnsureKioskDevice` middleware after `throttle`. The routes themselves don't change.

## 7. Frontend

### 7.1 Pages

- **`resources/js/Pages/Kiosk/Index.vue`** — the only page. Full-screen, no `<AuthenticatedLayout>` or `<GuestLayout>` wrapper. Renders into a custom minimal shell defined inline:
  - Top: institutional logo + live clock + date (cobalt/navy heading band)
  - Middle: state machine between **lookup**, **confirm**, **result** — animated transitions
  - Bottom: today's wall ticker (read-only)
  - Side: ambient blue/cobalt mesh + gold hairline accent (Sovereign Precision; single 5% moment on the page is the gold "Clocked in" check on the result screen)
  - No sidebar, no header — this device only exists to clock in/out

### 7.2 Components

- **`Components/Kiosk/LookupForm.vue`** — Staff ID + Name fields with auto-uppercase on ID, large 56px-tall inputs, big primary button.
- **`Components/Kiosk/ConfirmPanel.vue`** — employee photo (96px), name (2xl), position, last punch summary, two big direction buttons (In / Out) — one highlighted as suggested. 30-second timeout that auto-resets to lookup if no decision (so the kiosk doesn't sit on a stranger's photo).
- **`Components/Kiosk/ResultMoment.vue`** — full-bleed success/error confirmation with a 5 s countdown; tap-anywhere dismisses early.
- **`Components/Kiosk/LiveClock.vue`** — re-uses the existing live-clock pattern from `MyAttendance.vue` (already proven UX).
- **`Components/Kiosk/RecentPunches.vue`** — read-only ticker; horizontally scrolling list of first names + direction + time. Polls `/kiosk/recent` every 15 s via Inertia partial reload.

### 7.3 No sidebar entry

Kiosk is **not** linked from the sidebar (the kiosk shell has no sidebar, and authenticated users have their own `/attendance/me` page). An admin on the authenticated app who wants to inspect the kiosk just visits `/kiosk` directly — a future Admin / Kiosks page (out of scope) will surface registered devices and let HR open this URL in a new tab for setup.

### 7.4 Accessibility

- Lookup inputs are `text-[28px]` minimum — usable from a metre away.
- Confirm-panel buttons are 80px tall, 240px wide minimum — touch-targets exceed WCAG 2.5.5 AAA.
- Live region announces "Clocked in" or "Did not match" on every state change (already wired via `useAriaAnnounce`).
- Screen reader fallback navigation works (the lookup state always autofocuses the Staff ID field).

## 8. Backend

### 8.1 Already implemented ([app/Http/Controllers/KioskController.php](../../../app/Http/Controllers/KioskController.php))

- `show()` — renders `Kiosk/Index.vue` with `serverTime`. ✅
- `verify()` — runs the loose name match, returns employee summary or 422. ✅
- `clock()` — records via `AttendanceService->record(source: WebKiosk)`, returns the saved record. ✅
- `clockByFace()` — 501 stub. ✅
- `matchEmployee()` — normalised, case-insensitive, 2-char min, substring match. ✅
- `presentEmployee()` — photo URL, last today punch, suggested direction. ✅

### 8.2 To add for v1

- **`KioskController::recent()`** — returns last 8 today-kiosk punches as JSON `{first_name, direction, event_at}`. No employee_no, no full name, no position — just enough for the social-proof wall.
- **Form Requests** are already in place ([`KioskVerifyRequest`](../../../app/Http/Requests/Attendance/KioskVerifyRequest.php), [`KioskClockRequest`](../../../app/Http/Requests/Attendance/KioskClockRequest.php)). Spec leaves them untouched.
- **Audit trail verification** — add `audit` middleware to the route group if not already covered globally; otherwise no change.

### 8.3 Listeners / Events

- The existing `AttendanceClockedIn` / `AttendanceClockedOut` events fire from `AttendanceService` regardless of source. No new events needed.
- No new notification — the punch shows up in the employee's portal next time they look; we don't push them an SMS for kiosk punches (would be noisy and the employee just walked past the device).

## 9. Device pairing (v1.5 hardening, optional)

Today the `/kiosk/*` routes are public + throttled at `60/min`. That's fine for a controlled environment but in production we want positive identification of which device is punching.

**Proposed when production rollout starts:**

1. Admin adds a row to `attendance_devices` via a future `/admin/kiosks` page (out of scope for v1).
2. Pairing flow: admin generates a one-time pairing code; opens the kiosk URL on the device; types the code; device stores the issued bearer token in `localStorage` keyed by `cihrms.kiosk.deviceToken`.
3. Front-end attaches `X-Kiosk-Device: <token>` to every kiosk POST.
4. New `EnsureKioskDevice` middleware on the route group checks the token's `sha256` against `attendance_devices.paired_token_hash` (active rows only); on success, updates `last_seen_at` / `last_seen_ip`; on failure, 401 + auto-clear of the device's `localStorage`.
5. Punches optionally write `attendance_records.device_id` (new nullable FK) so HR can audit which kiosk a punch came from.

The v1 implementation must not bake assumptions that prevent this — i.e. middleware ordering must permit injecting `EnsureKioskDevice` after `throttle` later, and the page must already namespace its `localStorage` keys.

## 10. Face recognition (v2)

The route `POST /kiosk/face` and controller stub `KioskController::clockByFace` already return 501. The v2 work:

### 10.1 Decision: browser-side vs server-side

| Approach | Pros | Cons | Cost |
|---|---|---|---|
| **A. Browser-side (face-api.js or MediaPipe)** | Free, no per-call cost, no PII over the wire, no server compute | ~3 MB model download on first load; less accurate; needs each employee's face template baked into the page | One-time eng |
| **B. Server-side managed (AWS Rekognition / Azure Face)** | High accuracy; managed SLA; standard "compare faces" API | Per-call $$; PII (face images) leaving the institutional perimeter — needs DPA Article 17 review | $$ per match |
| **C. Server-side self-hosted (insightface / deepface in Python sidecar)** | High accuracy + privacy; no per-call cost | Adds a Python service to the deployment; templates table to maintain; eng cost | More eng + ops |

**Recommended: A (browser-side) for v2.** Reason: the kiosk is a controlled, well-lit environment where face-api.js's accuracy is adequate; zero per-call cost; templates never leave the device. If accuracy proves insufficient in field testing, escalate to C.

### 10.2 Flow (when v2 lands)

1. Employee approaches kiosk. Tapping "Scan face" instead of typing.
2. `<video>` element captures a frame; face-api.js extracts a 128-d descriptor.
3. Front-end POSTs the descriptor (not the image) to `/kiosk/face`.
4. Server compares against pre-computed `employee_face_templates.descriptor` rows using cosine distance; threshold 0.5 (configurable).
5. Closest match below threshold → returns employee summary identical to `/kiosk/verify`. UI proceeds to the confirm panel as if the lookup completed.
6. No match → "Face not recognised — please use Staff ID + Name".

### 10.3 Template enrolment

Out of scope for this spec. Sketch: an authenticated flow under `/profile` where the employee enrols their face (3 frames; descriptors stored), with explicit consent recorded against DPA Article 17.

### 10.4 Privacy

- Descriptors are mathematical vectors, not images. They cannot be reversed into a usable photo.
- Storage in `employee_face_templates.descriptor` (BLOB or JSON column).
- Right-to-erasure deletes the template row.
- Consent record stored at enrolment time and surfaced in the DPA admin queue.

## 11. Storage

- No new storage in v1 (no images saved per-punch).
- Avatar URLs come from the existing `employee.avatar_url` accessor (root-relative path; already fixed in this session).
- Face templates (v2): `employee_face_templates` table with a descriptor JSON column. No image files saved.

## 12. Audit & integrity

- Every `/kiosk/clock` POST is captured by the existing `AuditTrail` middleware (request_path, ip, user_id is null for kiosk so `device_id` is the audit anchor once pairing lands).
- `attendance_records.source = web_kiosk` is the durable trail — daily summaries already group by source.
- A future v2 Admin/Kiosks page will show per-device punch counts so HR can spot anomalies (e.g. one tablet suddenly punching 200 events/hour suggests a broken auto-clicker, not real activity).

## 13. Dependencies

### v1
- No new composer or npm packages. The page uses Vue 3 + Inertia + Tailwind already in the stack.

### v2 (face-rec)
- npm: `face-api.js` (~3 MB) — only loaded on the kiosk page, not the rest of the app.
- Composer: none (descriptors are just JSON arrays we compare in PHP).

## 14. Code structure

```
app/
├── Http/Controllers/KioskController.php           ✅ exists
├── Http/Requests/Attendance/
│   ├── KioskVerifyRequest.php                     ✅ exists
│   └── KioskClockRequest.php                      ✅ exists
└── Enums/AttendanceSource.php                     ✅ WebKiosk value present

resources/js/
├── Pages/Kiosk/
│   └── Index.vue                                  ⬅ NEW
└── Components/Kiosk/
    ├── LookupForm.vue                             ⬅ NEW
    ├── ConfirmPanel.vue                           ⬅ NEW
    ├── ResultMoment.vue                           ⬅ NEW
    ├── LiveClock.vue                              ⬅ NEW
    └── RecentPunches.vue                          ⬅ NEW

routes/web.php
└── (add `/kiosk/recent` GET inside the existing kiosk group)

tests/Feature/Kiosk/
├── KioskLookupTest.php                            ⬅ NEW
├── KioskClockTest.php                             ⬅ NEW
└── KioskRecentTest.php                            ⬅ NEW
```

## 15. Defaults (locked-in choices)

| Choice | Value |
|---|---|
| Auth model (v1) | Public + `throttle:60,1`; per-device tokens deferred to v1.5 |
| Identification (v1) | Staff ID + Name loose substring match (already implemented) |
| Identification (v2) | Browser-side face-api.js with 128-d descriptors stored server-side |
| Direction suggestion | Last punch was In → suggest Out; otherwise In |
| Confirm panel timeout | 30 s auto-reset to lookup |
| Result moment timeout | 5 s auto-dismiss back to lookup |
| Today's wall length | 8 most recent kiosk punches today |
| Today's wall refresh | 15 s polling via Inertia partial reload |
| Page chrome | Full-screen, no sidebar, no header — kiosk-only |
| Touch targets | ≥ 80 px tall on action buttons (WCAG 2.5.5 AAA) |
| Input font size | ≥ 28 px on lookup fields |
| Layout component | None — custom inline shell (`/kiosk` is intentionally not under AuthenticatedLayout) |
| Sidebar entry | None — kiosk is not reached from inside the app |
| Notifications | None — punches are silent on the kiosk; the employee sees them in their own portal next visit |
| Audit trail | Existing `AuditTrail` middleware; `device_id` once pairing layer lands |

## 16. Testing strategy

### Pest feature tests
- `KioskLookupTest`:
  - Valid Staff ID + matching name fragment → 200 + employee payload
  - Valid Staff ID + non-matching name → 422
  - Unknown Staff ID → 422
  - Single-character name → 422 (the 2-char minimum guard)
  - Diacritic / case differences → still matches (normalize() coverage)
- `KioskClockTest`:
  - Clock In → `attendance_records` row created with `source = web_kiosk`, direction = `in`
  - Clock Out → direction = `out`
  - Same employee back-to-back same direction → 422 with the AttendanceService domain exception
  - Match failure → 422, no record written
  - Throttle limit → 429 after 60 requests in a minute
- `KioskRecentTest`:
  - Returns last 8 today-kiosk punches in descending event_at order
  - Excludes non-kiosk-source punches
  - Excludes yesterday's punches
  - First names only — no Staff ID, no full name in the payload
- (v2) `KioskFaceTest`:
  - Descriptor match within threshold → 200
  - Descriptor match outside threshold → 422
  - No templates enrolled for any employee → 422

### Manual QA checklist
- Tablet (iPad / Android) at the gate position: lookup → confirm → punch flow under 5 seconds
- Two employees back-to-back without page reload — kiosk auto-resets between them
- Wrong name typed three times in a row → still works (no soft-lock)
- Power-cycle the kiosk mid-flow → lookup screen restores cleanly
- Live clock keeps ticking during idle hours (no memory leak on 8 h dwell)

## 17. Migration / rollback

- v1: no migrations. Pure frontend addition + one new GET route.
- v1.5 (when added): one migration `create_attendance_devices_table`; safely rollback-able because no existing data references it.
- v2 (face): two migrations — `create_employee_face_templates_table` + add `consented_at` to the same table. Rollback drops the table — no impact on `attendance_records`.

## 18. Open risks

1. **Lost productivity if the device crashes.** Mitigation: today's wall doubles as a liveness indicator. Future v2: a small heartbeat ping endpoint + dashboard alarm.
2. **Spoofing via knowing someone's Staff ID.** Real risk in v1. Mitigation in v2: face match. Until then, the audit trail (`device_id` once pairing lands, `ip`, `event_at`) plus the fact that physical presence at the device is required is the deterrent.
3. **Browser zoom / address-bar leakage.** Mitigation: instruct the device to be locked to Chrome kiosk mode (`--kiosk` flag) or Android device-owner mode at deployment.
4. **face-api.js model accuracy in poor lighting.** Mitigation: pilot v2 with a small group; if accuracy < 95%, escalate to self-hosted insightface.
5. **DPA Article 17 review for face templates.** Mitigation: enrolment is opt-in, descriptors are mathematical (not images), erasure cascades on user delete, consent recorded.

## 19. Acceptance criteria (v1)

1. A staff member walks up to `/kiosk` on a shared tablet.
2. They type their Staff ID (`GH-HR-001`) and "Kwame" (their first name) and tap Continue.
3. Within 1 second the confirm panel shows their photo, full name, position, last today punch (or "no punches today"), and a highlighted suggested direction.
4. They tap **Clock In**.
5. A success moment appears: "✓ Welcome, Kwame Boateng · Clocked in at 08:02".
6. After 5 s the page auto-resets to the lookup screen.
7. The new `attendance_records` row exists with `source = 'web_kiosk'`, `direction = 'in'`, `event_at` close to now.
8. The same staff member opens `/attendance/me` later in the day and sees the 08:02 entry in their daily punches list.
9. The today's wall ticker shows "Kwame · in · 08:02" at the bottom of the kiosk.
10. A second employee can clock in immediately after without page reload.
11. A non-matching name returns "Employee ID or name did not match" and the lookup fields stay focused.
12. `POST /kiosk/face` returns 501 (face-rec not yet enabled).

## 20. Future work (v2+)

- **Face recognition** match per §10 — browser-side via face-api.js
- **Per-kiosk device pairing** per §9 — `attendance_devices` table + middleware
- **Admin/Kiosks page** — register, pair, decommission devices; per-device punch stats
- **Photo-on-punch** — optional snap-and-store (jpeg, 200 px) per clock as anti-tailgating evidence; opt-in per kiosk
- **Offline queue** — PWA service worker buffers punches when the LAN drops; flushes on reconnect
- **Multi-kiosk sync** — display the same today's wall across multiple kiosks via Echo / Reverb broadcasting
- **Hardware fingerprint plug-in** — already supported via `/webhooks/biometric` HMAC endpoint; spec only flags this so engineering remembers the path

---

## Appendix A — Existing surfaces this spec interacts with

| Surface | Path | Role |
|---|---|---|
| AttendanceService | `app/Services/Attendance/AttendanceService.php` | `record(employee, eventAt, direction, source)` — already accepts `WebKiosk` |
| AttendanceSource enum | `app/Enums/AttendanceSource.php` | `WebKiosk = 'web_kiosk'` already present |
| KioskController | `app/Http/Controllers/KioskController.php` | `show`, `verify`, `clock`, `clockByFace` already in place |
| Kiosk Form Requests | `app/Http/Requests/Attendance/KioskVerify/Clock` | Validation already wired |
| Kiosk routes | `routes/web.php` lines 140–148 | Group + throttle already in place |
| Biometric webhook | `routes/web.php` line 117, `BiometricWebhookController` | Pre-existing HMAC endpoint for physical devices; this spec does **not** modify it |
| MyAttendance page | `resources/js/Pages/Attendance/MyAttendance.vue` | Where the kiosk punch shows up in the employee's view; no change |
| Avatar URL accessor | `app/Models/Employee.php::avatarUrl()` | Returns root-relative `/storage/avatars/...`; consumed by `presentEmployee()` |
| AuditTrail middleware | `app/Http/Middleware/AuditTrail.php` | Captures every `/kiosk/clock` POST |
