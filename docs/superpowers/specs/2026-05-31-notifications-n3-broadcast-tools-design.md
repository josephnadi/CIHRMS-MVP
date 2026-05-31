# Notifications N3 — Admin Broadcast Tools

## Context

Notifications v2 final phase. N1 (PR #71) shipped the async SMS dispatcher with retries, exhaustion alerts, and a registered `sms:marketing` rate limiter. N2 (PR #72) wired 15 dangling domain events to per-module listeners. Both phases were event-driven — system fires a notification when something happens.

N3 inverts the trigger direction: an **admin clicks a button** and a notification fans out to a pre-defined audience. Use cases:

- "Annual Member Dues 2026 are now billed — please log in to your portal to pay" (annual)
- "Office closed 2026-12-25 for Christmas" (one-off scheduled)
- "AGM is tomorrow at the Accra Marriott, 09:00" (one-off urgent)
- "Your payslip for May is available" (already handled by N2, but bulk re-send for stragglers)

This is the surface CIHRM's admin team will use **most** outside of the regular HR workflows. It has to be safe (no accidental send-all), reusable (broadcasts repeat), trackable (who sent what to whom), and tight (no template-engine RCE).

## Goals

1. Admin can send an SMS, email, or both to a pre-defined audience without typing anyone's name.
2. Admin can save body text as a template and reuse it (with per-recipient variable interpolation).
3. Admin can schedule a broadcast for a future datetime; scheduler fires it automatically.
4. The N1 `sms:marketing` rate limiter throttles by default; an explicit override (with reason) bypasses it for emergencies.
5. Every broadcast records a per-recipient outcome row so admins can verify delivery.

## Non-goals

- Per-recipient unsubscribe links. Subscription state is global, not per-broadcast.
- Two-way reply tracking. Broadcasts are one-way.
- Rich HTML email layouts. Mail body is plain text with `{{var}}` interpolation; matches existing `MailMessage` conventions.
- Per-channel preferences enforcement at the audience level. Recipients with no phone get the mail leg only; with no email get the SMS leg only. Nothing else is filtered.
- A free-form query builder for audiences. Audiences are a finite enum; admins pick from a list.

## Architecture

```
Admin → /admin/messaging/broadcasts/create (Composer)
   │
   ├─ Pick AudienceType + params  → live count + sample 10 recipients
   ├─ Pick channels: SMS | Mail | both
   ├─ Pick saved template (filtered by audience compat) OR write inline body
   ├─ Preview: render body for sample recipient
   ├─ Schedule: send now OR schedule_at datetime
   └─ Override throttle (only if user has broadcasts.bypass_throttle perm)
       │
       ▼
   POST /admin/messaging/broadcasts → BroadcastController::store
       ├─ Validate via SendBroadcastRequest
       └─ BroadcastService::queue($broadcast)
           ├─ Insert broadcasts row (status=Queued or Scheduled)
           └─ If immediate: DispatchBroadcastJob::dispatch($broadcast->id)
                If scheduled: scheduler picks up at scheduled_at

DispatchBroadcastJob::handle()
   ├─ Refresh broadcast row; bail if status !== Queued (idempotency)
   ├─ Update status: Queued → Sending; set started_at
   ├─ AudienceResolver::resolve($type, $params) → Eloquent Builder
   ├─ chunkById(100, fn ($chunk) => $chunk->each(...))
   │   For each recipient:
   │     ├─ Skip if BroadcastRecipient row already exists (retry-safe)
   │     ├─ TemplateRenderer::render($body, $recipient, $type)
   │     ├─ SMS leg (if 'sms' in channels AND recipient has phone):
   │     │     - If !throttle_overridden AND RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5):
   │     │           insert recipient row with sms_status=Throttled, skip SMS
   │     │     - Else: SmsDispatcher::send($phone, $smsBody,
   │     │             contextType:'broadcast', contextId:$broadcast->id)
   │     │           insert recipient row with sms_status=Sent + sms_message_id
   │     ├─ Mail leg (if 'mail' in channels AND recipient has email):
   │     │     - Mail::raw($mailBody, fn ($m) => $m->to($email)->subject($subject))
   │     │       (driver-queued)
   │     │     - Update recipient row mail_status=Sent (or Failed + reason)
   │     └─ Update broadcast counters
   ├─ On chunk failure: log + continue (don't abort whole broadcast)
   └─ Set status: Sending → Completed; completed_at = now()

scheduler (every minute):
   messaging:fire-due-broadcasts
   ├─ Find broadcasts where status=Scheduled AND scheduled_at <= now()
   └─ For each: flip status to Queued + dispatch DispatchBroadcastJob
```

The chain is deliberately built on top of N1's queued `SmsDispatcher`, so the SMS leg inherits all of N1's reliability (retries, transient/permanent classification, exhaustion alerts).

## Data model

### `broadcasts` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `title` | string(150) | Admin-facing name e.g. "Annual Dues 2026 nudge" |
| `audience_type` | string(64) | `BroadcastAudienceType` enum |
| `audience_params` | json | Params for the audience (`{class:'Professional'}`, `{department_id:5}`, `{permission:'payroll.view'}`, or `{}`) |
| `channels` | json | Array of `BroadcastChannel` values, e.g. `['sms','mail']` |
| `template_id` | bigint nullable FK | `broadcast_templates.id` if reusing a saved template |
| `sms_body` | text nullable | Inline SMS body (used when no template) |
| `mail_subject` | string(150) nullable | Inline mail subject |
| `mail_body` | text nullable | Inline mail body |
| `scheduled_at` | datetime nullable | When to fire; null = send immediately |
| `throttle_overridden` | bool default false | True if admin bypassed `sms:marketing` |
| `throttle_override_reason` | string(255) nullable | Required when `throttle_overridden=true` |
| `status` | string(32) | `BroadcastStatus` enum |
| `created_by` | bigint FK | `users.id` |
| `started_at` | datetime nullable | When the job actually began |
| `completed_at` | datetime nullable | When the job finished |
| `recipient_count` | int default 0 | Total audience size at dispatch time |
| `sms_sent_count` | int default 0 | |
| `sms_failed_count` | int default 0 | |
| `sms_throttled_count` | int default 0 | |
| `mail_sent_count` | int default 0 | |
| `mail_failed_count` | int default 0 | |
| `deleted_at` | datetime nullable | soft delete |
| `created_at`, `updated_at` | datetime | |

Index: `(status, scheduled_at)` for the scheduler query.

### `broadcast_templates` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `name` | string(150) | "Annual Dues Reminder" |
| `audience_type` | string(64) | Locks the variable whitelist at save time |
| `sms_body` | text nullable | Either both legs or one (mail-only templates allowed) |
| `mail_subject` | string(150) nullable | |
| `mail_body` | text nullable | |
| `is_active` | bool default true | Inactive templates hidden from composer picker |
| `created_by` | bigint FK | |
| `created_at`, `updated_at` | datetime | |

### `broadcast_recipients` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `broadcast_id` | bigint FK | |
| `recipient_type` | string(32) | `App\Models\Member` / `Employee` / `User` |
| `recipient_id` | bigint | |
| `sms_message_id` | bigint nullable FK | `sms_messages.id` (links to N1's row) |
| `sms_status` | string(16) nullable | `Sent`, `Failed`, `Throttled`, `Skipped` (no phone) |
| `mail_status` | string(16) nullable | `Sent`, `Failed`, `Skipped` (no email) |
| `mail_failure_reason` | text nullable | |
| `created_at` | datetime | |

Unique: `(broadcast_id, recipient_type, recipient_id)` — idempotency guard.

## Enums (new under `app/Enums/`)

### `BroadcastStatus`
`Draft`, `Scheduled`, `Queued`, `Sending`, `Completed`, `Failed`, `Cancelled`

State transitions:
- `Draft` → admin saves but doesn't send (future: not in N3 scope but the status reserves the slot)
- `Scheduled` → has `scheduled_at > now()`; scheduler will flip it
- `Queued` → about to dispatch; job picks it up
- `Sending` → job running
- `Completed` → finished successfully (may have per-recipient failures)
- `Failed` → job exhausted retries
- `Cancelled` → admin cancelled a Scheduled broadcast

### `BroadcastChannel`
`Sms`, `Mail`

### `BroadcastAudienceType`
`AllActiveMembers`, `MembersByClass`, `MembersWithOutstandingFees`, `AllActiveEmployees`, `EmployeesByDepartment`, `UsersByPermission`

## Permissions (new)

Added to `App\Enums\Permission` and seeded by `RolePermissionSeeder`:

- `broadcasts.view` — read history + recipient outcomes
- `broadcasts.manage` — compose, schedule, send, cancel
- `broadcasts.bypass_throttle` — needed to use the override checkbox

Default grants: `messaging.manage` holders get all three (effectively `hr_admin`, `super_admin`, `ceo`). `messaging.view` holders get `broadcasts.view`.

## Services (new under `app/Services/Messaging/Broadcasts/`)

### `AudienceResolver`

```php
public function resolve(BroadcastAudienceType $type, array $params): Builder
```

Returns an Eloquent Builder (not a Collection) so `DispatchBroadcastJob` can `chunkById(100)`. Per-type logic:

| Type | Builder |
|---|---|
| `AllActiveMembers` | `Member::where('status', MemberStatus::Active)` |
| `MembersByClass` | same + `->where('class', $params['class'])` |
| `MembersWithOutstandingFees` | `Member::whereHas('customer.arInvoices', fn ($q) => $q->where('amount_outstanding', '>', 0))` |
| `AllActiveEmployees` | `Employee::where('status', EmployeeStatus::Active)` |
| `EmployeesByDepartment` | same + `->where('department_id', $params['department_id'])` |
| `UsersByPermission` | `User::whereJsonContains('permissions', $params['permission'])` |

### `TemplateRenderer`

```php
public function render(string $body, object $recipient, BroadcastAudienceType $type): string
```

Walks `body` for `{{var}}` tokens, looks up against a per-audience-type whitelist:

| AudienceType | Recipient | Allowed vars |
|---|---|---|
| `AllActiveMembers`, `MembersByClass`, `MembersWithOutstandingFees` | `Member` | `member.name`, `member.member_no`, `member.class`, `member.outstanding_total`, `member.next_due_date` |
| `AllActiveEmployees`, `EmployeesByDepartment` | `Employee` | `employee.name`, `employee.staff_id`, `employee.department`, `employee.position` |
| `UsersByPermission` | `User` | `user.name`, `user.role` |

Always available: `org_name`, `today`.

Unknown vars render as empty string (silent — same forgiving behaviour Mustache uses). Variables outside the whitelist NEVER reach `$recipient` introspection — this is the security guard against `{{member.password}}` leak.

### `BroadcastService`

```php
public function queue(Broadcast $b): void   // Insert + dispatch or schedule
public function cancel(Broadcast $b): void  // Only for Scheduled; flips to Cancelled
```

## Job (new)

`App\Jobs\Messaging\DispatchBroadcastJob` — `$tries = 1` (we don't retry the whole broadcast, just individual SMS legs via N1). `handle()` does the chunked iteration described in the Architecture section.

`failed()` callback marks the broadcast row as `Failed` and the failure reason on it.

## Controllers + routes (new)

Under existing `/admin/messaging` prefix:

- `GET    /admin/messaging/broadcasts` → `BroadcastController@index` — paginated list w/ status filter
- `GET    /admin/messaging/broadcasts/create` → `BroadcastController@create` — composer
- `POST   /admin/messaging/broadcasts` → `BroadcastController@store` — queue/schedule
- `GET    /admin/messaging/broadcasts/{broadcast}` → `BroadcastController@show` — detail + recipient outcomes paginated
- `POST   /admin/messaging/broadcasts/{broadcast}/cancel` → `BroadcastController@cancel`
- `GET    /admin/messaging/broadcasts/{broadcast}/preview` → audience count + sample 10 (XHR from composer)

- `GET    /admin/messaging/templates` → `BroadcastTemplateController@index`
- `POST   /admin/messaging/templates` → `BroadcastTemplateController@store`
- `PATCH  /admin/messaging/templates/{template}` → `BroadcastTemplateController@update`
- `DELETE /admin/messaging/templates/{template}` → `BroadcastTemplateController@destroy`

All routes gated by appropriate permissions. Composer + store require `broadcasts.manage`. Override checkbox requires `broadcasts.bypass_throttle`.

## Frontend (new under `resources/js/Pages/Messaging/`)

- `Broadcasts/Index.vue` — table of past + scheduled broadcasts with filter chips
- `Broadcasts/Create.vue` — composer with audience picker, channel toggles, template select, body editor, preview, schedule, override
- `Broadcasts/Show.vue` — detail page with counters + paginated `BroadcastRecipient` outcomes table
- `Templates/Index.vue` — CRUD with audience-type picker + variable panel

Reuses `SlidePanel`, `StatusPill`, `EmptyState`. Adds a `VariablesPanel.vue` sub-component listing allowed vars for the selected audience.

## Scheduler entry (1 new line in `routes/console.php`)

```php
Schedule::command('messaging:fire-due-broadcasts')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

The `FireDueBroadcastsCommand` queries `Broadcast::where('status', BroadcastStatus::Scheduled)->where('scheduled_at', '<=', now())->get()`, flips each to `Queued`, dispatches the job.

## Throttle policy

- Default: `DispatchBroadcastJob` calls `RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5)` before each SMS leg.
- If true → insert recipient row with `sms_status=Throttled`, increment `sms_throttled_count`, skip the SMS leg. Mail leg unaffected.
- If `$broadcast->throttle_overridden` → skip the limiter check entirely. SMS goes through normally; recipient row records `sms_status=Sent` like any other. The override fact is recorded **once** on the broadcast row (`throttle_overridden=true` + `throttle_override_reason`), not on each recipient row.
- The override checkbox in the composer is hidden unless the user has `broadcasts.bypass_throttle` permission. Override always requires a `throttle_override_reason` (validation rule).
- Override decisions land in `audit_logs` via the existing audit middleware (route is POST).

## Audit + observability

- Existing `AuditTrail` middleware captures every POST to `/admin/messaging/broadcasts/*` — who, when, body summary.
- `broadcast_recipients` table is the source of truth for per-recipient outcomes — admin can drill into the broadcast detail page and see "Akua Mensah / +233200000099 / Sent / SMS #12345" rows.
- Counters on `broadcasts` are derived snapshots refreshed during job execution. The `Show.vue` page also has a "Re-tally" button (admin-only) that runs a one-off recount in case a worker died mid-broadcast.

## Error handling

| Failure mode | Behaviour |
|---|---|
| Audience resolver returns 0 recipients | Broadcast immediately Completed with all counters 0; admin sees "Audience was empty" banner on Show |
| Template renderer encounters unknown var | Renders as empty string; no error. Author sees the same in the preview before sending. |
| Recipient has phone but `sms:marketing` limiter hit | Row recorded as `Throttled`. Counter incremented. SMS not sent. |
| Recipient has no phone (SMS channel chosen) | Row recorded as `Skipped` for SMS leg. Mail leg attempted if mail channel chosen. |
| Recipient has no email (mail channel chosen) | Row recorded as `Skipped` for mail leg. |
| SmsDispatcher throws `PermanentSmsFailure` (bad input) | Row recorded as `Failed` with reason; broadcast continues. |
| SmsDispatcher accepts but Hubtel later returns transient | N1's retry kicks in independently; eventually flips `sms_messages.status` to `Sent` or `Failed`. Admin can query via `sms_message_id` join. |
| Mail driver throws | Row recorded as `mail_status=Failed` with reason; broadcast continues. |
| `DispatchBroadcastJob` itself crashes mid-chunk | `failed()` marks broadcast `Failed`. Admin can re-fire via "Retry" button on Show, which dispatches a fresh job; unique constraint on `broadcast_recipients` prevents double-send. |
| Scheduled broadcast scheduled-time arrives but queue worker is down | Stays `Scheduled`. When worker comes back, `messaging:fire-due-broadcasts` picks it up next tick. |

## Migration / rollout

- 3 new tables: `broadcasts`, `broadcast_templates`, `broadcast_recipients`. Migrations are pure additions; no existing tables touched.
- 3 new permission slugs in `RolePermissionSeeder`. Existing roles get them where appropriate.
- 1 new scheduler entry. No existing schedules touched.
- No breaking changes to N1 or N2 surfaces.

## Risks

- **Throttle override misuse.** A super_admin could check "Bypass throttle" on every broadcast, defeating the limiter. Mitigation: override requires a reason string + audit log entry. UI strongly suggests when override is *not* needed (e.g. recipient pool < 50, no need to override). Future: surface a weekly report of override usage.
- **Audience drift between save and send.** A `MembersByClass=Professional` broadcast scheduled for next month may pick up newly-added members or miss recently-resigned ones. This is correct behaviour (audience is resolved at dispatch time, not at save time), but admins should expect it.
- **Long-running job for very large audiences.** A 5,000-employee broadcast through `chunkById(100)` = 50 chunks × 100 recipients × queue dispatch latency. With 2 workers it drains in maybe 5–10 minutes. Acceptable; document the expected duration on the Show page.
- **Cost.** Each broadcast SMS leg costs ~GHS 0.06 via Hubtel. A 500-recipient broadcast = GHS 30 per send. The `sms:marketing` limiter prevents accidental double-sends; reasonable cost guard.

## Verification

End-to-end Pest tests under `tests/Feature/Messaging/Broadcasts/*`. ~15 tests total:

| Test file | Cases |
|---|---|
| `AudienceResolverTest` | 6 — one per audience type, asserts the builder returns the expected set |
| `TemplateRendererTest` | 4 — happy path, unknown var skipped, whitelist enforced (no `password` leak), audience-type mismatch errors |
| `DispatchBroadcastJobTest` | 6 — happy SMS+mail path, throttle-respect, throttle-override, recipient no-phone (mail only), recipient no-email (SMS only), idempotent on re-run |
| `BroadcastSchedulerTest` | 2 — due broadcast fires, future broadcast doesn't |
| `BroadcastControllerTest` | 5 — perm gates, cancellation only on Scheduled, validation rejects empty audience, override requires reason, audit log entry created |
| `BroadcastTemplateControllerTest` | 4 — CRUD perms, audience compatibility validation, inactive templates hidden from composer |

Full suite target: ~1180 (current ~1153 + ~27 new — slightly more than the 15 estimate above counting nested cases).

## Out-of-scope reminder

- Per-recipient unsubscribe
- Two-way reply tracking
- Rich HTML email
- Free-form query-builder audience
- CSV upload audience (rejected during brainstorming)
- AutoBroadcast / recurring schedules (every Monday at 09:00) — N3 scheduled-send is one-shot only; recurring is a future phase if needed
