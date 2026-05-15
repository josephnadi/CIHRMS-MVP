# CIHRMS — Phase 2 / WS7: Time & Attendance Delivery

Second-gate in the ghost-worker defense. Phase 1 gave us **verified identity**; this work-stream adds **verified presence**. Payroll now requires both before paying anyone.

## Why this gap was prioritised

Ghana's public-sector wage-bill leakage is approximately 10% per month (Auditor-General, multiple reports). Verified identity alone catches *fake* employees; it doesn't catch *real but never-show-up* employees who pass identity checks but draw a salary without working. This work-stream closes that loop.

The payroll engine in Phase 1 already gated on identity — this WS adds a second gate: **zero recorded attendance in a pay period ⇒ skip with `potential_ghost_worker`**. That single rule is the strongest defensible signal a government auditor can ask for.

## What shipped

### Enums (2)
`AttendanceStatus` (present, late, half_day, absent, on_leave, holiday, weekend), `AttendanceSource` (biometric, gps_mobile, web_kiosk, manual, webhook).

### Migrations (3)
1. `biometric_devices` — registered hardware with per-device HMAC `shared_secret` (encrypted)
2. `public_holidays` — Ghana 2026 statutory holidays with observance rules
3. `attendance_records` + `attendance_summaries` — raw events + daily roll-up

### Models (4)
`BiometricDevice`, `AttendanceRecord`, `AttendanceSummary`, `PublicHoliday`. The holiday model has a cached `observedDatesInYear()` helper so per-day lookups are O(1).

### Services (3)
- **`AttendanceService`** — `record()`, `recomputeDailySummary()`, `aggregatePeriod()`. Derives daily status from raw records using the Ghana public-service default schedule (Mon–Fri 08:00–17:00, 15-min grace, 4-h half-day floor). Honors approved leave requests.
- **`OvertimeCalculator`** — Labour Act 2003 (Act 651) §35:
  - Weekday > 8h: 1.5×
  - Weekend / holiday: 2× from hour 1
  - Beyond 12h on any day: 2×
- **`BiometricIngestionService`** — translates vendor-neutral device payloads (`{device_code, events[]}`) into `AttendanceRecord` rows. ZKTeco / Hikvision / Suprema all fit.

### Webhook & signature
`POST /webhooks/biometric` with HMAC-SHA256 over `{X-Biometric-Timestamp}.{body}`, keyed by the per-device `shared_secret`. Replay protection: ±5-min skew window. The existing `VerifyWebhookSignature` middleware was extended with a `verifyBiometric()` method.

### **Payroll integration — the key change**
`PayrollService::calculate()` now applies a **two-gate skip**:
1. Identity unverified → `skip_reason = "Identity unverified — Ghana Card validation required."` (existing)
2. **Zero working days recorded AND no approved leave → `skip_reason = "No attendance recorded in N working days — potential ghost worker."`** (new)

The attendance period is materialised by `AttendanceService::aggregatePeriod()` so every day in the run window has an explicit summary row (absences become first-class, not implicit).

### Controllers + form requests + policies + resources
- `AttendanceController` (org-wide view, employee self-view, manual entry, self-clock)
- `Webhooks\BiometricWebhookController`
- `ManualAttendanceRequest`, `ClockSelfRequest`
- `AttendancePolicy`
- `AttendanceRecordResource`, `AttendanceSummaryResource`

### Routes
- `GET /attendance` — HR / dept-head view (perm `attendance.view`)
- `GET /attendance/me` — employee self-view
- `POST /attendance/clock` — self clock-in/out with optional GPS (perm `attendance.clock_self`)
- `POST /attendance/manual` — HR manual entry, reason required (perm `attendance.manage`)
- `POST /webhooks/biometric` — signed device webhook
- `GET /modules/attendance` — now redirects to `attendance.index` (was a static placeholder)

### Permissions
3 new: `attendance.view`, `attendance.manage`, `attendance.clock_self`. Granted to the seven existing roles with appropriate scope.

### Seeders
- `GhanaPublicHolidaySeeder` — 13 Ghana 2026 statutory holidays with Sunday-shift observance rule
- `BiometricDeviceDemoSeeder` — 2 demo ZKTeco / Hikvision devices with rotated shared-secrets

### Vue pages (2)
- **`Attendance/Index.vue`** — HR overview with today-stats, paginated daily summaries, manual-entry slide-panel (replaces the prior static placeholder)
- **`Attendance/MyAttendance.vue`** — employee self-view with clock-in/out buttons (with optional `navigator.geolocation`) and monthly summary

### Tests (4 files, 17 test cases)
- `AttendanceServiceTest` — 8 cases: present/late/half-day/absent/weekend/holiday derivation, manual-reason enforcement, period aggregation
- `OvertimeCalculatorTest` — 5 cases: normal 8h, 1.5× weekday OT, 2× weekend, 2× holiday, deep OT > 12h
- `BiometricWebhookTest` — 4 cases: valid signed accept, unsigned reject, wrong-secret reject, replay reject
- `PayrollAttendanceGateTest` — 2 cases: zero-attendance skip, single-day-attendance pays

## Files (24 new + 5 modified)

```
NEW
app/Enums/AttendanceSource.php
app/Enums/AttendanceStatus.php
app/Http/Controllers/AttendanceController.php
app/Http/Controllers/Webhooks/BiometricWebhookController.php
app/Http/Requests/Attendance/ClockSelfRequest.php
app/Http/Requests/Attendance/ManualAttendanceRequest.php
app/Http/Resources/AttendanceRecordResource.php
app/Http/Resources/AttendanceSummaryResource.php
app/Models/AttendanceRecord.php
app/Models/AttendanceSummary.php
app/Models/BiometricDevice.php
app/Models/PublicHoliday.php
app/Policies/AttendancePolicy.php
app/Services/Attendance/AttendanceService.php
app/Services/Attendance/BiometricIngestionService.php
app/Services/Attendance/OvertimeCalculator.php
database/migrations/2026_05_26_000001_create_biometric_devices.php
database/migrations/2026_05_26_000002_create_public_holidays.php
database/migrations/2026_05_26_000003_create_attendance_records.php
database/seeders/BiometricDeviceDemoSeeder.php
database/seeders/GhanaPublicHolidaySeeder.php
resources/js/Pages/Attendance/MyAttendance.vue
tests/Feature/Attendance/AttendanceServiceTest.php
tests/Feature/Attendance/BiometricWebhookTest.php
tests/Feature/Attendance/OvertimeCalculatorTest.php
tests/Feature/Payroll/PayrollAttendanceGateTest.php

MODIFIED
app/Http/Middleware/VerifyWebhookSignature.php           (added verifyBiometric)
app/Providers/AppServiceProvider.php                     (registered services + policy)
app/Services/Payroll/PayrollService.php                  (zero-attendance gate)
database/seeders/DatabaseSeeder.php                      (chained new seeders)
database/seeders/RolePermissionSeeder.php                (3 new permissions + 5 role grants)
resources/js/Pages/Attendance/Index.vue                  (replaced static placeholder)
routes/web.php                                           (4 attendance routes + biometric webhook)
```

## How to run

```bash
php artisan migrate
php artisan db:seed --class=GhanaPublicHolidaySeeder
php artisan db:seed --class=BiometricDeviceDemoSeeder
php artisan test --filter='Attendance|PayrollAttendanceGate'
```

## Acceptance criteria

| # | Gate | Status |
|---|---|---|
| 1 | Biometric devices register, store an encrypted HMAC secret | ✅ |
| 2 | Webhook accepts properly signed events, rejects unsigned/wrong-secret/replay | ✅ tests |
| 3 | Attendance status derives correctly from raw events (present/late/half/absent/weekend/holiday) | ✅ tests |
| 4 | Overtime calculator matches Labour Act §35 rules | ✅ tests |
| 5 | Payroll engine **skips zero-attendance employees** with audit-clear reason | ✅ test |
| 6 | Approved leave does NOT count as zero attendance | ✅ handled in `deriveStatus` |
| 7 | Public holidays are pre-seeded for Ghana 2026 | ✅ 13 holidays |
| 8 | HR view, self-view, and webhook all in place | ✅ |

## Honest gaps & follow-ups (deferred)

1. **Partial-month attendance proration in payroll** — currently the gate is binary (zero days = skip, ≥1 day = pay full). True proration (`days_worked / working_days`) is a one-line addition to `calculateLine()` once policy is locked.
2. **GPS geofence enforcement on self-clock** — coordinates are captured but not yet compared against `biometric_devices.geo_radius_m`. Tracked as the obvious next addition.
3. **Shifts / rosters** — the calculator assumes a single Mon–Fri 08:00–17:00 schedule. Multi-shift support (hospitals, security, utilities) is its own work-stream.
4. **Vendor-native webhook adapters** — current ingestion is vendor-neutral (`{device_code, events[]}`). ZKTeco's PUSH SDK and Hikvision's HCNetSDK each have their own payload shapes that would benefit from per-vendor mappers, but the generic format is enough for pilot.
5. **Overtime → payroll line** — calculator computes premium hours; they're stored in `attendance_summaries.overtime_hours`. The payroll engine doesn't yet read this into a salary supplement. One follow-up commit.
6. **Real-time dashboard** — current attendance pages refresh on navigation; a Reverb/Pusher live tile is a small follow-up.
