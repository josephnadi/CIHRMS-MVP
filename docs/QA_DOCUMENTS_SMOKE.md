# Documents Module — Manual Browser Smoke Test

> **Companion to:** `tests/Feature/Documents/EndToEndFlowTest.php`
> **Why a manual run is still required:** the automated test covers every controller action and state transition, but cannot verify visual rendering of the PDF viewer, the signature_pad canvas, stamp placement on a real PDF page, or the look of the burned-PDF watermark. Walk this checklist once after any change to the Documents UI.
> **Estimated time:** 10–15 minutes for the full run.

---

## Prerequisites

1. App running locally (`php artisan serve` or your usual dev server) on `http://127.0.0.1:8000`.
2. Vite dev server or built assets available: `npm run dev` for hot reload, or `npm run build` for the production bundle.
3. Three logged-in browser sessions (use 3 different browser profiles, or Chrome's profile picker). You'll switch between them.
4. A small **real** PDF on disk (anything 1–5 pages). The Editorial Sovereign rendering tests in CI use a TCPDF-generated 1-page fixture; for the manual run any real PDF works.
5. Three test accounts seeded:
   - **Owner** — any user with `documents.create` permission. Default `hr_admin` role works.
   - **Recipient A** (Registrar) — any user with `documents.view`.
   - **Recipient B** (DHR) — any user with `documents.view`.

Seed quickly if needed:
```bash
php artisan tinker --execute="
\$owner = \App\Models\User::firstOrCreate(['staff_id'=>'GH-HR-001'],['name'=>'Owner','email'=>'owner@local','password'=>bcrypt('password'),'role'=>'hr_admin','permissions'=>['documents.create']]);
\$a     = \App\Models\User::firstOrCreate(['staff_id'=>'GH-HR-002'],['name'=>'Registrar','email'=>'registrar@local','password'=>bcrypt('password'),'role'=>'manager','permissions'=>['documents.view']]);
\$b     = \App\Models\User::firstOrCreate(['staff_id'=>'GH-HR-003'],['name'=>'DHR','email'=>'dhr@local','password'=>bcrypt('password'),'role'=>'manager','permissions'=>['documents.view']]);
dump(\$owner->id, \$a->id, \$b->id);
"
```

---

## 1 · Owner uploads a PDF

**As:** Owner

- [ ] Click **Documents** in the sidebar → the **Document Register** page loads with empty `All` tab and no console errors.
- [ ] Click **Upload Document** → the slide panel opens.
- [ ] Fill: `Title = Smoke memo`, `Description = end-to-end smoke`, `Confidentiality = Internal`, file = your test PDF.
- [ ] Click **Upload** → redirects to the document Show page.
- [ ] **Verify on Show:**
  - Eyebrow shows `INTERNAL · DRAFT`.
  - Header has the title, ref number in the format `CIHRMS/DOC/YYYY/NNNN`, and your name as owner.
  - PDF viewer renders the first page (pdf.js working).
  - Right rail shows "Routing slip — Not routed yet." and Timeline shows `uploaded` event.
- [ ] Browser **DevTools → Application → Service Workers**: confirm the active SW is `cihrms-v3` (not v2). If not, hard-refresh once.

---

## 2 · Owner places a signature + stamp

**As:** Owner (still on Show page)

- [ ] Click **Add signature** → modal opens with a blank canvas.
- [ ] Draw a signature with mouse/touch → click **Save**.
- [ ] Click anywhere on the rendered PDF page → the signature image appears at that spot inside the page, scaled to ~22 × 8 % of the page.
- [ ] Click **Add stamp** → modal opens with preset stamps (APPROVED / RECEIVED / FOR ACTION / CONFIDENTIAL / COPY) + a custom field.
- [ ] Click **APPROVED** preset.
- [ ] Click another spot on the page → green-bordered `APPROVED` stamp appears.
- [ ] Refresh the page (F5) → both annotations are still there (persisted to DB).
- [ ] Timeline now has `signed` and `stamped` events.

---

## 3 · Owner routes to two recipients

**As:** Owner

- [ ] Click **Route** in the header → modal opens with one empty recipient row.
- [ ] In recipient 1's typeahead, type "Reg" → dropdown shows Registrar (Kwame Owusu in the seeded data) within ~300 ms.
- [ ] Click Registrar → typeahead chip shows the name + staff_id; underneath: `user #<id>`.
- [ ] Set action to **Sign**.
- [ ] Click **+ Add recipient** → second row appears.
- [ ] In recipient 2's typeahead, search "DHR" or "Esi" → pick DHR.
- [ ] Set action to **Approve**.
- [ ] Click **Send** → modal closes; routing slip rail on the right now shows **2 steps**:
  - Step 1 (Registrar · Sign) → amber `in progress`
  - Step 2 (DHR · Approve) → grey `pending`
- [ ] Header eyebrow now reads `INTERNAL · IN REVIEW`.
- [ ] Timeline gains a `routed` event.

---

## 4 · Recipient A (Registrar) acts

**As:** Recipient A (switch browser profile, log in as Registrar)

- [ ] Click **Documents** in the sidebar → notice the **Inbox** tab has a red badge `1`.
- [ ] Click **Inbox** → the Smoke memo is listed.
- [ ] Click **Open** → Show page loads, with an amber "Awaiting your action" card in the right rail.
- [ ] The viewer shows the owner's signature + APPROVED stamp from the prior steps.
- [ ] Add another signature in a different spot (so you can later confirm Registrar's signature on the burned PDF).
- [ ] Type a comment: "Approved at Registrar's level, forwarding to DHR."
- [ ] Click **Sign & forward** → page reloads.
- [ ] Routing slip now shows step 1 `completed` (green) and step 2 `in progress` (amber).
- [ ] Header still says `IN REVIEW` (because step 2 is not yet done).

---

## 5 · Recipient B (DHR) acts

**As:** Recipient B

- [ ] Inbox badge shows `1`.
- [ ] Open the document → both prior signatures + the stamp are visible.
- [ ] Optional: place your own stamp.
- [ ] Click **Sign & forward** (button label flips to **Approve & close** if there's no next hop; either label is fine).
- [ ] Routing slip → both steps `completed`; header eyebrow flips to `INTERNAL · COMPLETED`.
- [ ] Timeline has the full sequence: `uploaded` → `signed` (owner) → `stamped` (owner) → `routed` → `signed` (Registrar) → `forwarded` → `signed` (DHR if you stamped) → `completed`.

---

## 6 · Owner downloads the burned PDF

**As:** Owner

- [ ] On the Show page, click **Burned PDF** in the header → a new tab opens and downloads `<ref_no>-burned.pdf`.
- [ ] Open the file → every annotation (your signature + APPROVED stamp + Registrar's signature + DHR's stamp if added) is flattened into the PDF at the right page coordinates.
- [ ] **Visual check:** the annotations don't overlap the original PDF content destructively. Stamps are crisp; signatures preserve aspect ratio.
- [ ] In DevTools → Network → click the download request → response headers include `Content-Disposition: attachment; filename="CIHRMS/DOC/YYYY/NNNN-burned.pdf"`.
- [ ] In DevTools → Network: confirm the download URL includes `?signature=...&expires=...` (signed-URL gate working).

---

## 7 · Signed-URL expiry behaviour

**As:** Owner

- [ ] Copy the burned-download URL from the previous step.
- [ ] Wait 6 minutes (or use the DevTools clock-skew trick, or use this from tinker to mint an already-expired URL):
  ```php
  \URL::temporarySignedRoute('documents.download', now()->subMinutes(5), ['document'=>'<uuid>'])
  ```
- [ ] Paste the expired URL into a new tab → app returns **403 Forbidden**. (Laravel's `signed` middleware default page; CIHRMS doesn't override it.)

---

## 8 · Restricted-watermark policy

**As:** Owner

- [ ] Change the document's confidentiality to **Restricted**. (No UI for this yet — set it directly: `\App\Models\Document::where('uuid','<uuid>')->update(['confidentiality'=>'restricted'])`.)
- [ ] Refresh the Show page → eyebrow now says `RESTRICTED · COMPLETED`.
- [ ] Click **Original** in the header → because the doc is restricted, the **same** burned+watermarked file is served regardless of which button is clicked (filename: `<ref_no>-restricted.pdf`). The "raw original" is no longer accessible.
- [ ] Open the downloaded PDF.
- [ ] **Visual check:** every page has a diagonal (≈ −30°) semi-transparent rose-coloured stamp reading:
  ```
  RESTRICTED · <Your Name> · <YYYY-MM-DD HH:mm>
  ```
- [ ] Re-download as another user — the watermark text changes to that user's name + the new timestamp (proves it's per-viewer, not cached).
- [ ] DevTools → Network → the download request shows the signed-URL params; the response is fresh (no `cf-cached`, `age`, or similar SW-cache header on first visit).

---

## 9 · Service-worker behaviour

**As:** Any user

- [ ] DevTools → Application → Service Workers: confirm `cihrms-v3` is `activated and running`.
- [ ] Application → Cache Storage: `cihrms-v3-assets` + `cihrms-v3-runtime` + `cihrms-v3-shell` exist. **No** `cihrms-v2-*` entries.
- [ ] Click **Documents** in the sidebar → URL changes AND the page content swaps. Repeat a few times across different modules → no stale-content symptoms.
- [ ] In Network: when navigating, the Inertia AJAX request has `X-Inertia: true` and goes straight to the network (not intercepted by the SW). The SW's `staleWhileRevalidate` does NOT cache the response (verify by clearing Cache Storage and confirming nothing under `cihrms-v3-runtime` matches the `/leave` or `/documents` URLs).

---

## 10 · Reject flow

**As:** Owner

- [ ] Upload a fresh PDF, route to a single recipient.
- [ ] As that recipient, click **Reject**, enter a reason, submit.
- [ ] Routing slip step 1 → red `rejected`; document eyebrow → `INTERNAL · REJECTED`.
- [ ] Timeline gains a `rejected` event with the comment in the payload.
- [ ] Try to act on the route again → the recipient card is gone (no awaiting action).
- [ ] Owner cannot **Route** again until they either re-upload a new version or use the **Withdraw** path (not applicable here since status is already terminal).

---

## 11 · Withdraw flow

**As:** Owner

- [ ] Upload a fresh PDF, route to a single recipient.
- [ ] As owner, **before** the recipient acts, click **Withdraw** in the header.
- [ ] Confirm the JS confirm dialog.
- [ ] Document eyebrow → `INTERNAL · WITHDRAWN`; routing slip step 1 → grey `cancelled`.
- [ ] As the recipient, open the Inbox → the badge has decremented; the doc is no longer in the active inbox.

---

## 12 · Conversion stub

**As:** Owner

- [ ] Upload a `.docx` file (Word document).
- [ ] On the Show page, the viewer shows the "Preview not available for this format — Use Download to view." placeholder.
- [ ] Try `POST /documents/<uuid>/convert?to=pdf` via DevTools Console or Postman → response is `501` with the message `Conversion from application/vnd.openxmlformats-... to pdf is not supported on this server.` (DOCX→PDF intentionally not implemented in v1.)
- [ ] Upload a `.png` instead → `convert?to=pdf` returns the PNG wrapped in a single-page A4 PDF (200 OK).

---

## 13 · Audit log captures everything

**As:** Auditor (any user with `audit.view`)

- [ ] Visit **Audit Logs** in the sidebar.
- [ ] Filter by user / by route name / by action → every Documents action you performed in steps 1–11 is in the chain with `route_name = documents.*`.
- [ ] The chain's `chain_position` is monotonically increasing; the `row_hash` matches the previous row's hash (the audit log's tamper-evident integrity holds).

---

## Pass criteria

This smoke walk passes if **every checkbox above ticks green** in one continuous run. If any step fails:
1. Open DevTools → Console: any red errors? Note them.
2. Open DevTools → Network: any 4xx / 5xx? Note the response body.
3. Open the Documents Show page's right-rail Timeline: any unexpected gap between events?
4. Re-run `php artisan test tests/Feature/Documents` to confirm the regression isn't already caught at the HTTP layer.
5. File against the rev that introduced the regression.

---

## Known visual gaps to file against future revs

These are intentional v1 limitations, not bugs:

| Gap | Why deferred |
| :--- | :--- |
| DOCX preview is a placeholder | Needs OnlyOffice / CKEditor — out of MVP scope |
| Restricted-doc UI toggle | No screen to change confidentiality post-upload yet — only via DB |
| Recipient typeahead doesn't show department or role chips | Single-line label is sufficient for v1; richer cards next rev |
| Burned-PDF watermark colour is fixed rose for restricted, slate otherwise | Tone selector would be over-engineering until a second classification is added |
| No bulk-route UI (one doc → many parallel signers) | Schema supports `parallel_routing` boolean; UI is v2 |
