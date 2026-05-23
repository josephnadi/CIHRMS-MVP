# Documents v2 — Sharing, Asset Libraries, Manipulable Annotations

**Date:** 2026-05-21
**Status:** Design (awaiting user approval)
**Module:** Documents

## Goal

Extend the existing Documents module to give users full lifecycle control:

1. **Edit, delete, modify** documents they own.
2. **Send to an individual or share with the entire organization** as read-only audiences (distinct from the existing sequential routing workflow).
3. **Upload personal/org stamp images** and use them as annotations alongside the existing text stamps.
4. **Drag, resize, rotate, and delete** signatures and stamps after placement for accurate positioning.
5. **Upload and select letterhead templates** in the composer (replacing the single hardcoded letterhead).
6. **Upload and select watermark templates**, applied at render/burn time.

## Current State (as of 2026-05-21)

The Documents module already implements: upload, in-portal HTML→PDF composer, versioning, sequential routing (sign/review/approve/acknowledge), click-to-place annotations (signature / text-stamp / text / initial / highlight), withdraw, archive, signed download with auto-watermarking for `restricted` documents.

Missing relative to this request:

- No edit / delete endpoints. `DocumentPolicy::update` and `delete` are defined but no controller actions / routes exist.
- No share-as-read-only mechanism. The only way to give someone visibility is to route the doc, which requires an action and forms part of the workflow.
- No org-wide audience.
- Stamps are text-only. `StampPicker.vue` offers presets + custom text, no image upload. The renderer already supports `data.png_base64` stamps (`DocumentRenderService::drawAnnotation`), so the data path exists; only the UI and asset storage are missing.
- Annotations are placed by a single click. `AnnotationLayer.vue` has no drag/resize/rotate handles; only the annotation owner can `DELETE` from the timeline via a row button. Sizes are hardcoded (`signature 22×8%`, `stamp 18×6%`).
- Letterhead is one hardcoded image at `public/img/letterhead.png` rendered via `setHeaderData()` in `DocumentComposerService::renderHtmlToPdf`. Users cannot upload or pick alternates.
- Watermarks are only applied when burning a `restricted` download (controller-driven, see `DocumentController::download`). Users cannot upload custom watermarks, nor opt to watermark non-restricted docs.

## Scope Decisions

These are the calls made up front so the spec is unambiguous:

1. **Routing vs Sharing are separate concepts.** Existing sequential `document_routes` workflow remains untouched. New `document_shares` table provides read-only access grants.
2. **"Public for entire organization" = read-only org audience**, not a workflow.
3. **Confidentiality guard:** documents with `confidentiality ∈ {confidential, restricted}` cannot be shared with `department` or `organization` audiences. Backend returns 422.
4. **Asset ownership scopes** for stamps / letterheads / watermarks: `personal` (creator only), `department` (creator's department), `organization` (curated by `documents.manage` holders, usable by anyone).
5. **Manipulable annotations:** drag-to-move, corner-handle resize, top-handle rotate, hover-X delete. The annotation's `user_id` (or the document owner while the doc is in Draft) can manipulate. Annotations attached to a `Completed` route are locked (sign integrity).
6. **Edit endpoint** is metadata-only (title, description, confidentiality, tags, letterhead_id, watermark_id, watermark_mode) and only allowed while the doc is `Draft`, matching the existing `update` policy.
7. **Delete endpoint** soft-deletes (the model already uses `SoftDeletes`). Owner-on-Draft OR `documents.manage`.
8. **"Modify" = `addVersion`** which already exists.

## Data Model

### New tables

```text
document_shares
  id
  document_id          FK documents.id, cascade delete
  audience_type        enum [user, department, organization]
  audience_id          nullable bigint (users.id or departments.id; null when audience_type=organization)
  granted_by           FK users.id
  granted_at           timestamp
  expires_at           nullable timestamp
  timestamps
  unique (document_id, audience_type, audience_id)

stamp_assets
  id
  owner_scope          enum [personal, department, organization]
  owner_id             nullable bigint (users.id when personal; departments.id when department; null when organization)
  name                 string
  storage_path         string
  mime                 string (image/png only)
  default_w_pct        decimal(5,2)  default 18
  default_h_pct        decimal(5,2)  default 6
  created_by           FK users.id
  timestamps
  index (owner_scope, owner_id)

letterhead_templates
  id
  owner_scope          enum [personal, department, organization]
  owner_id             nullable bigint
  name                 string
  storage_path         string
  mime                 string (image/png or image/jpeg)
  header_height_mm     smallint default 36
  is_default           bool default false
  created_by           FK users.id
  timestamps
  index (owner_scope, owner_id)

watermark_templates
  id
  owner_scope          enum [personal, department, organization]
  owner_id             nullable bigint
  name                 string
  type                 enum [text, image]
  text                 nullable string             (when type=text)
  color                nullable string             (#rrggbb; default #dc2626)
  storage_path         nullable string             (when type=image)
  mime                 nullable string
  opacity              decimal(3,2) default 0.18
  angle_deg            smallint default -30
  font_size_hint       smallint nullable           (TCPDF pt; null = auto)
  created_by           FK users.id
  timestamps
  index (owner_scope, owner_id)
```

### `documents` additive columns

```text
+ letterhead_id      nullable FK letterhead_templates.id
+ watermark_id       nullable FK watermark_templates.id
+ watermark_mode     enum [none, on_burn, always]   default 'on_burn'
```

`is_org_public` is **not** stored on `documents` — it's derived from a `document_shares` row with `audience_type='organization'`.

## Backend Changes

### Routes (additive, namespace `documents.`)

```text
PATCH  /documents/{document}                                 -> update      (title, description, confidentiality, tags, letterhead_id, watermark_id, watermark_mode)
DELETE /documents/{document}                                 -> destroy
POST   /documents/{document}/shares                          -> shares.store
DELETE /documents/{document}/shares/{share}                  -> shares.destroy
PATCH  /documents/{document}/annotations/{annotation}        -> annotations.update   (x_pct, y_pct, w_pct, h_pct, rotation)
```

### Routes — settings namespace (new prefix `/settings`)

```text
GET    /settings/stamps              POST /settings/stamps           DELETE /settings/stamps/{asset}
GET    /settings/letterheads         POST /settings/letterheads      DELETE /settings/letterheads/{template}
GET    /settings/watermarks          POST /settings/watermarks       DELETE /settings/watermarks/{template}
```

Each route is gated by the matching permission (see below). Org-scope create/delete requires `document_assets.manage`.

### Form requests (under `App\Http\Requests\Documents` and `App\Http\Requests\DocumentAssets`)

- `UpdateDocumentRequest` — partial-update validation; `letterhead_id` must exist & be accessible to user; `watermark_mode` enum.
- `ShareDocumentRequest` — `audience_type` enum, `audience_id` required-unless `audience_type=organization`, `expires_at` future date.
- `MoveAnnotationRequest` — numeric x/y/w/h percents in `[0, 100]`, `rotation` in `[0, 359]`.
- `StoreStampAssetRequest` / `StoreLetterheadRequest` / `StoreWatermarkRequest` — file mime/size validation per the storage rules below; `owner_scope` requires the appropriate permission.

### Services

- `DocumentService::updateMetadata(Document, array, User): Document` — owner-on-Draft, logs `DocumentEventType::Updated`.
- `DocumentService::softDelete(Document, User): void` — owner-on-Draft OR `documents.manage`. Logs `DocumentEventType::Deleted`.
- `DocumentService::moveAnnotation(DocumentAnnotation, array, User): DocumentAnnotation` — logs `AnnotationMoved` / `AnnotationResized` depending on which fields changed.
- New `DocumentShareService` — `grant(Document, array, User): DocumentShare`, `revoke(DocumentShare, User): void`. Enforces the confidentiality guard.
- `DocumentRenderService::burn` — when the document has `watermark_id`, render that template (text or image) instead of (or in addition to) the auto restricted watermark. The existing auto-restricted behavior is preserved when `watermark_id IS NULL` to avoid regressions.
- `DocumentComposerService::compose` — accept `letterhead_id`. Render the template's image as the page header. The current hardcoded `CIHRM-GHANA` letterhead is preserved by seeding it as an `organization`-scope `letterhead_templates` row referenced by `is_default=true`.
- New `StampAssetService`, `LetterheadTemplateService`, `WatermarkTemplateService` — upload + delete with disk hygiene.

### Policies

`DocumentPolicy::view` widens to include shares:

```php
return $doc->routes()->where('to_user_id', $user->id)->exists()
    || $doc->routes()->where('from_user_id', $user->id)->exists()
    || $doc->shares()->where(function ($q) use ($user) {
        $q->where(function ($a) use ($user) {
              $a->where('audience_type', 'user')->where('audience_id', $user->id);
          })
          ->orWhere(function ($a) use ($user) {
              $a->where('audience_type', 'department')->where('audience_id', $user->department_id);
          })
          ->orWhere('audience_type', 'organization');
       })
       ->where(function ($q) {
           $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
       })
       ->exists();
```

New methods:

- `share(User, Document)` — owner or `documents.manage`.
- `moveAnnotation(User, DocumentAnnotation)` — annotation's `user_id === User.id` OR (doc owner AND doc.status === Draft). Blocked when the annotation's `route_id` belongs to a `Completed` route.

`update` / `delete` are already defined — wire them to the new routes.

### Enum extensions

`App\Enums\DocumentEventType`:

```php
case Updated         = 'updated';
case Deleted         = 'deleted';
case Shared          = 'shared';
case Unshared        = 'unshared';
case AnnotationMoved   = 'annotation_moved';
case AnnotationResized = 'annotation_resized';
```

New enum `DocumentShareAudience`: `user|department|organization`.
New enum `DocumentWatermarkMode`: `none|on_burn|always`.
New enum `AssetOwnerScope`: `personal|department|organization`.

### Permissions

Added to the seeder + `permissions` table:

- `documents.share_organization` — required to create `audience_type=organization` shares. (User/department shares require only that the user is the document's owner, gated by `DocumentPolicy::share`.)
- `document_assets.manage` — required to create/delete organization-scope assets (stamps, letterheads, watermarks). Personal-scope creation requires no extra permission; department-scope creation requires that the user belongs to the target department.

Existing `documents.create`, `documents.view`, `documents.manage` are unchanged.

## Frontend Changes

### `Pages/Documents/Show.vue`

- Header gains three new buttons (next to existing Route / Withdraw):
  - **Share** — opens a modal with three audience tabs (user typeahead reusing `RecipientPicker`; department dropdown; org checkbox with confidentiality guard message). Lists current shares with revoke.
  - **Edit** — opens a slide-panel for metadata + asset selection.
  - **Delete** — confirm dialog; calls `DELETE`.
- The Edit drawer holds `letterhead_id`, `watermark_id`, `watermark_mode` selectors (each is a searchable picker over the user's accessible templates).

### `Components/Documents/AnnotationLayer.vue` — substantial rewrite

Each rendered annotation becomes a `<DraggableAnnotation>` group with:

- pointer-down → capture pointer → translate `x_pct`/`y_pct` (percent of page rect).
- four corner handles (NW/NE/SW/SE) for resize, preserving aspect ratio if Shift held. Min `w_pct/h_pct = 4`, max `80`.
- one rotate handle ring above the box; updates `rotation` in degrees.
- one X button (top-right) to delete; uses existing `DELETE annotations.destroy`.
- on pointer-up, optimistically update the local copy and PATCH `annotations.update` — rollback on 422/403.

Read-only mode (no handles) when `canManipulate(annotation) === false`. The `canManipulate` predicate mirrors the new policy.

### `Components/Documents/StampPicker.vue` — three tabs

- **My stamps** — grid of the user's `personal` stamp assets (preview thumbnails); clicking emits `stamp` with `{ png_base64, asset_id }` (server-side resolves the file).
- **Org stamps** — same grid sourced from `organization`-scope + the user's department-scope assets.
- **Custom text** — existing UI preserved.
- Inline "Upload new" button (FilePond-style; PNG only, ≤ 1 MB) → POST to `/settings/stamps` and immediately select the returned asset.

The annotation `data` shape becomes `{ png_base64: string, asset_id?: number }` for image stamps. `DocumentRenderService` already handles `data.png_base64` — no renderer change for stamps. For long-term storage efficiency we may later replace `png_base64` with a stored `asset_id` lookup, but v2 keeps the existing data shape to avoid a renderer change.

### `Pages/Documents/Compose.vue`

- Replace the boolean "Attach letterhead" toggle with a **dropdown** (None / template list, grouped by scope). Default selected = the org-default template.
- Add **Watermark** dropdown + **Watermark mode** selector (`none`, `on_burn`, `always`).
- Live preview iframe honors the selected letterhead image (renders the image as the `<header>` block in `previewHtml`).

### New pages

- `Pages/Settings/Stamps.vue`
- `Pages/Settings/Letterheads.vue`
- `Pages/Settings/Watermarks.vue`

Each: scope filter (Mine / Department / Organization), drag-drop upload zone, grid of cards with preview + name + delete. Org-scope create/delete buttons gated behind `document_assets.manage`.

These pages live under the existing **Settings** module nav (extend `AuthenticatedLayout` sidebar accordingly).

## Storage

- Disk: `local` (matches existing module).
- Paths:
  - `assets/stamps/<uuid>.png`
  - `assets/letterheads/<uuid>.png` or `.jpg`
  - `assets/watermarks/<uuid>.png`
- Size caps: stamp 1 MB, watermark 1 MB, letterhead 3 MB.
- Mime validation: stamps & watermarks PNG only (alpha required for clean burn-in); letterheads PNG or JPG.
- Server strips EXIF on upload via `intervention/image` (already in composer deps if present; otherwise add).

## Migration & Backwards Compatibility

- New tables additive; no destructive changes to `documents` / `document_annotations` / etc.
- Seeder inserts:
  - One `letterhead_templates` row reproducing the current hardcoded CIHRM-GHANA design (storage_path = copy of `public/img/letterhead.png`), `owner_scope=organization`, `is_default=true`.
  - One `watermark_templates` row reproducing the current restricted watermark (text + rose color), `owner_scope=organization`.
- Existing documents (`letterhead_id IS NULL`, `watermark_id IS NULL`) keep working: composer falls back to the seeded default, render falls back to the existing auto-restricted behavior.

## Tests (Pest)

### Feature

- `tests/Feature/Documents/UpdateDocumentTest.php` — owner-on-Draft can edit; non-owner 403; non-Draft 403.
- `tests/Feature/Documents/DeleteDocumentTest.php` — owner-on-Draft soft-deletes; manage-permission deletes any; non-owner 403.
- `tests/Feature/Documents/ShareDocumentTest.php` — user / department / organization audience grants; revocation; confidentiality guard rejects org/dept share when confidentiality≠internal; expired share no longer grants view.
- `tests/Feature/Documents/MoveAnnotationTest.php` — owner moves their annotation; coordinates persist; non-owner 403; locked when route completed.
- `tests/Feature/DocumentAssets/StampAssetTest.php` — personal upload + use; org upload requires `document_assets.manage`; PNG-only validation; size cap.
- `tests/Feature/DocumentAssets/LetterheadTemplateTest.php` — upload, composer uses selected template, preview renders, default fallback when no template.
- `tests/Feature/DocumentAssets/WatermarkTemplateTest.php` — upload, burned PDF includes template watermark, `always` mode also overlays non-burned download.

### Unit

- `DocumentShareService` audience resolution and confidentiality guard.
- `DocumentRenderService` watermark template picker fallback.
- Policy: `view` extension with shares; `moveAnnotation` lock-on-completed.

## Phasing (for the implementation plan)

Each phase is independently shippable and ends with green tests.

1. **Phase 1 — Edit, Delete, Share.**
   New endpoints + policy work + `document_shares` table + Share modal + Edit drawer (without asset pickers yet). Confidentiality guard.
2. **Phase 2 — Manipulable annotations.**
   PATCH endpoint + `moveAnnotation` policy + `AnnotationLayer.vue` rewrite with drag/resize/rotate/delete handles.
3. **Phase 3 — Stamp assets.**
   `stamp_assets` table + settings page + `StampPicker.vue` tabs + permission wiring.
4. **Phase 4 — Letterhead templates.**
   `letterhead_templates` table + settings page + composer dropdown + composer service refactor + seeder for default. Wire `documents.letterhead_id` into Edit drawer.
5. **Phase 5 — Watermark templates.**
   `watermark_templates` table + settings page + render service override + Edit drawer fields + seeder for restricted default.

## Out of Scope (deliberately deferred)

- Editing already-burned PDFs in-place. The current "burn" is a derived artifact; v2 keeps it as derived.
- Per-page letterhead overrides (different first page vs. body).
- Animated/SVG watermarks.
- Annotation versioning / undo stack across reloads (the existing remove+re-add UX is preserved).
- Public anonymous links (org-public still requires authentication).
