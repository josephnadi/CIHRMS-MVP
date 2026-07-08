# Design: CIHRM Website ↔ CIHRMS-MVP Finance Integration

**Date:** 2026-07-08 · **Status:** design approved, ready for implementation plan
**Apps:** `cihrm_website` (Laravel 11, live front office) and `cihrms-mvp` (Laravel 13, back office / GL).

## Purpose

CIHRM collects all of its real revenue — membership, student, exam, tuition,
conference, exhibitor, transcript and premium fees — inside `cihrm_website` (via
Hubtel and manual learning-partner entries). The website has **no general ledger**.
The double-entry GL, AR, deferred income and financial statements live in
`cihrms-mvp`, and the *CIHRM 2025 Annual Financial Statements* the finance module
is aligned to are this same institute's. Today the two are disconnected, so the GL
cannot be produced from real data.

This integration wires **verified website collections into the mvp GL** so the
financial statements are produced from actual revenue, while keeping the website
the authoritative system for members and fees.

## Approved decisions (from brainstorming)

- **Member source of truth:** website is authoritative; mvp holds a read-only
  mirror keyed by member number, used only for billing/GL.
- **Integration mode:** nightly batch reconciliation first (non-invasive to the
  live site); real-time webhooks are a later spec.
- **Data access:** a read-only, token-authenticated JSON API on the website that
  mvp pulls. No shared DB, no website schema/behaviour changes.
- **Scope (v1):** all fee surfaces, ingested through **one normalized contract**
  plus a configurable mapping table (not per-surface code).
- **Posting model:** per-payment, cash-basis — `DR Gateway/Cash clearing / CR
  Income`, except member subscriptions → `CR Deferred Income (2400)`, released
  monthly by the existing D straight-line recognition.

## Non-goals (v1)

- Real-time / webhook posting (later spec).
- Refund/void reversal automation — v1 only *flags* status changes on
  already-posted rows for manual review.
- Writing anything back to the website (one-way pull only).
- Merging the two applications or migrating membership data into mvp.
- Non-GHS currency (asserted GHS-only; other rows parked).

---

## Architecture

```
  cihrm_website (SoR: members, fees)                 cihrms-mvp (SoR: GL)
  ┌───────────────────────────────┐                 ┌──────────────────────────────┐
  │ GET /api/finance-sync/members │  nightly pull   │ WebsiteSyncService (scheduled)│
  │ GET /api/finance-sync/collections ├────────────▶│  ├─ upsert Member/Customer     │
  │  (token auth, read-only,       │   (mvp is the   │  ├─ stage external_collections │
  │   normalized, paginated,       │    client)      │  ├─ resolve fee_gl_mappings    │
  │   since=watermark)             │                 │  └─ PostingService.post(JE)    │
  └───────────────────────────────┘                 │       WebsiteCollection source │
                                                     │  Reconciliation dashboard      │
                                                     └──────────────────────────────┘
```

One direction of flow. The website exposes reads; mvp is the client that pulls,
mirrors, and posts. The website's only change is additive read endpoints.

---

## Component 1 — Website read API (additive, on `cihrm_website`)

A new route group `routes/api.php` → `/api/finance-sync/*`, guarded by a
dedicated Sanctum token (a service account for mvp) with a read-only ability.

### `GET /api/finance-sync/members?since=<ISO8601>&cursor=<id>&limit=200`

Returns members/students changed since `since` (by `updated_at`), ordered by id,
`cursor`-paginated. Payload per record:

```json
{
  "member_no": "CIHRM/2021/00456",
  "user_type": "member" | "student",
  "class": "student|associate|full|fellow|chartered",
  "status": "active|lapsed|...",
  "name": "…", "email": "…", "phone": "…",
  "chartered_at": "2024-06-01" | null,
  "lapsed_at": null,
  "updated_at": "2026-07-07T22:14:03Z"
}
```

`member_no` is the stable external key. Students that later become members keep a
single `member_no` where possible; if the website uses distinct student vs member
numbers, both are emitted and mvp links them via the `meta` on collections.

### `GET /api/finance-sync/collections?since=<ISO8601>&cursor=<id>&limit=200`

Returns **only settled** collections across all surfaces, normalized:

```json
{
  "source": "member_fee_payment|student_payment|payment_record|conference|exhibitor|transcript|premium|payment",
  "source_id": 12345,
  "external_ref": "TXN-9F3A…",        // globally unique idempotency key
  "member_no": "CIHRM/2021/00456" | null,
  "payer_name": "…", "payer_email": "…", "payer_phone": "…",
  "fee_code": "member.subscription",  // canonical taxonomy (see mapping)
  "amount": 350.00,
  "currency": "GHS",
  "paid_at": "2026-07-05T10:22:00Z",  // confirmed_at / approved_at / payment_date
  "method": "momo|bank|card|cash",
  "gateway_ref": "hubtel-abc123" | null,
  "meta": { "level_id": 4, "cohort": "…", "course_id": null, "learning_partner_id": 12 }
}
```

**"Settled" definition per surface** (only these rows are emitted):
- `member_fee_payments`, `student_payments`: `status = 'completed' AND payment_verified = true`.
  `external_ref = transaction_reference`. `paid_at = confirmed_at ?? payment_date`.
- `payment_records` (offline learning-partner): `status = 'approved'`.
  `external_ref = "PR-" + id`. `paid_at = approved_at`.
- conference / exhibitor / transcript / premium / generic `payments`: the
  surface's own paid/verified flag; `external_ref` = its unique reference (or
  `"<source>-" + id` if none). Surfaces without a settled flag are excluded and
  listed in the spec's open items rather than guessed.

`fee_code` is derived on the website from `(source, fee_type, user_type)` so the
canonical taxonomy is owned in one place. `combined` fee_types are **split into
their components** where the row records a breakdown (`meta_data`); if it cannot
be split, it is emitted as a single `*.combined` code and mapped to a suspense/
mixed-income account for manual allocation.

### Guarantees

- Read-only. No write endpoints in this namespace.
- Idempotent from the client's view: same `since` returns the same rows;
  `external_ref` is stable per collection.
- Pagination via ascending `id` cursor; `since` filters by `updated_at` so
  status-flips (e.g. a later void) resurface the row for reconciliation.

---

## Component 2 — Fee → GL mapping (`cihrms-mvp`)

### `fee_gl_mappings` table

| column | type | notes |
|---|---|---|
| id | pk | |
| fee_code | string, unique | canonical code from the feed |
| label | string | human name |
| income_gl_account_id | fk gl_accounts | the 41xx/other income account |
| clearing_gl_account_id | fk gl_accounts | cash/gateway clearing debited on receipt |
| is_deferred | bool, default false | subscriptions → true |
| recognition_months | int, null | for deferred (e.g. 12) |
| deferred_gl_account_id | fk gl_accounts, null | defaults to 2400 |
| is_active | bool, default true | |

Seeded from the CIHRM chart of accounts (`CihrmChartOfAccountsSeeder`). This is
the single place accountants configure routing; new `fee_code`s park as
`unmapped` until a row is added. Reuses the D-work fields (`is_deferred`,
`recognition_months`, `deferred_gl_account_id`) so subscriptions flow straight
into the existing recognition engine.

Initial mapping (illustrative — finalized against the real CoA during planning):

| fee_code | income acct | deferred? |
|---|---|---|
| member.subscription | Subscription income | yes (12mo → 2400) |
| member.induction | Induction income | no |
| member.building_levy | Building levy income | no |
| student.subscription | Student subscription income | yes (12mo) |
| student.tuition | Tuition income | no |
| student.exemption | Exemption income | no |
| exam | Examination income | no |
| conference | Conference income | no |
| exhibitor | Conference/exhibition income | no |
| transcript | Transcript / other income | no |
| premium | Premium/other income | no |

---

## Component 3 — Member mirror (`cihrms-mvp`)

`Member` (and its linked AR `Customer`) is **upserted** from the members feed,
keyed by `member_no`. mvp never edits mirrored fields; it is a projection.

- Match on `member_no`; create if absent, update name/email/phone/class/status
  if present. `customer_id` links to an AR `Customer` for statement drill-down.
- Collections whose `member_no` is null or unmatched attach to a generic
  **"Website Collections"** `Customer` so no revenue is lost; the raw payer
  identity is retained on the staging row for later attribution.
- The existing demo `MemberPortalDemoSeeder` data is superseded by real mirror
  data in environments where the sync runs.

---

## Component 4 — Ingestion (`cihrms-mvp`)

### `external_collections` staging table

| column | type | notes |
|---|---|---|
| id | pk | |
| source | string | from feed |
| source_id | unsignedBigInt | website row id |
| external_ref | string | idempotency key |
| member_no | string, null | |
| member_id | fk members, null | resolved mirror |
| fee_code | string | |
| amount | decimal(14,2) | |
| currency | string(3) | |
| paid_at | datetime | |
| method | string, null | |
| gateway_ref | string, null | |
| payload | json | full normalized record |
| status | string | `posted` \| `unmapped` \| `error` \| `flagged` |
| status_note | string, null | why unmapped/error/flagged |
| journal_entry_id | fk, null | the JE it produced |
| created_at / updated_at | | |

Unique index on `(source, external_ref)` — the hard idempotency guarantee.

### `WebsiteSyncService`

`sync(): SyncReport` — the scheduled nightly job:

1. **Members:** pull `/members?since=<members_watermark>`, page through, upsert
   the mirror, advance `members_watermark` to the max `updated_at` processed.
2. **Collections:** pull `/collections?since=<collections_watermark>`, page
   through. For each record, in a transaction:
   - Upsert `external_collections` on `(source, external_ref)`. If it already
     exists with `status=posted`, skip (idempotent).
   - Resolve member (`member_no` → mirror, else generic customer).
   - Resolve `fee_gl_mappings[fee_code]`. Missing → `status=unmapped`, continue.
   - Assert `currency=GHS`. Otherwise → `status=error`, continue.
   - Build the posting document and post via `PostingService` under
     `JournalSourceType::WebsiteCollection`, `source_id = external_collections.id`,
     `purpose = 'collection'` (PostingService is already idempotent on this triple).
   - Save `journal_entry_id`, set `status=posted`.
   - **Status-flip detection:** if a record arrives whose `external_ref` is
     already `posted` but the feed now marks it void/refunded, set `status=flagged`
     + note; do **not** auto-reverse in v1.
3. Advance `collections_watermark` to the max `paid_at`/cursor fully processed.
4. Return a `SyncReport` (counts: pulled, posted, skipped, unmapped, error,
   flagged) for the dashboard and logs.

**Fail-soft:** a single bad row never aborts the batch; it is parked with a
status and surfaced for an accountant. The watermark only advances past rows that
reached a terminal state (`posted`/`unmapped`/`error`/`flagged`), so nothing is
silently dropped.

Watermarks live in a tiny `sync_state` table (or the `settings` store) keyed by
feed name.

### GL posting

Per collection (`date = paid_at`, GHS):

- **Non-deferred:** `DR clearing_gl_account / CR income_gl_account` (amount).
- **Deferred (subscriptions):** `DR clearing_gl_account / CR deferred (2400)`,
  and create the D **revenue-recognition schedule** (`recognition_months`,
  `start_date = paid_at`) so the existing `RevenueRecognitionService` releases it
  monthly `DR 2400 / CR income`.

Cash-basis and per-payment: because members are mirrored, each receipt is
attributable, giving per-member statement drill-down. (A daily-summary variant
was considered and rejected for losing that drill-down.)

### New enum

`JournalSourceType::WebsiteCollection = 'website_collection'` (+ label).

---

## Component 5 — Reconciliation dashboard (`cihrms-mvp`)

A finance page (permission `finance.reports` or similar): for a chosen date
range, per day × `fee_code`:

- **Collected** (Σ feed amount), **Posted** (Σ GL), **Unmapped/Error/Flagged**
  counts and amounts, with drill-down to individual `external_collections` rows
  and their JE.
- A prominent "unresolved" panel (unmapped/error/flagged) that is the accountant's
  worklist — add a mapping, or investigate a flagged void.

This is the deliverable that proves the ledger ties to real collections before
anyone trusts a future real-time path.

---

## Error handling summary

| condition | handling |
|---|---|
| duplicate pull | unique `(source, external_ref)` + PostingService idempotency → no double post |
| unmapped `fee_code` | row parked `unmapped`, shown on dashboard, batch continues |
| missing GL account | parked `error` |
| non-GHS | parked `error` |
| API / network failure | job retries; watermark unmoved past unprocessed rows |
| later void/refund of a posted row | parked `flagged` for manual reversal (v1) |
| null/unmatched `member_no` | attached to generic "Website Collections" customer |

---

## Testing strategy

**Website (contract):**
- `/collections` returns only settled rows (completed+verified / approved / paid).
- Each surface maps to the normalized shape with a stable `external_ref`.
- `since`/`cursor` pagination is complete and ordered; token auth required.

**mvp (ingestion):**
- Given a feed fixture of N collections → N balanced JEs to the mapped accounts.
- Re-running the same feed posts nothing new (idempotent on `(source, external_ref)`).
- Unmapped `fee_code` parks `unmapped`, does not throw, batch completes.
- Subscription collection → CR 2400 + a recognition schedule of `recognition_months` entries.
- Member mirror upsert: new member created; changed fields updated; null member_no → generic customer.
- Watermark advances only past processed rows; a mid-batch error leaves later cursors for the next run.
- Reconciliation totals: dashboard "collected" == feed Σ; "posted" == GL Σ.

---

## Build order (for the implementation plan)

1. **mvp: mapping + staging foundations** — `fee_gl_mappings`, `external_collections`,
   `sync_state`, `JournalSourceType::WebsiteCollection`, seeded mappings. (TDD)
2. **mvp: member mirror upsert** from a stubbed members feed.
3. **mvp: ingestion + GL posting** from a stubbed collections feed (the heart;
   idempotency, fail-soft, deferred → recognition).
4. **mvp: reconciliation dashboard.**
5. **website: read API** (`/finance-sync/members`, `/collections`) + normalization
   + token — built against the contract the mvp tests already pin.
6. **wire the real feed** (config: base URL + token) + a scheduled command
   `php artisan sync:website-collections`; verify one real day reconciles.
7. **P2:** auto-run the D recognition monthly for subscription schedules.

Steps 1–4 are pure mvp and testable with fixtures before the website endpoint
exists, so the two codebases can proceed in parallel against the frozen contract.

## Open items to confirm during planning

- Exact "settled" flag for conference / exhibitor / transcript / premium / generic
  `payments` surfaces (read each model's payment state before emitting).
- Whether students and members share one `member_no` or need a link table in the
  mirror.
- Final `fee_code` → account rows against the real CIHRM chart of accounts.
- Whether `*.combined` payments carry a splittable breakdown in `meta_data`, or
  need a suspense account + manual allocation.
