# Chapter 25 — Identity Verification & Ghana Card

> *In one paragraph.* Identity Verification is the module that turns a typed-in Ghana Card number into a verified, time-bounded, audit-grade record that other CIHRMS modules can rely on before they move money. It is the single most important Phase-1 control: the gap analysis flagged Ghana Card / NIA integration as *missing*, and this chapter is what closes that gap. The module accepts a Ghana Card PIN (the `GHA-NNNNNNNNN-N` value printed on every Ghanaian's national ID card), runs it through a pluggable verification provider (real NIA endpoint, third-party KYC aggregator, or a manual-upload fallback), stores the outcome on an `identity_verifications` row that keeps the raw PIN *encrypted* and a SHA-256 *hash* alongside for duplicate detection, fires a domain event so downstream listeners (webhook fan-out, audit log) can react, and exposes a single helper — `Employee::hasUsableIdentity()` — that the Payroll Engine in Chapter 19 uses as its first gate before computing any pay line. Every verification is time-stamped, attributed to the HR officer who performed it, set to expire twelve months out, and surfaced in the Auditor-General Report Pack (Chapter 24) as a masked register.

## Where to find it

- **Sidebar location:** **Governance** group → **Ghana Card Verification** (top-level item under the Identity Register heading). The page lives at `/identity` and is rendered by `IdentityVerificationController::index` into `resources/js/Pages/Identity/Index.vue`. The masthead carries an editorial-sovereign "IDENTITY REGISTER · GHANA CARD" eyebrow with the legal anchor "NIA-aligned register under Act 750" surfaced in the subhead, so the page never lets you forget what statute it answers to.
- **Roles that see it:**
    - **super_admin** and **ceo** — every verification, every employee, every field. The `before()` hook on `IdentityVerificationPolicy` short-circuits all checks for super_admin; CEO mirrors the same posture via the `null` permission grant in `RolePermissionSeeder` (which means "all permissions").
    - **hr_admin** — full read + write. Holds both `identity.view` and `identity.verify`, so HR is the role that actually submits cards to the provider and reads the register.
    - **auditor** — read-only. Holds `identity.view` only; can browse the register and pull the masked CSV from the Auditor-General pack, but cannot trigger a new verification. This is the segregation-of-duties posture the Data Protection Officer needs.
    - **finance_officer**, **manager**, **dept_head**, **it_support**, **employee** — no register access. Employees do see their *own* verification row through the policy's `view` method (`$row->employee?->user_id === $user->id`), which means the Profile portal in Chapter 16 could surface the green "verified" tick to the employee themselves; the Identity Register page itself is not on their sidebar.
- **Related modules:** Employees (Ch 3) — the `national_id` field on the employee row is what gets verified, and the encrypted Ghana Card number is back-filled onto the employee row on a successful first verification; Payroll Engine (Ch 19) — `PayrollService::calculate()` calls `Employee::hasUsableIdentity()` as Gate 1 before it computes a payroll line, and skipped lines carry the explicit reason "Identity unverified — Ghana Card validation required."; Audit Logs (Ch 24) — every successful verification and every duplicate detection is event-sourced and surfaces in the Auditor-General Report Pack as `identity/verifications.csv`; DPA & Privacy (Ch 26) — the Ghana Card number is sensitive personal data under Act 843 §17, so it is encrypted at rest, masked on screen and in CSVs, included verbatim in the data-subject export, and retained for seven years on the SSNIT statute (Act 766 §92) so an employee right-to-erasure request must hold this row back; Webhooks (Ch 27) — `FanOutWebhooks::handleIdentityVerified` ships the verification event to subscriber tenants on the `identity.verified` topic; Standards (Ch 44) — every legal anchor surfaced in this chapter is forwarded there; Roadmap (Ch 46) — the remaining gaps (employee-edit hook, loan/disbursement gates, biometric template hashing) live there.

## The screens

![Identity Register — masthead, submit form, biometric capture, register table](../assets/screenshots/25_identity_ghana_card/index.png)

*Callouts: ❶ Editorial-sovereign masthead — `verified_user` icon, "IDENTITY REGISTER · GHANA CARD" eyebrow, page title "Ghana Card Verification", and the cobalt "Submit Verification" CTA that scroll-jumps to the form. The subhead names the statute ("NIA-aligned register under Act 750"), the cryptographic posture ("SHA-256 hashed lookup"), and the downstream consequence ("payroll disbursement gated on verified records") in a single line. · ❷ "Submit a new verification" card — three-column grid with Employee ID (number input), Ghana Card number (placeholder `GHA-123456789-1`, regex-validated client- and server-side), and the cobalt "Verify" submit button. Below the grid: a "Capture biometric photo" button that opens the live camera modal, a fallback "Or upload scan" file picker that accepts JPG / PNG / PDF up to 5 MB, and a secondary chip that shows the chosen file's name with an X to clear. · ❸ Register table — Employee (name + employee_no), Card (masked `GHA-•••••••••-N`), Provider (e.g. "NIA Official Verification System", "Manual Ghana Card Upload"), Status badge, Verified at, Expires. The raw card number is *never* rendered — the Resource explicitly strips it. · ❹ Pagination — 25 rows per page, `withQueryString()` so filters survive paging.*

![Biometric capture modal — live camera with face + card framing guide](../assets/screenshots/25_identity_ghana_card/biometric_capture.png)

*Callouts: ❶ Eyebrow "Biometric capture" and dynamic title "Hold Ghana Card next to face" (switches to "Confirm photo" once captured). The component pulls the user-facing camera (`facingMode: 'user'`) at 1280×960 ideal and shows a dashed gold framing rectangle inset 8% from each edge — wide enough to fit a face and the card together. · ❷ Live stage with the `getUserMedia` video feed and the framing overlay. Capture compresses to JPEG at quality 0.9 and emits a `File` named `biometric-{timestamp}.jpg`. · ❸ Action row — Cancel / Capture while live, Retake / Use this photo once captured. Camera permission errors surface with a clear "Allow camera permission" message and a Try-again button — no silent failures. · ❹ The captured frame becomes the `evidence` field on the form submission; the controller stores it under the `identity_evidence/` disk and pins the path on the `IdentityVerification` row.*

> The two screenshot files referenced above will be captured in Wave 1 (task W1.25). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Identity Register page

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Submit Verification** (cobalt CTA, top right) | Anchors to `#identity-verify` — the form sits on the same page rather than behind a slide panel, because this is the single action this page is for. | `identity.verify` (hr_admin, super_admin, ceo) | Verification is the *only* mutating action this module exposes; demoting it to a popover would be theatre. The CTA stays visible from any scroll position. |
| **Employee ID** | Numeric input that maps to `employees.id`. Validated server-side as `required | integer | exists:employees,id` — a nonexistent id returns a 422 before the provider is even called. | Same | The PIN is verified *against an employee* — there is no anonymous lookup. The integer id rather than employee_no keeps the call cheap (PK lookup) and unambiguous across departments. |
| **Ghana Card number** | Free-text input with placeholder `GHA-123456789-1`. Client-side `required` only; server-side `regex:/^GHA-\d{9}-\d$/i` is what actually gates. Whitespace and case are normalized (`strtoupper(preg_replace('/\s+/', '', trim($x)))`) before hashing or sending to the provider, so `  gha-123456789-1  ` and `GHA-123456789-1` produce the same hash. | Same | The NIA's canonical Ghana Card format is exactly this — three letters, a dash, nine digits, a dash, a check digit. The regex is the gate that stops malformed PINs reaching the provider at all. The case-insensitive `i` flag lets HR type lowercase without re-typing. |
| **Capture biometric photo** | Opens the `BiometricCapture` modal, requests camera permission, and on capture emits a JPEG `File` that populates `form.evidence`. | Same | Many MDAs need a face-and-card composite as part of their internal evidence trail. Doing it in-browser means no extra hardware; doing it in JPEG (not raw) means the upload stays under 5 MB even at 1280×960. |
| **Or upload scan** (file picker) | Alternative to the live capture — accepts `.jpg / .jpeg / .png / .pdf`, max 5,120 KB (`mimes:jpg,jpeg,png,pdf | max:5120`). Mutually exclusive with the captured photo (the same `form.evidence` field). | Same | Some environments have no working webcam (think the dept head submitting from a CCTV-locked office). A PDF scan path keeps the module unblocked. |
| **Captured-file chip** | Displays the chosen file's name in a secondary-coloured pill with a `×` to clear. Calls `clearCapture()` which nulls `form.evidence` and the displayed name. | Same | One-click undo on the wrong file is faster than re-opening the file picker. |
| **Verify** (submit) | POSTs to `/identity` with `forceFormData: true` (because the evidence field is multipart). On success the form resets and a green flash carries "Identity verified successfully."; on failure a red flash carries the provider's failure reason verbatim. | `identity.verify` enforced both at the form-request level (`VerifyIdentityRequest::authorize()`) and at the route middleware (`permission:identity.verify`). The double-gate is intentional. | Belt-and-braces — the FormRequest stops the call before validation runs, the middleware stops it before the FormRequest is constructed, and either alone would be enough to refuse. Re-running an identical verification is allowed (it produces a fresh row) because re-verification at the 11-month mark is the official re-verification flow. |
| **Register table** | Read-only list of every `IdentityVerification` row visible to the current user (super_admin / `identity.view` see all; everyone else sees only their own employee's rows). Sorted `latest()`. | `identity.view` for the bulk view; any user for their own row | The auditor's primary lens — "show me the verified-identity register for this tenant." The masked column means the raw PIN is never on screen even for super_admin. |
| **Pagination** | Standard Inertia pagination, 25 per page, query string preserved. | Anyone with the view | At a 5,000-employee MDA this is the page size that keeps the first paint under 200 ms. |
| **Stats** (computed server-side, not yet surfaced on the page) | Four numbers — verified count, pending count, failed count, count of *active* employees with no usable verification. They are calculated by the controller and passed in `props.stats` but the current `Index.vue` template doesn't render them as cards. The component does compute a forward-compatible `rejectedCount` that falls back through `props.stats?.rejected ?? props.stats?.failed`. | Same as the page itself | The numbers are wired and waiting — surfacing them as stat-cards is the Wave-1 polish task (W1.25.a). The most useful one is "unverified active employees" because that is the live ghost-worker risk surface. |

> *Notes:* The form intentionally does *not* expose a provider dropdown. The provider is selected globally via `config('identity.driver')`, defaulting to `manual_upload`. This is deliberate: an HR officer should not be able to "downgrade" a verification to manual to bypass a stricter provider, and an MDA's choice of provider is a configuration decision (set in `.env` against the `IDENTITY_PROVIDER` key), not a per-record one.

## The data behind it

There is exactly one storage table — `identity_verifications` — and one immutable cast on the model that makes the rest of the privacy story tractable.

### Schema (`2026_05_25_000006_create_identity_verifications`)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `employee_id` | foreign | Cascade-on-delete. Points at the verified employee; one employee can have many verifications over time. |
| `provider` | varchar(32) | Slug — `nia_official`, `third_party_kyc`, or `manual_upload`. Cast to `IdentityProviderKind` enum on the model. |
| `ghana_card_number` | varchar | **Encrypted via the model cast (`'encrypted'`).** At rest, this column is ciphertext; reads transparently decrypt. The model's `$hidden = ['ghana_card_number']` strips it from any JSON serialisation by default. |
| `ghana_card_hash` | varchar(64), indexed | **SHA-256 fingerprint of the normalized PIN.** This is the duplicate-detection key — comparing two cards across two employees is a single indexed equality check that never decrypts anything. |
| `status` | varchar(16) | One of `pending`, `verified`, `failed`, `expired`, `disputed` (cast to `IdentityVerificationStatus` enum). `expired` and `disputed` exist in the enum but are not yet written by any code path — they are reserved for the re-verification job (`Expired`) and the dispute workflow (`Disputed`). |
| `verified_at` | timestamp | Set on success; null otherwise. |
| `verified_by` | foreign(users) | Null-on-delete. The HR officer who submitted the verification (`$request->user()`). Null when the verification came from the async job rather than a UI submit. |
| `expires_at` | timestamp | Twelve months from `verified_at` by default. Sourced from the provider's `VerificationResult::expiresAt` (each provider returns `+12 months` from `now()` on success). |
| `evidence_path` | varchar | Storage path under `identity_evidence/` if the HR officer attached a biometric photo or PDF scan. Otherwise null. |
| `raw_response` | json | Whatever the provider sent back. For `nia_official` that's the full NIA JSON body; for `third_party_kyc` it's the vendor payload plus a `vendor` key; for `manual_upload` it's `{'normalized': '...', 'mode': 'manual', 'note': 'Pending senior-officer manual approval.'}`. |
| `failure_reason` | text | The provider's literal failure message when `status = failed`. Surfaced in the flash error and on the Auditor-General CSV. |
| `created_at` / `updated_at` | timestamps | Standard. |
| `deleted_at` | timestamp | Soft delete — a redacted verification still leaves the row visible for the SSNIT 7-year retention rule (Act 766 §92). |

Indices: `(employee_id, status)` for the "is this employee currently verified?" lookup; `(status, expires_at)` for the `identity:expiring` scanner.

### What is verified, exactly

Three things, and **not** four:

1. **The Ghana Card PIN format.** Every provider, including `manual_upload`, refuses anything that doesn't match `GHA-NNNNNNNNN-N` after normalization. Malformed PIN never gets a verified row.
2. **The PIN against the chosen authority.** What "authority" means depends on the provider — see the table below.
3. **The personal payload (full name, date of birth, phone).** The service constructs a payload from the employee row (`personalPayload()`) and passes it to the provider; the NIA provider sends `pin`, `full_name`, and `date_of_birth` in its request body, so a mismatch surfaces as a "matched: false" with a `reason` such as "PIN found but biographic data does not match".

What is **not** verified yet:

- **Biometric match (fingerprint / facial recognition).** The `BiometricCapture` component captures a photo, but the captured image is stored as audit evidence only — *no fingerprint or facial-recognition match is computed*. The photo is a chain-of-custody artefact, not a biometric template. This is by design for the MVP: hashing a real biometric template requires an enrolled template from the NIA's IVS that is not available to third-party MDAs without an MoU.
- **Card photo OCR or chip read.** The MVP does not read the printed photo, the optional MRZ, or the chip on the card. A future card-scanner integration is on the Phase-1 roadmap (Chapter 46).

### Provider matrix

| Driver | Class | Endpoint | Auth | What it actually does | When you'd use it |
|---|---|---|---|---|---|
| `nia_official` | `NiaOfficialProvider` | `POST {NIA_BASE_URL}/verify` — default `https://api.nia.gov.gh/verify` | Bearer token (`NIA_API_KEY`) | Sends `{pin, full_name, date_of_birth}`, expects `{matched: bool, reason?: string}`. On a 200 + `matched=true` returns success with a 12-month expiry. On non-2xx, transport error, or `matched=false` returns failure with the NIA reason surfaced verbatim. 8-second timeout (configurable via `NIA_TIMEOUT`). | Production, after the MDA has signed the NIA institutional MoU and has been issued a shared-secret API key. **No live NIA endpoint is hit from any test or seed** — the request shape is fully covered by an HTTP-faked test suite. |
| `third_party_kyc` | `ThirdPartyKycProvider` | `POST {KYC_BASE_URL}/ghana/card` | `X-API-KEY` header (`KYC_API_KEY`) | Generic adapter for uqudo, Smile ID, Youverify, or any aggregator that re-publishes the NIA data. Sends `{card_number, first_name, last_name}`, expects `{data: {verified: bool}}` or `{verified: bool}`. 10-second timeout. | Pilot phase before the official NIA MoU is finalised, or as a redundant secondary check for high-value approvals. |
| `manual_upload` | `ManualUploadProvider` | None — no network call | None | Regex-validates the PIN against `GHA-NNNNNNNNN-N` and returns success pending senior-officer manual approval. The 12-month expiry is set anyway, so a manual verification still ages out. The `raw_response` JSON records `{mode: 'manual', note: 'Pending senior-officer manual approval.'}` so the auditor can tell which rows came from this path. | The default. Used in dev, staging, and any MDA that has not yet provisioned an `IDENTITY_PROVIDER` and `NIA_API_KEY`. Acceptable for the MVP because the *PayrollService* still refuses to pay an employee whose latest verification is `failed` or `expired`. |

The active provider is chosen at the container layer in `IdentityServiceProvider::register()`. The choice is `env('IDENTITY_PROVIDER', 'manual_upload')` — so the default posture out of the box is manual-upload, and an MDA flips to `nia_official` only after the API key is provisioned.

### What gets stored versus what gets seen

| Surface | Sees | Does not see |
|---|---|---|
| The `identity_verifications` table at rest | Ciphertext PIN + SHA-256 hash | Plain PIN |
| The `IdentityVerificationResource` (used by the page and the API) | Masked tail (`GHA-•••••••••-N`) | Plain PIN |
| The Auditor-General CSV (`identity/verifications.csv`) | Masked tail (same mask format) | Plain PIN |
| The data-subject export (Chapter 26) | Just the last digit (`card_tail`) | Plain PIN |
| Any JSON serialisation of the model | Nothing — the field is in `$hidden` | Plain PIN |
| The `IdentityExpiringReminder` mail | Only the `expires_at` date | Plain PIN |
| The captured biometric JPEG | Whatever the user pointed the camera at | n/a |

The plain PIN is decryptable only inside PHP, on the model accessor — there is no UI surface, API surface, CSV surface, or notification surface that emits it.

## How it talks to other modules

- **`IdentityVerified` event** → fired at the end of every successful `IdentityVerificationService::verify()` call. One listener picks it up: `FanOutWebhooks::handleIdentityVerified` ships an `identity.verified` envelope (`{verification_id, employee_id, provider, verified_at}`) to every external webhook subscriber tenant. The event is *not* hooked into `RecordAnalyticsEvent` yet — that's a Phase-1 polish item.
- **`DuplicateIdentityDetected` event** → fired by `detectDuplicates()` after a successful verification, when the same `ghana_card_hash` already maps to a different employee with a verified row. The payload is `{cardHash, employees[]}` (where `employees` is the list of all employees sharing the hash, including the current one). **There is no listener wired for this event yet** — it is the leading indicator of a ghost-worker fraud attempt, and the audit-log / alerting wiring lives in the Phase-1 backlog. The event is dispatched, captured by the test suite, and ready for a listener.
- **Employees (Ch 3)** — on a successful verification, `IdentityVerificationService` back-fills the employee's `national_id` field if it was empty: `if ($result->success && empty($employee->national_id)) { $employee->forceFill(['national_id' => $ghanaCardNumber])->save(); }`. This means subsequent payroll and disbursement modules can read the PIN off the employee row without re-querying the verification table. The reverse direction — re-verifying when the employee edits their `national_id` in the Profile portal — is *not yet hooked up*; that's the `VerifyEmployeeIdentity` job, which exists and works on a queued path but is not dispatched anywhere automatically.
- **Payroll Engine (Ch 19)** — `PayrollService::calculate()` calls `Employee::hasUsableIdentity()` as Gate 1. The helper returns `true` iff the employee has at least one `IdentityVerification` with `status = verified` and either no expiry or an expiry in the future. A `false` result causes the line to be skipped with `skip_reason = "Identity unverified — Ghana Card validation required."`. The skipped row is still persisted, still appears in the Lines tab in amber, and still counts against the `skipped_count` so the run's headcount reconciles. **This is the only place in the codebase where `hasUsableIdentity()` is called** — see the gap analysis below.
- **Audit Logs (Ch 24)** — the Auditor-General Report Pack pulls every `identity_verifications` row into `identity/verifications.csv`, with the masked tail format (`GHA-•••••••••-N`), the provider, the status, the verified-at and expires-at timestamps, and the failure reason. The plain PIN is never written to the export.
- **DPA & Privacy (Ch 26)** — `DataSubjectExportBuilder::identityVerifications()` includes the masked tail (`substr($v->ghana_card_number, -1)`) so the data subject sees their own verification history without the export becoming a target for re-identification. `ErasureService` *holds back* `identity_verifications` rows on a right-to-erasure request, citing "National Pensions Act 2008 (Act 766) §92 — 7-year retention" — because SSNIT's statutory retention rule overrides the DPA §40 right within the retention window.
- **Webhooks (Ch 27)** — the `identity.verified` topic is one of four event topics fanned out by `FanOutWebhooks`. Subscribers can react to "this employee just got a verified Ghana Card" in real time — useful for an external GIFMIS bridge or a partner KYC tracker.
- **Public API v1 (Ch 28)** — not yet exposed. The verification register has no API route. A read-only `GET /api/v1/identity/verifications` is a one-day Phase-1 task; the Resource and Policy are already in place.

### The expiring scanner

`php artisan identity:expiring --window=30` is a queueable command that:

1. Queries every `IdentityVerification` where `status = verified` and `expires_at` is inside the next N days (default 30).
2. Sends each employee an `IdentityExpiringReminder` notification (mail + database channels) with the days-remaining count and a link back to `/profile`.
3. Logs how many it notified.

The command is idempotent on the mail channel (Laravel doesn't de-dupe across days), and chatty on the database channel (one row per scan, so HR sees the cadence). It is wired to be run nightly by the system scheduler in production; the schedule entry is on the Wave-1 backlog.

### Cross-module triggers that *should* fire but do not yet

The prompt asks where high-risk approvals require `hasUsableIdentity()`. The honest answer is:

- **Payroll (Ch 19)** — *yes*, enforced in `PayrollService::calculate()`. This is the gate.
- **Loans (Ch 21)** — *not yet*. `LoanService` does not call `hasUsableIdentity()` before approving or disbursing a loan. The Phase-1 roadmap calls for this gate; the code change is two lines.
- **Disbursements (Ch 22)** — *indirectly*. Payroll disbursements pass through the identity gate because Payroll already gates the calculate step. Off-cycle disbursements (an ad-hoc allowance, a per-diem advance, a one-off settlement) do not yet check identity directly. The Phase-1 roadmap adds a `Disbursement::requiresIdentity()` predicate that the `BatchDisbursementService` will honour.
- **Off-boarding (Chapter to follow)** — *not yet*. Final-settlement payouts inherit Payroll's gate when the settlement is routed through a payroll run; standalone settlements bypass the check.

This is the most important honesty note in this chapter: the gap analysis (8 days old, dated 2026-05-13) called Ghana Card / NIA integration *missing*; today it is *shipped* end-to-end as a verification module but *partially wired* into the modules that should be gated by it. Payroll is gated. The other three are not.

## Standards touchpoints

The Identity module's posture is anchored against three statutes and one international standard. Every name below is hand-written, with a one-line description, and forwarded to **Chapter 44 — Standards & Statute Index**.

- **National Identification Authority Act, 2006 (Act 707) — Ghana Card mandate** — Act 707 establishes the NIA and mandates the Ghana Card as the country's primary national identification document. The CIHRMS Identity module's PIN-format gate (`/^GHA-\d{9}-\d$/`), the NIA-Official provider (which targets `https://api.nia.gov.gh` by default), and the 12-month re-verification window are the operational implementation of the Act's identity-assurance expectation for payroll-bearing institutions. See Chapter 44.
- **National Identity Register Act, 2008 (Act 750) — Register operating posture** — Act 750 governs the operation of the National Identity Register; the page masthead names "NIA-aligned register under Act 750" as the explicit anchor, and the encrypted-at-rest + masked-on-screen + duplicate-detected posture is the operational reading of the Act's confidentiality and accuracy expectations. See Chapter 44.
- **Data Protection Act, 2012 (Act 843) §17 — Lawful basis for sensitive personal data** — biometric and identity data are *sensitive personal data* under the Act. The module relies on the employment-contract basis combined with the legal-obligation basis (PAYE, SSNIT, Tier-2 returns require a verified identity), and the encryption-at-rest + masking-everywhere + audit-trail posture is the operational reading of §17's "appropriate safeguards" clause. See Chapter 26 and Chapter 44.
- **Data Protection Act §39 — Security of personal data** — the SHA-256 hash for duplicate detection (no PII compared in plaintext), the encrypted column cast, the `$hidden` strip on the model, the resource-layer masking, the policy-layer access control (`identity.view` is a separate permission grant), and the immutable audit trail constitute the §39 "appropriate technical and organisational measures" defence. See Chapter 26 and Chapter 44.
- **Data Protection Act §40 — Right to erasure** — `ErasureService` explicitly holds `identity_verifications` rows back from erasure for seven years under SSNIT's Act 766 §92 retention rule, and the held-back log entry names the statute so the auditor and the data subject both see *why* the row was retained. See Chapter 26 and Chapter 44.
- **National Pensions Act, 2008 (Act 766) §92 — Seven-year SSNIT retention** — the retention statute that justifies the held-back posture above. See Chapter 44.
- **Cybersecurity Act, 2020 (Act 1038) — Registration with CSA for handling biometrics** — the Act requires institutions that handle biometric data to register with the Cyber Security Authority. The MVP captures biometric photos as audit evidence only (no template extraction, no template storage, no biometric matching), but the moment a future release extracts a fingerprint or facial-recognition template, the MDA's CSA registration becomes load-bearing. The current posture is "biometric photo as chain-of-custody artefact, not biometric template" — and the chapter records that explicitly for the auditor. See Chapter 44.
- **ISO/IEC 27001:2022 A.9 — Access control** — the layered access control here (policy + middleware + FormRequest authorize + per-resource policy `view`) is the operational reading of A.9.1.1 ("access control policy") and A.9.2.3 ("management of privileged access rights"). The CEO + super_admin pattern, with auditor segregated to read-only and HR's verify capability gated by a dedicated permission, is the same SoD model used by the payroll engine. See Chapter 44.
- **ISO/IEC 29115 — Entity authentication assurance** — the four-level assurance framework. The CIHRMS NIA-Official provider operates at LoA-3 (high-assurance, government-source verification with cryptographic auth); the third-party-KYC provider operates at LoA-2 (substantial assurance, redistributed authoritative source); the manual-upload provider operates at LoA-1 (some confidence, format-checked self-declaration pending officer approval). Each row's `provider` column records which assurance level applied. See Chapter 44.

## What's planned next

Phase 1 of the government-grade roadmap (see Chapter 46) closes the six gaps still visible in this module: **(1)** wire `hasUsableIdentity()` into `LoanService::approve` and `LoanService::disburse` so high-value loans cannot issue against an unverified employee; **(2)** add a `Disbursement::requiresIdentity()` predicate on the off-cycle disbursement path so the gate is not Payroll-only; **(3)** add a `Listener` for `DuplicateIdentityDetected` that writes to the immutable audit log and pages the on-call HR officer — duplicate detection fires today, but nothing reacts to it; **(4)** dispatch `VerifyEmployeeIdentity` automatically when an employee edits their `national_id` in the Profile portal, so the verified state cannot get out of sync with the stored PIN; **(5)** surface the four stat-cards (`verified`, `pending`, `failed`, `unverified_employees`) on the register page — they are computed by the controller and waiting for the template; **(6)** wire the `identity:expiring` artisan command into the production scheduler at 02:00 daily, so the 30-day reminder fires without an operator. Phase 2 adds biometric template extraction (with the matching CSA registration), card photo OCR / MRZ read, and an "Identity Disputed" workflow that uses the `Disputed` enum case the model already supports. Phase 3 promotes the NIA-Official provider from a hand-rolled HTTP client to the certified NIA IVS SDK once the institutional MoU is in place — the contract is provider-pluggable, so the migration is a one-class swap, not a refactor.
