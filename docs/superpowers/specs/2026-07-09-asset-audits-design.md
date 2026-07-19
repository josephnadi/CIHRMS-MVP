# Asset Audits — Spec 2 (Auditors follow-up)

**Date:** 2026-07-09
**Status:** Approved (design)
**Scope:** Campaign-based physical asset audits for auditors — open a run, snapshot the
expected assets, count each, flag discrepancies, and apply one-click write-back
corrections through the existing `AssetService`. Slots into the Auditor Hub.
The org-wide audit portal (views over the existing `audit_logs`) is **out of scope** —
a later thin add.

## 1. Background

The [[project_auditors_module]] shipped invoice vetting + an Auditor Hub. This is the
next follow-up scoped there: "active asset-audit counts / discrepancy flagging vs the
Assets module."

### Existing scaffolding (reused, not rebuilt)

- **Assets module** (`app/Models/Asset.php`, `AssetAssignment`, `AssetMaintenance`;
  migration `2026_05_28_000001_create_assets_tables.php`). Asset has `asset_tag` (unique),
  `category` (`AssetCategory`), `current_status` (`AssetStatus`: in_stock/assigned/
  maintenance/retired/lost), `location` (free text), and `current_assignment_id` → open
  `AssetAssignment` → `employee_id` (the current holder).
- **`AssetService`** (`app/Services/AssetService.php`) — all state changes go through here,
  each wrapped in `DB::transaction` and dispatching an event: `markLost(Asset,User,reason)`,
  `logMaintenance(...)`, `retire(...)`, `assign(...)`, `returnAsset(...)`. Services do NOT
  enforce permissions — the controller/policy layer does.
- **`SequenceService`** (`app/Services/Finance/SequenceService.php`) — `next(key)` gives a
  row-locked incrementing counter (generic despite the "finance" name). Used for references.
- **Auditor Hub** (`app/Http/Controllers/AuditorController.php` → `resources/js/Pages/
  Auditor/Hub.vue`) — stat cards + permission-gated link cards; the natural home for an
  "Asset Audits" card.
- Module convention: Enum → FormRequest → Service → Event → Resource → Controller(Inertia),
  demonstrated by IncomingInvoice (service + `*_events` history table pattern).

## 2. Design decisions (from brainstorming)

1. **Campaign + snapshot count** — an audit run snapshots the expected asset set into lines
   at open; the auditor counts each line.
2. **One-click write-back via `AssetService`** — discrepancy lines offer actions that
   correct the asset registry through the existing service (guarded + trailed).
3. **Auditor-owned, no second sign-off** — the auditor opens, counts, and closes; the
   event trail provides accountability.

## 3. Entities

New tables (all with softDeletes on the run; events append-only):

### `asset_audits` (the run)
| Column | Notes |
|---|---|
| `reference` | unique; `SequenceService::next('asset_audit')` → `ASA-2026-00001`. **Not** count()+1 |
| `status` | string(20) default `in_progress`, indexed (`AssetAuditStatus`) |
| `scope_type` | string(20): `all` \| `category` \| `location` |
| `scope_value` | nullable string — the category value or location text (null when `all`) |
| `total_lines` | int — snapshot size at open |
| `counted_lines` | int — maintained as lines are counted |
| `discrepancy_lines` | int — maintained as discrepancies are recorded |
| `notes` | nullable text |
| `opened_by`, `opened_at` | fk users / timestamp |
| `completed_by`, `completed_at` | nullable |
| `cancelled_by`, `cancelled_at`, `cancel_reason` | nullable |
| timestamps, softDeletes | |

### `asset_audit_lines` (one per expected asset, snapshotted at open)
| Column | Notes |
|---|---|
| `asset_audit_id` | fk, cascadeOnDelete |
| `asset_id` | fk assets, restrictOnDelete |
| `expected_status` | string(16) — snapshot of `asset.current_status` at open |
| `expected_location` | nullable string — snapshot of `asset.location` |
| `expected_holder_employee_id` | nullable fk employees — snapshot of current holder |
| `result` | string(20) default `pending` (`AssetAuditResult`: pending/present/missing/wrong_location/wrong_holder/damaged) |
| `observed_location` | nullable string |
| `observed_note` | nullable text |
| `is_discrepancy` | boolean default false — derived at count time |
| `counted_by`, `counted_at` | nullable |
| `resolution_action` | string(20) default `none` (`AssetAuditAction`: none/marked_lost/relocated/maintenance_logged/flagged) |
| `resolved_by`, `resolved_at`, `resolved_note` | nullable |
| timestamps | |

### `asset_audit_events` (append-only trail)
`id`, `asset_audit_id` (fk, cascade), `actor_id` (nullable), `action` (string 40),
`asset_audit_line_id` (nullable fk), `detail` (nullable text), `created_at` (manual,
`$timestamps = false`).

## 4. State machine (run)

```
open ─▶ in_progress ──complete──▶ completed
              │
              └──cancel──▶ cancelled
```

- **open(scope, actor):** in ONE `DB::transaction`, create the run (`in_progress`), query the
  expected asset set for the scope, snapshot one line per asset, set `total_lines`, record an
  `opened` event.
- **Expected asset set:** assets whose `current_status` ∈ {in_stock, assigned, maintenance}
  (retired & lost are not physically expected), filtered by scope:
  `all` → no filter; `category` → `where category`; `location` → `where location`.
- **count(line, result, observed, actor):** only when run is `in_progress`; sets result +
  observed fields + `counted_by/at`; computes `is_discrepancy`
  (`result !== present` OR observed location/holder differs from snapshot); updates the run's
  `counted_lines`/`discrepancy_lines` tallies; records a `counted` event.
- **complete(run, actor):** only from `in_progress` → `completed`; records `completed` event.
- **cancel(run, actor, reason):** from `in_progress` → `cancelled`; records `cancelled` event.
- All transitions live ONLY in `AssetAuditService`, each guarding source state with a
  `DomainException` on illegal transitions (mirrors `IncomingInvoiceService`).

## 5. Resolution — one-click write-back via `AssetService`

`AssetAuditService::applyResolution(AssetAuditLine $line, AssetAuditAction $action, User $actor)`
maps a discrepancy to an existing `AssetService` call, inside a transaction, then sets the
line's `resolution_action`/`resolved_by/at` and records a `resolved` event:

| Discrepancy `result` | Action | Effect |
|---|---|---|
| `missing` | `marked_lost` | `AssetService::markLost($asset, $actor, "Asset audit {ref}: not found")` → status `lost` |
| `wrong_location` | `relocated` | update `asset.location = line.observed_location` (+ note) |
| `damaged` | `maintenance_logged` | `AssetService::logMaintenance($asset, ...)` → status `maintenance` |
| `wrong_holder` | `flagged` | record-only; no safe auto-fix (reassignment needs an employee) — UI links to the asset's Show page for manual reassignment |

- `applyResolution` guards: the run must be `in_progress` or `completed`; the line must be a
  discrepancy; the action must be valid for the line's `result`.
- The apply path is authorized by **`asset_audits.manage`** (the auditor's authority to write
  back *through an audit*) — it does NOT require `assets.manage`, since `AssetService` methods
  don't self-enforce permissions.

## 6. RBAC

New permission slugs — added to `Permission` enum, `RolePermissionSeeder` (`PERMISSIONS` +
`ROLE_PERMS`), and mirrored in `User::ROLE_PERMISSIONS` (all three in lock-step):

| Slug | Held by |
|---|---|
| `asset_audits.view` | auditor, super_admin (ceo via wildcard) |
| `asset_audits.manage` | auditor, super_admin |

`super_admin`/`ceo` keep their `null`/`['*']` wildcard. Routes registered as per-action
`permission:` middleware groups inside the existing `auditor.` prefix group in `routes/web.php`.

## 7. Backend layers (Enum → FormRequest → Service → Event → Resource → Controller)

- **Enums:** `App\Enums\AssetAuditStatus`, `AssetAuditResult`, `AssetAuditAction` (each
  string-backed with `label()`). Plus add a `label()` method to the existing
  `App\Enums\AssetStatus` (it currently lacks one; needed to display expected status).
- **Models:** `App\Models\AssetAudit`, `AssetAuditLine`, `AssetAuditEvent`.
- **FormRequests** (`app/Http/Requests/Assets/`): `StoreAssetAuditRequest` (scope),
  `CountAssetAuditLineRequest` (result + observed), `ResolveAssetAuditLineRequest` (action),
  `CompleteAssetAuditRequest`, `CancelAssetAuditRequest` (reason). `authorize()` gates on the
  right slug (`asset_audits.view` for read, `asset_audits.manage` for writes).
- **Service:** `App\Services\AssetAuditService` — constructor injects `SequenceService` +
  `AssetService`; methods `open`, `count`, `applyResolution`, `complete`, `cancel`, plus
  protected `recordEvent` + `nextReference`.
- **Events:** `AssetAuditOpened`, `AssetAuditCompleted` (shape of existing `Asset*` events).
- **Resources** (`app/Http/Resources/`): `AssetAuditResource`, `AssetAuditLineResource`,
  `AssetAuditEventResource`.
- **Controller:** `App\Http\Controllers\Auditor\AssetAuditController` — thin; injects the
  service; `Inertia::render('Auditor/AssetAudits/*')` with `activeModule`. Defense-in-depth
  `abort_unless($user->hasPermission(...))` mirroring the invoice controller.

## 8. UI

- **Pages** `resources/js/Pages/Auditor/AssetAudits/`:
  - `Index.vue` — list of runs (filter by status), coverage/discrepancy badges, "New audit".
  - `Create.vue` — pick `scope_type` + `scope_value` (category dropdown / location text / all).
  - `Show.vue` — the count sheet: line table with per-line `result` selector + observed
    location/note inputs, live coverage + discrepancy counters, resolution-action buttons on
    discrepancy lines (Mark Lost / Relocate / Log maintenance / Flag), Complete + Cancel
    actions, and an event timeline.
- **Auditor Hub** gains a stat card (open audits / open discrepancies) and an "Asset Audits"
  link card (`v-if` on `asset_audits.view`). `AuditorController::hub()` adds the counts +
  a `links.asset_audits` boolean.

## 9. Testing (Pest)

- `open` snapshots the correct expected set per scope (all/category/location) and excludes
  retired/lost.
- `count` sets `is_discrepancy` correctly for each mismatch kind and updates run tallies.
- `applyResolution` write-backs: `marked_lost` flips the asset to `lost`; `relocated` updates
  `asset.location`; `maintenance_logged` opens a maintenance record + status `maintenance`;
  `flagged` is record-only.
- Permission gating per action (403 without the slug), incl. that `asset_audits.manage` (not
  `assets.manage`) authorizes write-backs.
- `complete`/`cancel` guards + illegal-transition `DomainException`s.
- Per project test patterns: seed `RolePermissionSeeder`; per-user `permissions` JSON for
  grants; assets created via factory/`AssetService::register`.

## 10. Out of scope (future)

- Org-wide audit portal beyond the hub — mostly views over the existing `audit_logs`
  (already has `AuditLogController`).
- Barcode/CSV import of scanned tags (the count sheet is interactive in v1).
- Auto-reassignment for `wrong_holder` (record-only; manual via the Assets module).
- A second sign-off / approval step on audit completion.
