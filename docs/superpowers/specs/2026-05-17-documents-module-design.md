# Documents Module — Design Spec

**Date:** 2026-05-17
**Status:** Approved (design phase). Ready for implementation plan.
**Author:** CIHRMS team via Claude Code

---

## 1. Problem & Goal

CIHRMS currently has no system for institutional documents. Memos, approval letters, requisitions, and contracts circulate physically — printed, hand-signed, stamped by the Registrar, then walked from desk to desk. This is slow, prone to loss, and leaves no auditable record.

**Goal:** A "digital routing slip" module that lets a sender upload a document, drop signatures/stamps on it, route it through an ordered list of recipients (each of whom can also sign/stamp/comment), and produce a tamper-evident, downloadable PDF at the end. Cross-portal: any staff member's Documents Inbox surfaces awaiting actions regardless of which module they were sent from.

## 2. In Scope (v1)

1. **Document register** — upload, list, search, filter (PDF, DOCX, PNG/JPG)
2. **Annotations** — draw signature (`signature_pad`), place text/image stamps, add comment notes at specific page coordinates
3. **Sequential routing** — sender picks an ordered list of recipients ("Registrar → DHR → Finance"); each acts and forwards
4. **Inbox** — every staff member has a Documents Inbox; cross-portal badge in the sidebar
5. **Audit timeline** — uploaded, routed, signed, forwarded, rejected, completed — timestamped per document
6. **Download** — original file OR a "burned-in" PDF with all annotations rendered on top
7. **Version history** — re-upload creates v2; v1 retained; each version has its own annotation set

## 3. Out of Scope (deferred to v2+)

| Feature | Reason |
|---|---|
| In-browser DOCX editing | Requires a heavy editor (OnlyOffice/CKEditor) — too big for one slice |
| True PDF ↔ DOCX conversion | Needs LibreOffice headless or paid API; `DocumentConversionService` interface exists with stub that returns 501 |
| Cryptographic e-Signature (PKI / CSCA) | Drawn signatures suffice for institutional use; legal e-Sign is a separate compliance project |
| Parallel approval (N people sign at once) | MVP is sequential; schema has a `parallel_routing` boolean so v2 needs no migration |
| OCR / searchable scans | Defer |
| Document templates (mail-merge) | Defer |

## 4. User Flows

### 4.1 Sender flow
1. Click "Upload Document" in Documents portal.
2. Slide-panel: title, description, confidentiality (Internal / Confidential / Restricted), tags, file.
3. Document saved as `draft`. Sender lands on Show page.
4. Sender places own signature/stamp(s) anywhere on the file using the annotation toolbar.
5. Sender clicks "Route" → modal: ordered recipient list, action required per recipient (Sign / Review / Approve / Acknowledge), due date.
6. Status → `in_review`. First recipient gets a `DocumentAwaitingAction` notification.

### 4.2 Recipient flow
1. Sees inbox badge; opens Documents → Inbox tab.
2. Opens document; sees prior signatures/stamps overlaid by previous handlers.
3. Adds own annotations.
4. Picks one of:
   - **Complete** (UI labels this "Sign & forward" if a next hop exists, otherwise "Approve & close") — internally this is a single `act` operation; the routing engine completes the document automatically if no further hops remain
   - **Reject** — terminates the slip; the document moves to `rejected` and the sender is notified. Annotations are preserved for the record.

### 4.3 Completion
- Last recipient completes → status `completed`. Sender notified. Document is locked from further annotation; downloadable as burned-in PDF.

### 4.4 Withdrawal
- Sender (or admin with `documents.manage`) can withdraw an `in_review` document; status → `withdrawn`. Future re-route requires re-upload or explicit "re-open".

## 5. Schema

### 5.1 Tables (5)

```
documents
  id              bigint pk
  uuid            uuid    unique
  ref_no          string  unique  -- "CIHRMS/DOC/2026/0042"
  title           string
  description     text    nullable
  owner_id        bigint  fk users.id
  current_version_id bigint nullable fk document_versions.id
  status          enum    -- draft, in_review, completed, rejected, withdrawn, archived
  confidentiality enum    -- internal, confidential, restricted
  parallel_routing bool   default false  -- forward-compat for v2
  tags            json    nullable
  created_at, updated_at, deleted_at

document_versions
  id              bigint pk
  document_id     bigint fk
  version_no      int
  original_name   string
  mime            string
  size            bigint
  storage_path    string         -- private disk relative path
  sha256          string  index
  uploaded_by     bigint fk users.id
  uploaded_at     timestamp
  notes           text nullable
  unique (document_id, version_no)

document_routes
  id              bigint pk
  document_id     bigint fk
  version_id      bigint fk document_versions.id
  sequence        int             -- 1, 2, 3 ...
  from_user_id    bigint fk users.id  -- the sender at each hop
  to_user_id      bigint fk users.id
  action_required enum    -- sign, review, approve, acknowledge  (label only; engine treats all the same)
  status          enum    -- pending, in_progress, completed, rejected, cancelled
  due_at          timestamp nullable
  acted_at        timestamp nullable
  comment         text nullable
  created_at, updated_at
  index (to_user_id, status)

document_annotations
  id              bigint pk
  document_id     bigint fk
  version_id      bigint fk document_versions.id
  route_id        bigint nullable fk document_routes.id
  user_id         bigint fk users.id
  type            enum    -- signature, stamp, text, initial, highlight
  page            int
  x_pct           decimal(7,4)   -- percent of page width, 0..100
  y_pct           decimal(7,4)
  w_pct           decimal(7,4)
  h_pct           decimal(7,4)
  rotation        int default 0
  data            json    -- {svg:"...", png_base64:"...", text:"APPROVED", color:"#0d1452"}
  created_at
  index (document_id, version_id, page)

document_events  -- module-local timeline (the global audit_logs table also records the HTTP calls)
  id              bigint pk
  document_id     bigint fk
  actor_id        bigint fk users.id
  type            enum    -- uploaded, version_added, routed, annotated, signed, stamped, forwarded, rejected, completed, withdrawn, downloaded
  payload         json    -- {route_id, version_id, recipient_id, comment, ...}
  occurred_at     timestamp
  index (document_id, occurred_at)
```

### 5.2 Status flow

```
draft ──route()──> in_review ──(last recipient acts)──> completed
                       │
                       ├──(any recipient rejects)──> rejected
                       └──(sender/admin withdraws)──> withdrawn

(any state) ──archive()──> archived  (manual, admin only)
```

## 6. Routes

All under `Route::middleware(['auth', 'audit'])`.

| Verb | Path | Action | Permission |
|---|---|---|---|
| GET  | `/documents` | `index` — tabs: All / Inbox / Sent / Drafts / Archive | `documents.view` |
| GET  | `/documents/inbox` | filter shortcut → `index?tab=inbox` | `documents.view` |
| POST | `/documents` | upload v1 | `documents.create` |
| GET  | `/documents/{uuid}` | viewer + routing slip + timeline | `documents.view` (own or in slip or admin) |
| POST | `/documents/{uuid}/versions` | re-upload as v(n+1) | `documents.create` (owner) |
| POST | `/documents/{uuid}/route` | create routing slip | `documents.create` (owner) |
| POST | `/documents/{uuid}/withdraw` | withdraw slip | `documents.create` (owner) or `documents.manage` |
| POST | `/documents/{uuid}/annotations` | save annotation | active route recipient or owner |
| DELETE | `/documents/{uuid}/annotations/{id}` | remove own annotation (only if route still open) | author |
| POST | `/documents/{uuid}/routes/{route}/act` | act on a route hop (sign+forward / approve / reject) | route.to_user |
| GET  | `/documents/{uuid}/download` | params: `version`, `burned=0\|1` | `documents.view` |
| POST | `/documents/{uuid}/convert` | param: `to=pdf` — image→PDF works, DOCX→PDF returns 501 | `documents.view` |
| POST | `/documents/{uuid}/archive` | archive | `documents.manage` |

## 7. Frontend

### 7.1 Pages

- `resources/js/Pages/Documents/Index.vue` — register/list with TabBar
- `resources/js/Pages/Documents/Show.vue` — split layout: viewer (left), routing slip + annotation tools + timeline (right rail)

### 7.2 Components

- `Components/Documents/Viewer.vue` — `pdfjs-dist` renderer; for images, render `<img>`; for DOCX, show "Preview not available — download to view"
- `Components/Documents/SignaturePad.vue` — modal canvas via `signature_pad`; returns PNG base64
- `Components/Documents/StampPicker.vue` — text stamps (APPROVED / RECEIVED / FOR ACTION / CONFIDENTIAL) + per-user uploaded image stamps
- `Components/Documents/AnnotationLayer.vue` — overlay div positioning annotations on the rendered page; click-drag to place
- `Components/Documents/RoutingSlipPanel.vue` — read view of slip; "Route" CTA opens recipient picker modal
- `Components/Documents/TimelineRail.vue` — chronological list of `document_events`

### 7.3 Sidebar nav

Add entry in `resources/js/Layouts/AuthenticatedLayout.vue` `navSections`:
```js
{ label: 'Documents', route: 'documents.index', module: 'documents', icon: 'description', visible: can('documents.view'), badge: inboxCount }
```
`inboxCount` comes from a shared Inertia prop populated by middleware (so the badge is live cross-portal).

## 8. Backend

### 8.1 Service responsibilities

- **`DocumentService`** — `upload(file, payload, owner): Document`, `addVersion(doc, file, by)`, `saveAnnotation(doc, route|null, user, payload)`, `removeAnnotation(annot, by)`, `archive(doc, by)`
- **`DocumentRoutingService`** — `route(doc, recipients[]): void` (creates ordered `document_routes`, marks first as `in_progress`, document status → `in_review`, dispatches `DocumentAwaitingAction` notification to first recipient). `act(route, decision, comment, by)`:
  - `decision = complete` → mark this route `completed`. If a next-sequence route exists, mark it `in_progress` and dispatch notification. If none, document → `completed`, sender notified.
  - `decision = reject` → mark this route `rejected`, document → `rejected`; sender + all previously-acted recipients notified.
  - All transitions emit a `DocumentEvent` row + a domain Event for listeners.
  - The action_required values (`sign`, `review`, `approve`, `acknowledge`) are recipient-facing labels; the engine treats `complete` identically across them.
  - **Withdraw**: `withdraw(doc, by)` — only allowed in `in_review`; current in-progress route marked `cancelled`; doc → `withdrawn`
- **`DocumentRenderService`** — `burn(version): pdfPath` — uses `setasign/fpdi` + `tecnickcom/tcpdf` to import the source PDF and overlay annotations; for images, wraps via Imagick then burns. Output cached at `private/documents/{uuid}/v{n}/burned-{hash}.pdf` keyed by annotation set hash.
- **`DocumentConversionService`** — interface; concrete `LocalConversionService` handles image→PDF; throws `ConversionNotSupportedException` for DOCX (controller returns 501).

### 8.2 Events & listeners

- `DocumentRouted` → notify next recipient (database + mail)
- `DocumentSigned` → log to `document_events`; no notification
- `DocumentCompleted` → notify owner
- `DocumentRejected` → notify owner + all previously-acted recipients

### 8.3 Policy

`DocumentPolicy`:
- `view(user, doc)`: owner OR in slip (any past/present route hop) OR `documents.manage`
- `update(user, doc)`: owner AND status = draft
- `delete(user, doc)`: owner AND status = draft, OR `documents.manage`
- `route(user, doc)`: owner AND status = draft, OR `documents.manage`
- `act(user, doc, route)`: `route.to_user_id === user.id` AND `route.status = in_progress`
- `annotate(user, doc, route|null)`: owner (if draft) OR current in-progress route recipient
- `download(user, doc)`: same as `view`; if `confidentiality = restricted`, watermark the PDF with viewer name/timestamp

## 9. Storage

- **Disk:** `local` (private). New file paths under `private/documents/{uuid}/v{n}/{filename}`.
- **Burned cache:** `private/documents/{uuid}/v{n}/burned-{annotHash}.pdf` — regenerated when annotations change.
- **Stamps library:** per-user uploaded stamps at `private/users/{userId}/stamps/{uuid}.png` (small, <100 KB enforced).
- **Signed URLs:** download endpoint generates short-lived (5 min) signed URL via Laravel's `URL::temporarySignedRoute`.

## 10. Audit & integrity

- Every state transition writes a `document_events` row.
- The existing `AuditTrail` middleware also logs every HTTP POST/PUT/DELETE (already wired).
- `document_versions.sha256` stored at upload time so any future tampering is detectable on download verification.

## 11. Dependencies to add

### Composer
- `setasign/fpdi` — PDF import for stamping
- `tecnickcom/tcpdf` — PDF output

### npm
- `pdfjs-dist` — in-browser PDF rendering
- `signature_pad` — canvas signature capture

## 12. Code structure

```
app/
├── Enums/
│   ├── DocumentStatus.php
│   ├── DocumentConfidentiality.php
│   ├── DocumentRouteAction.php
│   ├── DocumentRouteStatus.php
│   ├── DocumentAnnotationType.php
│   └── DocumentEventType.php
├── Models/
│   ├── Document.php
│   ├── DocumentVersion.php
│   ├── DocumentRoute.php
│   ├── DocumentAnnotation.php
│   └── DocumentEvent.php
├── Http/
│   ├── Controllers/DocumentController.php
│   ├── Requests/Documents/
│   │   ├── StoreDocumentRequest.php
│   │   ├── AddVersionRequest.php
│   │   ├── RouteDocumentRequest.php
│   │   ├── AnnotateDocumentRequest.php
│   │   └── ActOnRouteRequest.php
│   └── Resources/
│       ├── DocumentResource.php
│       ├── DocumentRouteResource.php
│       ├── DocumentAnnotationResource.php
│       └── DocumentEventResource.php
├── Services/
│   ├── DocumentService.php
│   ├── DocumentRoutingService.php
│   ├── DocumentRenderService.php
│   └── DocumentConversionService.php
├── Events/
│   ├── DocumentRouted.php
│   ├── DocumentSigned.php
│   ├── DocumentCompleted.php
│   └── DocumentRejected.php
├── Notifications/
│   ├── DocumentAwaitingAction.php
│   └── DocumentCompleted.php
├── Policies/DocumentPolicy.php
└── Exceptions/ConversionNotSupportedException.php

database/migrations/
├── 2026_05_17_000001_create_documents_table.php
├── 2026_05_17_000002_create_document_versions_table.php
├── 2026_05_17_000003_create_document_routes_table.php
├── 2026_05_17_000004_create_document_annotations_table.php
└── 2026_05_17_000005_create_document_events_table.php

resources/js/
├── Pages/Documents/
│   ├── Index.vue
│   └── Show.vue
└── Components/Documents/
    ├── Viewer.vue
    ├── SignaturePad.vue
    ├── StampPicker.vue
    ├── AnnotationLayer.vue
    ├── RoutingSlipPanel.vue
    └── TimelineRail.vue

routes/web.php  (append Document group)
```

## 13. Defaults (locked-in choices)

| Choice | Value |
|---|---|
| Routing model | Sequential (v1); schema supports parallel for v2 |
| Signature | Drawn (signature_pad), stored as PNG base64; no PKI |
| Stamps | Pre-defined text stamps + per-user uploaded image stamps |
| Confidentiality default | Internal |
| Restricted handling | Watermarked PDF download with viewer name + timestamp |
| Ref no | `CIHRMS/DOC/{YYYY}/{0000-padded sequence}` |
| File types accepted | PDF, DOCX, DOC, PNG, JPG/JPEG (≤ 25 MB) |
| Conversion | Image→PDF (Imagick) works; DOCX→PDF returns 501 |
| Recipient picker scope | Any staff member; no department restriction |
| Rejection behaviour | Terminates slip; sender must re-upload or re-open |
| Mobile signing | Yes (signature_pad supports touch) |
| Burned-PDF cache key | sha256 of annotation set; auto-regenerated on change |
| Notifications | `database` + `mail` channels via existing notification stack |

## 14. Testing strategy

- **Pest feature tests** per controller action: index, store, route, act (each transition), annotate, download (original + burned), withdraw, archive
- **Service unit tests** for `DocumentRoutingService::act()` state machine (each transition + invalid transitions)
- **Policy tests** for view/update/route/act gating
- **PDF burn test** with a fixed sample PDF + annotation set, comparing output sha256
- **No JS unit tests for v1** (Vue components rely on visual interaction); manual QA checklist instead

## 15. Migration / rollback

- New tables only; no changes to existing schema.
- Sidebar entry behind `documents.view` permission — invisible to users without the permission until seeded.
- Seeder grants `documents.view` to all authenticated users by default; `documents.manage` only to `Registrar` role.

## 16. Open risks

1. **`tecnickcom/tcpdf` is heavy** (~6 MB composer install). Alternative: skip burn-in v1 and overlay client-side on download via `pdf-lib` (browser). Decision: keep server-side burn for tamper-evidence + watermarking; cache aggressively.
2. **DOCX preview gap** — users uploading DOCX won't see WYSIWYG. Acceptable for v1 (download to view); flagged for v2.
3. **Concurrent annotation by two viewers** — schema permits it (separate rows); UI doesn't yet reconcile. v1 accepts last-write-wins on the same coordinate; no merge UI.

## 17. Acceptance criteria

A user with `documents.create` can:
1. Upload a PDF, add a drawn signature + a text stamp, and save it as draft
2. Route it to two recipients in order
3. Recipient 1 opens the inbox, sees the doc, adds their signature, clicks "Sign & forward"
4. Recipient 2 opens, sees both prior signatures, adds a stamp, clicks "Approve"
5. Sender gets notified, opens the doc, downloads the burned PDF — all three annotations are rendered onto the PDF
6. The timeline shows: uploaded → routed → signed (R1) → forwarded → stamped (R2) → completed
7. Image upload (PNG) followed by "Convert to PDF" produces a downloadable single-page PDF
8. DOCX upload → "Convert to PDF" → 501 with message "Format conversion not yet supported on this server"

## 18. Future work (v2+)

- Parallel routing toggle
- DOCX preview & PDF conversion (LibreOffice headless via separate container)
- Document templates with placeholders
- OCR + full-text search
- Cryptographic e-Signature (PKI / CSCA chain)
- Bulk routing (one doc → many simultaneous slips)
- API endpoints for SSO partners to push documents in
