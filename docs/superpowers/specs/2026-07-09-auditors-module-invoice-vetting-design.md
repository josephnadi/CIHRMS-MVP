# Auditors Module — Spec 1: Invoice Vetting + Auditor Hub

**Date:** 2026-07-09
**Status:** Approved (design)
**Scope:** Incoming-invoice vetting workflow + an Auditor Hub landing page.
Active asset-audit counts/discrepancy flagging and org-wide auditing are explicitly **out of scope** for this spec — they are future specs.

## 1. Background & motivation

The organization wants an Auditors capability where auditors vet incoming purchase
invoices and route them to the CEO for final approval. Any authorized department
should be able to scan, create, or upload an invoice and send it for vetting; once the
CEO approves, the invoice is considered "approved" from the originating department's
perspective. Finance then codes and posts it to the general ledger.

### Existing scaffolding (reused, not rebuilt)

- **`auditor` role already exists** (`app/Enums/UserRole.php`) with read-only oversight
  permissions across Finance, payroll, whistleblower, assets, etc.
  (see `User::ROLE_PERMISSIONS['auditor']`).
- **Assets module already exists** (`Asset`, `AssetAssignment`, `AssetMaintenance`, …).
- **`AuditorGeneralReportController`** already bundles downloadable audit report packs.
- **`AuditLog`** model + `AuditTrail` middleware already provide general audit logging.
- **AP Vendor Invoices** (`VendorInvoice` + `VendorInvoiceService`) already implement a
  `draft → pending_approval → approved` submit/approve flow with dual control, and
  auto-post a GL accrual on creation. This spec's posting step reuses that service.

## 2. Design decisions (from brainstorming)

1. **Separate intake entity** — a new `IncomingInvoice` distinct from the accounting
   `VendorInvoice`. On final approval + Finance coding it *promotes* to a `VendorInvoice`.
2. **Submitters** — dept heads, finance officers, hr_admin, managers, and admins (not
   every employee).
3. **Rejections return for correction** — a rejected/returned submission goes to a
   `returned` state with a required reason; the submitter can edit and resubmit.
4. **GL coding happens after CEO approval** — auditor and CEO approve on the invoice's
   face (vendor name / amount / scan). After CEO approval it enters an "awaiting posting"
   queue where **Finance** supplies the vendor record + GL account coding and posts.
5. **Scope** — invoice vetting workflow **plus** an Auditor Hub landing page that links to
   existing oversight tools (Assets, audit report packs, audit logs).

## 3. Concept & entities

New tables:

- **`incoming_invoices`** — the intake record and its workflow state.
- **`incoming_invoice_attachments`** — one or more uploaded/scanned files per invoice.
- **`incoming_invoice_events`** — append-only trail of every transition (actor, timestamp,
  from→to status, comment). Gives auditors a defensible history.

Attachments follow the existing file-upload/storage pattern used elsewhere in the app.

## 4. State machine

```
draft ──submit──▶ submitted ──vet_accept──▶ vetted ──ceo_approve──▶ approved ──finance_post──▶ posted
  ▲                   │                        │
  │                   │ vet_return             │ ceo_return
  └──────── returned ◀┴────────────────────────┘   (each return carries a required reason)

returned ──(edit)──▶ submit ──▶ submitted        (re-enters vetting)
```

| State | Meaning |
|---|---|
| `draft` | Submitter is still editing (optional; may submit immediately). |
| `submitted` | In the auditor's vetting queue. |
| `vetted` | Auditor accepted; in the CEO's approval queue. |
| `approved` | CEO signed off. **This is the terminal "approved" state the originating department sees.** Enters Finance's "awaiting posting" queue. |
| `posted` | Finance coded vendor + GL accounts and posted; a `VendorInvoice` now exists and is linked via `vendor_invoice_id`. |
| `returned` | Sent back to the submitter with a reason (by auditor or CEO); editable and resubmittable. |

**Rules:**

- All transitions live **only in `IncomingInvoiceService`**, each guarded by a
  `DomainException` thrown on an illegal source state (matches `VendorInvoiceService`).
- **Dual control:** the auditor who vets cannot be the submitter; CEO approval is a
  distinct actor (CEO holds the wildcard permission, so approval is inherently separate
  from vetting).
- Every transition writes an `incoming_invoice_events` row.
- Returns (`vet_return`, `ceo_return`) require a non-empty reason.

## 5. Data model

### `incoming_invoices`

| Column | Type / notes |
|---|---|
| `id` | pk |
| `reference` | unique; generated via `SequenceService::next()` (e.g. `INV-2026-00001`). **Must not** use `count()+1`. |
| `status` | string(30), default `draft`, indexed; backed by `IncomingInvoiceStatus` enum |
| `department_id` | origin department (auto-derived from submitter) |
| `vendor_name` | free-text at intake (no Vendor FK yet) |
| `vendor_invoice_no` | nullable |
| `invoice_date` | date |
| `currency` | default `GHS` |
| `amount` | face total (single figure; line-level coding happens at Finance posting) |
| `description` / `purpose` | what the purchase was for |
| `submitted_by`, `submitted_at` | nullable until submitted |
| `vetted_by`, `vetted_at`, `vetting_notes` | auditor |
| `approved_by`, `approved_at` | CEO |
| `returned_by`, `returned_at`, `return_reason` | nullable |
| `posted_by`, `posted_at` | nullable |
| `vendor_invoice_id` | FK → `vendor_invoices`, nullable, `restrict` on delete |
| `created_by` | fk users |
| timestamps, softDeletes | |

### `incoming_invoice_attachments`

`id`, `incoming_invoice_id` (fk, cascade), `path`, `original_name`, `mime`, `size`,
`uploaded_by`, timestamps.

### `incoming_invoice_events`

`id`, `incoming_invoice_id` (fk, cascade), `actor_id`, `action`, `from_status`,
`to_status`, `comment` (nullable), `created_at`.

## 6. RBAC

New permission slugs — added to the `Permission` enum, `RolePermissionSeeder`
(`PERMISSIONS` + `ROLE_PERMS` + labels), **and** mirrored in `User::ROLE_PERMISSIONS`
(all three kept in lock-step):

| Slug | Held by |
|---|---|
| `incoming_invoices.submit` | dept_head, finance_officer, hr_admin, manager, super_admin, ceo |
| `incoming_invoices.view` | above + **auditor** |
| `incoming_invoices.vet` | **auditor**, super_admin |
| `incoming_invoices.approve` | **ceo**, super_admin (CEO already wildcard) |
| `incoming_invoices.post` | finance_officer, super_admin |
| `auditor.hub` | auditor, super_admin, ceo |

`super_admin` and `ceo` retain their wildcard (`null` / `['*']`) mappings; the explicit
rows above document intent for non-wildcard roles.

Routes registered in `routes/web.php` as per-action `permission:` middleware groups,
following the AP-invoice route pattern:

```
permission:incoming_invoices.view    → index, show
permission:incoming_invoices.submit  → create, store, update, submit
permission:incoming_invoices.vet     → vetAccept, vetReturn
permission:incoming_invoices.approve → ceoApprove, ceoReturn
permission:incoming_invoices.post    → post
permission:auditor.hub               → hub
```

## 7. Backend layers (Enum → FormRequest → Service → Event → Resource → Controller)

- **Enum:** `App\Enums\IncomingInvoiceStatus` (string-backed, with `label()`).
- **FormRequests:** `StoreIncomingInvoiceRequest`, `UpdateIncomingInvoiceRequest`,
  `VetIncomingInvoiceRequest`, `ReturnIncomingInvoiceRequest`, `PostIncomingInvoiceRequest`
  (each `authorize()` calls `hasPermission`, `rules()` validates its payload; return
  requests require a non-empty reason).
- **Service:** `App\Services\Finance\IncomingInvoiceService` with methods:
  `create`, `update`, `submit`, `vetAccept`, `vetReturn`, `ceoApprove`, `ceoReturn`,
  `post`. Each transition guards its source state, records an event, and dispatches its
  event. `post()` accepts the Finance-supplied `vendor_id` + line coding, calls the
  existing `VendorInvoiceService::create()` (which posts the GL accrual), sets
  `vendor_invoice_id`, and moves the intake to `posted`.
- **Events:** `IncomingInvoiceSubmitted`, `IncomingInvoiceVetted`,
  `IncomingInvoiceApproved`, `IncomingInvoiceReturned`, `IncomingInvoicePosted`.
- **Resources:** `IncomingInvoiceResource` (+ attachment and event resources).
- **Controller:** `IncomingInvoiceController` — thin; injects the service, returns
  `Inertia::render(...)` with an `activeModule` key.

**Promotion note:** the `VendorInvoice` produced by `post()` is created through the
existing `VendorInvoiceService::create()` (starts `draft`, auto-posts accrual) and
thereafter follows Finance's own normal invoice lifecycle. The intake's `approved` state
is the departmental sign-off; Finance posting is the downstream accounting step.

## 8. UI

- **Auditor Hub** — `resources/js/Pages/Auditor/Hub.vue`, route `/auditor`
  (`permission:auditor.hub`). Shows vetting-queue stat cards (pending vetting / pending
  CEO / returned) and link-out cards to existing oversight: Assets (`assets.view`), Audit
  report packs, Audit logs. A new expandable **"Auditor"** nav group is added to
  `resources/js/Layouts/AuthenticatedLayout.vue` with `visible` gated on the new
  permissions.
- **Incoming Invoices pages** under `resources/js/Pages/Auditor/IncomingInvoices/`:
  - `Index.vue` — filterable list (by status / department).
  - `Create.vue` — scan/upload + face fields.
  - `Show.vue` — details, attachments, event timeline, and the action buttons the current
    user is permitted (Vet accept/return, CEO approve/return, Finance post). Submitter,
    auditor, CEO, and Finance all use this same page; buttons render conditionally on
    permission + current status.

## 9. Testing (Pest)

Feature tests (per project test patterns — per-user `permissions` JSON column for grants):

- Full happy path: submit → vet → CEO approve → Finance post creates a `VendorInvoice`
  with a balanced GL accrual and links `vendor_invoice_id`.
- Each return path (`vet_return`, `ceo_return`) sets `returned` + reason; resubmit
  re-enters `submitted`.
- Dual-control: auditor cannot vet an invoice they submitted (`DomainException`).
- Permission gating per action (403 without the slug).
- Illegal transitions throw `DomainException` (e.g. approving a `submitted` invoice,
  posting an unapproved one).
- Every transition writes an `incoming_invoice_events` row.

## 10. Out of scope (future specs)

- Active asset-audit counts and discrepancy flagging against the Assets module.
- Organization-wide ("audit the entire internal organization") oversight portal beyond
  the hub's link-outs to existing tools.
- Multi-line intake / line-level coding by the submitter (coding is Finance's job at
  posting in this spec).
